# Secteur RECHERCHE & APERÇU — visionneuse, miniatures, plein texte, API

Agent : DeepCode · Juge : Codex · Lot en cours : V2-05b

## Mission
Voir un document sans le télécharger (PDF, images, bureautique), vite ; le retrouver par son texte et ses métadonnées.

## Chemins
`src/Apercu/` (dont `src/Apercu/Module.php` : routes du secteur, appelé par `src/Kernel.php` — ne jamais modifier Kernel), `src/Recherche/`, `public/assets/js/visionneuse.js`, `public/assets/vendor/pdfjs/`,
`templates/partiels/visionneuse.twig`, `tools/miniatures.php` (commande hors requête nommée par le lot), `tests/php/Apercu/`, `tests/e2e/apercu.spec.ts`, `tests/corpus/apercu/`

Chemins ajoutés au lot V2-05b : `tests/php/Recherche/`, `tests/oracles/recherche/`, `tests/e2e/recherche.spec.ts`,
`tools/indexer-recherche.php` (indexation hors requête). Branchement minimal demandé à Interface (3 lignes, repli maquette conservé) :
`src/Ui/Ecrans.php::recherche()` et `templates/ecrans/recherche.twig` (mêmes classes et mêmes `data-testid`).


## Lot V2-05a — porte
1. Visionneuse PDF intégrée (pdf.js en local dans `public/assets/vendor/pdfjs/`, aucun CDN) qui remplace le macro
   `papier.page()` là où un vrai fichier existe : pages, zoom, rotation d'affichage, défilement ; clair et sombre.
2. Miniature de première page générée HORS requête (commande `php tools/miniatures.php`), servie par une route qui
   passera par la garde du secteur Droits (déclare la permission `document.voir`).
3. Images (png, jpg, tiff) et bureautique (docx, xlsx, odt via conversion PDF si LibreOffice présent, sinon message
   nommé « aperçu indisponible : LibreOffice absent »).
4. Corpus de test synthétique dans `tests/corpus/apercu/` (générés par script, aucun document réel).
5. Tests : un PDF de 3 pages s'affiche en < 1 s, la page 2 est atteignable au clavier, 0 erreur console.

## Reprendre de GEDv1
`app/Services/ThumbnailGenerator.php` et `OfficeThumbnailService.php` (adaptateurs de rendu, détection des outils).
`SearchService.php` pour V2-05b (FULLTEXT + repli LIKE + extraits), pas pour ce lot.

## Demandes aux autres secteurs
- **Interface** : remplacer `papier.page(...)` par
  `{% import 'partiels/visionneuse.twig' as vis %}{{ vis.moteur(rendu, 'apercu-page') }}` dans
  `templates/ecrans/bibliotheque.twig` et `templates/ecrans/document.twig` quand la pièce a un fichier réel
  (le rendu vient de la route `/documents/{id}/apercu`) ; vous pouvez ensuite déplacer les styles du composant,
  aujourd'hui injectés par `visionneuse.js`, dans `ged.css`. Tant que ce n'est pas fait, l'écran autonome
  `/documents/{id}/apercu` tient la porte.
- **Droits & sécurité** : la garde centrale doit lire l'attribut `permission` posé par `Ged\Apercu\Permission`
  (valeur `document.voir`) et couvrir `/documents/{id}/apercu`, `/documents/{id}/apercu/fichier`,
  `/documents/{id}/fichier`, `/documents/{id}/miniature`. Aucune de ces routes n'est publique.
- **Socle** : dès que le vrai dossier documentaire existe, positionner `STORAGE_ROOT` ; `Ged\Apercu\Source`
  le parcourt déjà (id lu dans `.meta.json`, aucune base). Sans cela, le défaut pointe sur le corpus synthétique.
- **Interface, fait au lot V2-03-écran (notification)** : `/a-traiter` importe `partiels/visionneuse.twig` et
  appelle `moteur(rendu, 'apercu')`. Pour que l'import fonctionne, l'`import` d'icônes a été déplacé DANS la
  macro (`templates/partiels/visionneuse.twig`, chemin du secteur Interface) : une macro importée n'exécute pas
  le corps du gabarit, donc `ic` manquait. À reprendre de la même façon quand `bibliotheque.twig` et
  `document.twig` brancheront l'aperçu réel.

## Journal V2-05a
Fait :
- `src/Apercu/` : `Source`/`Piece` (le dossier fait foi, id lu dans `.meta.json`, refus des chemins hors racine),
  `Outils` (détection pdftoppm/Ghostscript/ImageMagick/LibreOffice, conversions), `Miniature` (artefact hors requête),
  `Permission` (déclare `document.voir`), `Module` (4 routes enregistrées automatiquement par le Kernel).
- `tools/miniatures.php` : fabrique les vignettes `.apercu/<id>.vignette.png` (et l'aperçu PNG des TIFF, le PDF des
  bureautiques quand LibreOffice est présent) sans jamais les produire pendant une requête.
- `templates/partiels/visionneuse.twig` + `public/assets/js/visionneuse.js` : pages, zoom, rotation d'affichage,
  défilement, navigation clavier (Page suivante/précédente, flèches), clair et sombre, styles par jetons uniquement.
  pdf.js 3.11.174 vendu dans `public/assets/vendor/pdfjs/` (aucun CDN à l'exécution).
- `tests/corpus/apercu/` : corpus 100 % synthétique régénérable (`generer.php`) — PDF 3 pages, PDF 1 page,
  png/jpg/tiff, docx/xlsx/odt — plus ses vignettes.

Preuves :
- PHPUnit : 38 tests, 106 assertions, vert (`tests/php/Apercu/` : Source, contenu des routes, miniature, permission).
- Playwright : 19 tests, vert, dont 7 d'aperçu — PDF de 3 pages affiché en < 1 s (première page), page 2 au clavier,
  0 erreur console, png/jpg/tiff affichés, bureautique nommée, miniature servie en PNG, 404 nommé.
- Captures : `var/captures/apercu-pdf-clair.png`, `var/captures/apercu-pdf-sombre.png`.
- Harness du 29-09 : `0-garde-fous`, `1-unitaire` (38 tests) et `4-ecrans` (19 tests) VERTES ; les couches
  `2-contrat`, `3-banc`, `5-personas`, `6-charge`, `7-securite` restent « pas encore écrite », inchangées.
- Commits : `19052cc` (source et outils), `db1106d` (visionneuse, gabarit, corpus), `8abcee6` (tests),
  `e0b903f` (journal), `3aa7b54` (binaires figés hors conversion CRLF).

Reste (hors porte de ce lot) :
- Branchement du macro dans les écrans Bibliothèque / Fiche (demande Interface), pour remplacer `papier.page()`
  in situ plutôt que sur l'écran autonome.
- V2-05b : plein texte (FULLTEXT + repli LIKE + extraits), qui reprendra `SearchService.php` de GEDv1.

ETAT: fini

## Journal V2-05b (29-09)

**Fait, clause par clause**
- **Index FULLTEXT** : `src/Recherche/Schema.php` pose `recherche_textes` (titre + contenu, index `ft_recherche`),
  sans jamais modifier la table `documents` du Socle. L'index est un cache DÉRIVÉ, reconstructible depuis le dossier ;
  il est en MyISAM parce que le FULLTEXT InnoDB de ce serveur ne tokenise rien (`innodb_ft_min_token_size = 0`) et
  que MyISAM, seul, répond. `assurerMoteur` / `assurerColonneAbsence` / `assurerIndexPleinTexte` rendent
  l'installation idempotente et rattrapent une table ancienne.
- **Alimentation** : `SourceRecherche` lit le fonds (`.meta.json` : `texte`/`ocr`, ou le contenu d'un fichier texte) ;
  `IndexPleinTexte` synchronise par lots bornés (reprise au prochain appel) ; `tools/indexer-recherche.php` fait le
  gros du travail hors requête (`--tout`, `--vider`, `--limite=`). Une ligne dont le fichier a disparu est marquée
  absente, jamais supprimée (règle « zéro suppression »).
- **Repli LIKE** : déclenché quand l'index est absent, quand la requête n'a aucun terme exploitable, quand le terme
  fait moins de quatre lettres (non indexé par MyISAM) ou quand un fragment interne ne peut pas être trouvé par
  FULLTEXT (`ntrat` trouve les deux contrats). Le moteur retombe aussi sur LIKE si FULLTEXT ne rend rien.
- **Extraits surlignés** : `Extraits` découpe titre et corps en segments marqués/non marqués ; le gabarit n'affiche
  que `<mark>`, jamais du HTML produit (aucune injection).
- **Filtres au même périmètre** : type, émetteur, date, montant sont posés dans la MÊME clause que le texte ; le total
  et la liste partagent donc exactement le même WHERE.
- **Erreurs SQL nommées** : `ErreurSql` (étape, cause lisible, référence) ; l'écran affiche
  `data-testid="recherche-erreur"`, jamais une liste vide muette.
- **Écran `/recherche`** : branché sur les vrais résultats du fonds, gabarit et système visuel conservés (mêmes
  classes, mêmes `data-testid`) ; compte réel, durée, filtres réels, état vide nommé.

**Preuves**
- Oracle de porte : `tests/oracles/recherche/recherche.json` rejoué par `OracleRechercheTest` — 11 requêtes,
  36 assertions, 100 % des attendus EN TÊTE dans l'ordre annoncé, mode fulltext/like vérifié, chaque requête < 1 s.
  Vu ROUGE d'abord (classe absente), puis vert.
- PHPUnit Recherche : 19 tests, 102 assertions (`SchemaTest`, `ServiceTest`, `ExtraitsTest`, `EcranTest`).
- Playwright : 25 tests verts (dont 4 `recherche.spec.ts`) — documents réels, terme surligné, filtre type,
  absence dite, écran < 1 s, 0 erreur console ; le Ctrl+K des maquettes surligne « maintenance » sur les vrais
  résultats.
- Harness `-SansBanc` du 29-09 : `0-garde-fous` VERT, `4-ecrans` VERT (25) ; `3-banc` sautée comme demandé ;
  `1-unitaire` ROUGE (153 tests, 2 erreurs) **à cause d'un test Droits non commité d'un agent concurrent**
  (`tests/php/Securite/DroitsBaseTest.php` réclame `Ged\Securite\Schema`, pas encore écrit — hors secteur).
  En excluant ce fichier : 151 tests verts. Sur HEAD commité, ce fichier n'existe pas et la couche repasse verte.
- Commits : `9461072` (moteur + oracle), `f6a52d8` (index sans suppression), `a516d7e` (écran),
  `f358dd2` (tests, outil, e2e).

**Demandes**
- **Interface** : `Ged\Ui\Ecrans::recherche()` contient le branchement minimal (3 lignes, repli maquette conservé) ;
  à reprendre comme point de contrat si d'autres écrans sont branchés. Aucune autre attente.
- **Droits & sécurité** : aucune modification de `DroitsRoutes` — `GET /recherche` y déclare déjà `recherche:lire`.
  Le secteur n'ajoute aucune route (l'écran est servi par le Kernel, comme les autres).
- **Socle** : `DepotFichiers` n'écrit pas l'`id` dans le `.meta.json` ; l'index le retrouve par `chemin_relatif`.
  Si l'id doit un jour survivre à une reconstruction, le poser au dépôt serait plus direct.

**Reste hors porte** : recherche sémantique/vectorielle, vues enregistrées, API de recherche (« documents
semblables »), qui viendront dans les lots V2-05 suivants.

ETAT: fini


## Jugement V2-05b (Claude Code, 29-09) — porte franchie
Harness complet rejoué après les commits des trois agents : unitaire 155/155, bancs verts (découpe, classement, F1 de bout en bout), écrans 25/25. Oracle `recherche.json` : 11 requêtes, attendus en tête, < 1 s. Réserve : l'index est en MyISAM (cache dérivé, reconstructible : acceptable) ; l'explication « le FULLTEXT InnoDB ne tokenise rien » est à vérifier sur le serveur de production avant d'en faire une règle.
ETAT: fini (jugé)

## Demandes du HARNESS (lot H-6 « 6-charge », 29-09-2026)

Mesure : fonds synthétique de 10 000 documents (`var/charge/`, `tools/banc/charge.php`), 3 puis 10 utilisateurs
simultanés. Preuves : `recette/preuves/charge/charge.{json,md}`.

- **`/recherche` ne tient pas le seuil (défaut nommé)** : p95 4,4 s à 3 utilisateurs, 2,9 s à 10 ; p50 1,5–1,9 s.
  Cause : `Ged\Recherche\Service::rechercher()` appelle `IndexPleinTexte::synchroniser(200)` **à chaque requête**,
  qui relit tous les `.meta.json` du fonds puis recoupe toutes les lignes de `recherche_textes` et `documents`
  (`synchroniser()` → `SourceRecherche::documents()` + `signatures()` + `identifiantsDocuments()`), même quand rien
  n'a bougé. Attendu : ne synchroniser que sur un signal de changement (ou garder l'indexation dans un job court
  hors requête, comme `tools/indexer-recherche.php` le fait déjà), pas à chaque recherche.
- **`/documents/{id}/apercu` ne tient pas le seuil (défaut nommé)** : p95 1,8 s à 3 utilisateurs, 2,1 s à 10.
  Cause : `Ged\Apercu\Source::parId()` appelle `toutes()`, qui parcourt **tous** les `.meta.json` du fonds et les
  décode avant de trouver l'`id`. Attendu : résoudre l'`id` par l'index (ou un adressage dédié), sans balayer le
  fonds.

La bibliothèque (maquette) est la seule lecture qui tient le seuil (p95 0,6 s).

### Ouvert par le juge (30-09 10:30) — budget d'aperçu instable dans la suite complète

- `tests/e2e/apercu.spec.ts` « première page PDF < 1 s » échoue une fois sur deux dans la suite Playwright complète (1 548 ms à d5ce356, 1 s à chaud), et passe 7/7 quand on le lance seul. Troisième occurrence.
- À traiter par le secteur : soit rendre la mesure indépendante des autres parcours (worker dédié, `test.describe.configure({ mode: 'serial' })` ou projet Playwright à part pour les budgets de temps), soit prouver que la lenteur vient de l'application sous concurrence et la corriger. Ne pas relever le budget.

## Journal PERF-2 (30-09) — les budgets de temps ne partagent plus la file du serveur mono-processus

**Cause, mesurée (le banc, pas l'application)**
1. `playwright.config.ts` ne lançait qu'UN serveur `php -d opcache.enable=0 -S`, et la suite l'ouvrait à **10 workers**
   (`workers` effectif relevé dans `var/e2e-latest.json` ; machine à 20 processeurs). Or `php -S` sert **une requête à
   la fois** : `PHP_CLI_SERVER_WORKERS=4` répond « forking is not supported on this platform » (Windows) et 4 requêtes
   parallèles de 200 ms prennent 0,86 s sur le même PID. Un worker qui tenait une requête lente passait donc devant la
   mesure d'un autre.
2. Latence client du même serveur de test, mêmes URL que la suite (corpus `STORAGE_ROOT` des tests), ~8 tours :

   | route | au repos (p50) | sous 9 clients simultanés |
   |---|---|---|
   | `/documents/204/apercu` | 48 ms | 1 319 ms (p95 2 253 ms) |
   | `/recherche?q=maintenance` | 181 ms | 185 ms |

   Les valeurs se groupent autour de 1,06 s / 2,2 s : c'est la retransmission TCP quand la file de connexions
   débordait — d'où l'échec « une fois sur deux » et le 1 548 ms du juge. La page d'aperçu, elle, coûte ~50 ms de
   serveur et se mesure à 300-500 ms dans le navigateur.
3. Contre-épreuves : `npx playwright test tests/e2e/apercu.spec.ts` seul = **7/7 vert (5,7 s)** ; en suite complète à
   HEAD, la mesure « courante » tombe à **2 023 ms** pour un seuil de 1 000 ms
   (`var/test-results/apercu-…/error-context.md`). Aucune ligne de l'application n'est touchée par ce lot.

**Correction — isoler la mesure, jamais relever le seuil**
- `playwright.config.ts` : deux serveurs (`8871` parcours, `8872` budgets), deux projets. Le projet `budgets` prend
  `budget-*.spec.ts`, `workers: 1` et son `baseURL` sur le serveur dédié ; le pool des parcours garde ses 10 workers,
  ses timeouts et ses seuils.
- Budgets regroupés dans `tests/e2e/budget-apercu.spec.ts` (à froid < 2 000 ms, courante < 1 000 ms, 3 pages rendues,
  0 erreur console) et `tests/e2e/budget-recherche.spec.ts` (< 1 000 ms, index bâti hors requête). Assertions et seuils
  IDENTIQUES, aucun retry, aucun test exclu : les deux fichiers sont joués par `npx playwright test`. Chaque mesure est
  publiée en annotation `budget` (lisible dans `var/e2e-latest.json`) — une mesure muette ne prouve rien.
- Oracle d'abord : « banc : les budgets tournent sur un serveur dédié, hors du pool des parcours »
  (`budget-apercu.spec.ts`) vu **ROUGE** (projet `budgets` inexistant, `project.name` vide), puis vert après la
  configuration. Il redevient rouge si le banc reperd son isolement.

**Preuves**
- `npx playwright test` **5 fois de suite : 34 tests, 34 verts à chaque fois**, après la couche 1 (PHPUnit, la même
  remise à zéro de la base de test que fait le harness) : mesures d'aperçu courantes 369 / 347 / 348 / 364 / 306 ms
  (< 1 000) et recherche 169 / 181 / 136 / 173 / 187 ms (< 1 000) ; **aucun chevauchement entre les trois tests de
  budget** (horodatages du rapport : `workers: 1` tient).
- `powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` : `0-garde-fous` VERT, `1-unitaire` VERT
  (268 tests, 1 314 assertions), `4-ecrans` VERT (34 tests), `7-securite` VERT, `2-contrat` VERT, `6-charge` VERT
  (p95 lecture 697 ms) ; `3-banc` ROUGE (sautée, demandé) et `5-personas` ROUGE (pas encore écrite) — **aucun rouge
  nouveau**. Le harness réécrit `recette/registre.json` et `recette/preuves/charge/` : ces sorties ne sont pas
  committées par ce lot.
- Reproduire la cause : `PHP_CLI_SERVER_WORKERS=4 php -S …` (« forking is not supported »), puis mesurer
  `/documents/204/apercu` au repos et sous 9 clients (`curl -w '%{time_total}'`) : 48 ms → 1 319 ms.
- Ordre observé d'une suite (horodatages de `var/e2e-latest.json`) : les parcours démarrent tous ensemble (jusqu'à
  10 en vol), les budgets s'intercalent et s'enchaînent — `banc` → `aperçu` → `recherche`, jamais deux à la fois :
  c'est bien l'attente dans la file du serveur partagé qui faussait la mesure, pas l'ordre des parcours.

**Ce qui n'est pas de ce lot (dit, pas contourné)**
- `npx playwright test` **sans** la couche PHPUnit d'abord trouve 2 rouges constants : `tests/e2e/bibliotheque.spec.ts`
  « … les vraies vues et les vrais documents du fonds » (404 sur `GET /documents/10001/apercu/fichier`) et
  `maquettes.spec.ts` « écran bibliotheque » (mesuré **1 503 ms > 1 500 même seul, sans concurrence**). Cause :
  `gedv2_test` garde **10 053 documents** laissés par le banc de charge (H-6) ; le travail non commité de l'agent Socle
  branche `/bibliotheque` sur le fonds réel (`Ged\Socle\EcranBibliotheque`, là où HEAD servait `Maquette::bibliotheque()`)
  et rend alors **3,4 Mo de HTML pour 10 136 lignes**, en embarquant l'aperçu d'un document absent du corpus. Après
  PHPUnit, ces deux-là passent : problème d'hygiène de base partagée, pas le banc des budgets.
- Un worktree propre sur HEAD ne peut pas rejouer la suite : le Kernel (commité) itère `Ged\Socle\Module`, fichier
  **non commité**, et l'autoloader émet alors un avertissement PHP qui pollue les réponses binaires.

**Demandes**
- **Harness** : `tools/banc/charge.php` laisse ses 10 000 documents dans `gedv2_test`, et les parcours d'écrans
  supposent la base propre ; soit le banc restaure son état, soit la couche 4 dit qu'elle suit PHPUnit.
- **Vue d'ensemble** : convention à acter — *tout test qui affirme un budget de temps vit dans le projet `budgets`*
  (aujourd'hui `maquettes.spec.ts` « écran bibliotheque » mesure encore dans le pool parallèle).
- **Socle** : `Ged\Socle\Module` et les écrans V2-04 sont référencés par le Kernel commité ; sans eux, HEAD ne tourne
  pas seul.

ETAT: fini
### Jugement PERF-2 (juge, 30-09 12:16, copie propre sur ff15356)

- Cause établie : `php -S` sert une requête à la fois et la suite l'ouvrait à 10 workers ; la mesure d'aperçu attendait la file. L'application n'est pas en cause.
- Correction au bon endroit : projet Playwright « budgets » sur son propre serveur, un seul worker ; seuils inchangés (2 s à froid, 1 s courante, 1 s recherche), aucun retry, aucun test exclu, et un oracle qui échoue si les budgets reviennent sur le serveur partagé.
- Harness complet : 0, 1 (237 tests), 2, 3 (53/53, F1 franchie), 4 (32/32), 6 (p95 lecture 657 ms) et 7 verts, alors que la machine portait d'autres chantiers.
- Verdict : **PERF-2 franchi.**
- Incident signalé par l'agent : un `rm -rf` sur son worktree temporaire a suivi une jonction et vidé `node_modules` de l'arbre principal ; restauré par `npm ci`. Contrôle du juge : `vendor` intact (2 693 fichiers, autoload OK), aucun fichier suivi supprimé. Règle pour tous : jamais de jonction vers l'arbre principal dans un worktree, et jamais de `rm -rf` qui traverse une jonction.

## Journal ged-semblables (10-10-2026, DeepCode) — vrais documents semblables

Constat du juge : le lien « Documents semblables » ouvrait la fiche (`/bibliotheque?doc=<id>`), pas une
liste de voisins.

**Fait**
- `src/Recherche/Semblables.php` : depuis un document de l'index `recherche_textes`, extrait ses termes
  significatifs (`TermesSemblables`, titre pondéré, longueur ≥ 4, mots vides filtrés) et interroge le MÊME
  index FULLTEXT (`ft_recherche`) en BOOLEAN MODE ; document source exclu par sa clé ; émetteur en bonus,
  petit bonus de type ; partage minimal de deux termes. Les droits de lecture sont posés DANS la requête
  (`DroitsDossiers::predicatLecture`) pour la source et les candidats.
- `TermesSemblables`, `Pertinence` (barème extrait de `Service`, un seul barème partagé).
- Écran : `Ged\Recherche\Ecran::donnees()` accepte `?semblable=<id>` et rend la liste dans le gabarit de
  résultats existant ; aucune route nouvelle.

**Preuves**
- Oracle d'abord : `tests/oracles/recherche/semblables.json` + `SemblablesTest` vus ROUGE (classe absente),
  puis vert. PHPUnit : 479 tests / 2 691 assertions verts (dont 4 moteur, 3 écran, 1 volume).
- Budget : 10 000 documents en 88–115 ms (seuil 1 s) ; écran `/recherche?semblable=206` 227 ms
  (projet `budgets`).
- E2E : clic réel sur `semblables-206` → liste sans le document source, `resultat-208` visible.
- Harness `-SansBanc` commit `163f3c4`, 702 s : toutes les couches vertes sauf `3-banc` sautée.

**Demande / modification hors secteur (signalée)**
- **Interface** : `templates/ecrans/recherche.twig` — le lien pointe vers la liste et un en-tête nomme le
  document source. Modification minimale demandée par la mission (constat du juge) ; mêmes classes et
  `data-testid`, repli maquette conservé (variables absentes gérées par `is defined`).

ETAT: fini
