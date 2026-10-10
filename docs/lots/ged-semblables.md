# Lot ged-semblables — parité Paperless : vrais « documents semblables »

Juge : Claude (poste principal). Agent : DeepCode. Branche : `ged/ged-semblables`.

## Contexte (constat du juge, 10.10)

Le lien « Documents semblables » de la Recherche (`templates/ecrans/recherche.twig`) ouvre seulement la
fiche du document (`/bibliotheque?doc=<id>`) : ce n'est pas une recherche de documents semblables,
contrairement à Paperless (`more like this`).

## Méthode

1. Lire la doc MariaDB 11.5 du plein texte (MATCH … AGAINST, mode naturel, `WITH QUERY EXPANSION`, mots
   vides, longueur minimale) et l'index existant (`src/Recherche/IndexPleinTexte`, table `recherche_textes`,
   FULLTEXT MyISAM `ft_recherche`, `ft_min_word_len = 4`).
2. Oracle d'abord (rouge) : un corpus synthétique de trois factures d'un même émetteur et une lettre sans
   rapport ; « semblables » d'une facture doit rendre les deux autres factures en tête et pas la lettre.
3. Moteur dans `src/Recherche/` (termes significatifs du document, requête dans l'index, document lui-même
   exclu, droits `DroitsDossiers::predicatLecture` DANS la requête) ; budget < 1 s sur 10 000 documents.
4. Écran : le lien mène à une liste de semblables, testée E2E par clic réel.

## Fait (commits)

- `d8c99a4` — oracle rouge : `tests/oracles/recherche/semblables.json`, `tests/php/Recherche/SemblablesTest.php`, cette note.
- `ad98bcb` — moteur : `src/Recherche/Semblables.php`, `TermesSemblables.php`, `Pertinence.php` (extrait de `Service`).
- `a7a4517` — écran : `Ecran::donnees` mode `?semblable=`, gabarit (lien + en-tête), tests Ecran + E2E par clic.
- `b57c79f` — budget : `tests/php/Recherche/SemblablesVolumeTest.php` (10 000 documents) + budget Playwright de l'écran.
- `163f3c4` — gabarit robuste quand les variables `semblable`/`semblables_actifs` sont absentes (repli maquette).

## Décision de mode (doc MariaDB 11.5)

Le mode naturel de MyISAM écarte les mots présents dans 50 % des lignes et `WITH QUERY EXPANSION`
construit son expansion depuis un top de lignes : sur un fonds de factures qui partagent « facture »,
la liste dériverait. `Semblables` interroge donc le même index FULLTEXT (`ft_recherche`) en BOOLEAN MODE
(pas de seuil de 50 %), avec les termes significatifs du document (`ft_min_word_len = 4`, mots vides
filtrés localement), l'émetteur en bonus, et un partage minimal de deux termes pour écarter un mot
fourre-tout isolé (« jour » ne fait pas un semblable).

## Preuves

- **Oracle d'abord** : `SemblablesTest` vu ROUGE (`Class "Ged\Recherche\Semblables" not found`) avant le
  moteur, puis vert ; jamais affaibli.
- **PHPUnit ciblé `tests/php/Recherche`** : **27 tests, 151 assertions, vert** (19 existants + 4 moteur +
  3 écran + 1 volume). Suite complète **479 tests, 2 691 assertions, 1 skipped, 0 échec**.
- **Playwright** : `recherche.spec.ts` **4 passed** (dont le clic « Documents semblables ») ;
  `budget-recherche.spec.ts` **2 passed** — recherche **213 ms**, semblables **227 ms** (< 1 000 ms,
  annotation du rapport). Harness couche 4 : **67 passed**.
- **Budget moteur 10 000 documents** (`SemblablesVolumeTest`, index synthétique en base de test) :
  **88–115 ms** sur 5 tours après chauffe, source exclue, facture sœur du même émetteur en tête ;
  insertion des 10 000 lignes : 746 ms.
- **Harness `-SansBanc`** (commit `163f3c4`, 702 s) : `0-garde-fous` VERT, `1-unitaire` VERT (479/2 691),
  `4-ecrans` VERT (67), `7-securite` VERT, `2-contrat` VERT, `6-charge` VERT (p95 lecture 750 ms,
  écriture 248 ms, 0 erreur, 0 collision), `8-statique` VERT, `5-personas` VERT (6 personas, tous les
  gestes trouvés). Seul rouge : `3-banc`, sautée comme demandé par `-SansBanc`.

## Porte qualité (ECC)

- **Analyse statique** : `php vendor/bin/phpstan analyse --memory-limit=2G` → **0 erreur** hors baseline
  (baseline vide, jamais grossie) ; `php tools/composer.phar audit --locked` → aucune vulnérabilité.
- **Revue du diff contre php-reviewer** : `declare(strict_types=1)`, types de paramètres et de retour,
  classes `final`, propriétés `readonly` ; méthodes courtes (`pour` 2 paramètres, `candidats` 3, `retenir`
  4) ; aucun contrôleur géant ; duplication supprimée (`Pertinence` partagée par `Service` et `Semblables`,
  `Ecran::resultats()` et `Semblables::appliquerDroits()` extraits) ; nombres nommés (`BONUS_EMETTEUR`,
  `BONUS_TYPE`, `CANDIDATS_MAX`, `PARTAGE_MINIMUM`, `LIMITE_DEFAUT/MAX`, `LONGUEUR_MIN`) ; imports nets,
  aucun `var_dump`/`dd`.
- **Revue du diff contre security-reviewer** : SQL entièrement paramétré (`?`), aucun fragment construit
  par interpolation d'une entrée ; l'alias `d` du prédicat et les noms de table/index sont des constantes
  du code ; `LIMIT` et bonus interpolés uniquement depuis des constantes internes bornées. Les droits de
  lecture sont posés DANS la requête (`DroitsDossiers::predicatLecture`) pour la source ET les candidats :
  une source hors droits est introuvable, la liste reste vide, l'administrateur (`*`) voit tout, le compte
  synthétique des oracles garde son comportement. XSS : Twig échappe par défaut, `{{ semblable.titre }}`
  est échappé, `href` ne porte qu'un entier. Aucune donnée réelle, aucun nom de client.
- **Revue du diff contre silent-failure-hunter** : chaque `PDOException` ajoutée est nommée
  (`ErreurSql::nommer`, journalisée via `error_log`) — `Semblables::pour` et `Ecran::donneesSemblables` ;
  `Semblables::source()` propage volontairement pour ne pas confondre « document absent » et « base
  injoignable ». Aucun `catch` vide ajouté ; le repli documenté de `Ecran::typesFiltre()` (liste vide,
  lot V2-13) est conservé tel quel.
- **TDD** : oracle rouge d'abord, puis vert, sans jamais relâcher une assertion.

## Modifications hors secteur (signalées, règle « un agent = un secteur »)

Le constat du juge porte sur le gabarit du secteur Interface. Modification minimale, demandée explicitement
par la mission, consignée ici comme demande :

- `templates/ecrans/recherche.twig` — le lien « Documents semblables » passe de `/bibliotheque?doc=<id>` à
  `/recherche?semblable=<id>` (liste réelle), un en-tête nomme le document source, et les filtres de
  recherche sont masqués dans ce mode. Mêmes classes et mêmes `data-testid` ; le repli maquette est
  conservé (les variables absentes sont gérées par `is defined`), vérifié par un rendu de démonstration.
- Aucune route nouvelle, donc aucune modification de `Kernel`, `DroitsRoutes` ou `Ecrans` : la liste vit
  dans l'écran `GET /recherche` (déjà déclaré `recherche:lire`).

ETAT: fini
