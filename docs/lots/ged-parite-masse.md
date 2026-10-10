# Lot ged-parite-masse — parité Paperless : modification en masse et écrans jamais parcourus

Agent : DeepCode · Juge : Claude (poste principal) · Base : `gedv2_test` · Fonds des oracles :
`var/oracles/parite-masse/documents`.

## Mission

1. Lire la doc Paperless-ngx (bulk edit, rubrique Usage) et adapter ses opérations au modèle GEDv2
   (le dossier fait foi, `.meta.json` à côté, zéro suppression : la suppression est une corbeille).
2. Bibliothèque : cases à cocher sur les lignes, tout sélectionner (page ET résultat filtré), barre
   d'actions en masse à gauche sous le titre (type, étiquettes +/−, émetteur, dossier, corbeille).
   Confirmation qui annonce le nombre de documents. Chaque document passe par le MÊME service que
   l'édition unitaire (`DepotFichiers::mettreAJour`/`miseALaCorbeille` : même `.meta.json`, mêmes
   versions, même trace de déplacement).
3. Droits : un document que le compte ne peut pas modifier n'est jamais modifié ; le bilan dit
   combien sont traités et combien refusés, et pourquoi. Oracle PHPUnit avec un compte manager et
   deux dossiers (un en modification, un en lecture seule).
4. Traitement long : par paquets courts avec reprise (règle I/O batch), jamais un appel PHP de
   plusieurs minutes ; 500 documents doivent passer.
5. Découvrabilité (constat du lot ged-personas, note `docs/lots/ged-personas.md`, §2) : `/metadonnees`,
   `/administration/comptes`, `/administration/groupes`, `/administration/roles` et
   `/dossiers/{id}/droits` n'ont aucun lien visible. Les ajouter à la source unique des entrées de
   menu (section Administration, visible seulement aux rôles qui y ont droit ; droits d'un dossier
   depuis la Bibliothèque), sans dupliquer de menu.
6. E2E par l'écran (cocher, choisir l'action, confirmer, vérifier la liste et la fiche) ; plus un
   parcours E2E pour chacun des 4 écrans jamais parcourus (`/administration/groupes`,
   `/administration/roles`, `/partages-avec-moi`, `/workflows/catalogue`), en y arrivant par le menu
   comme un utilisateur.

## Contexte (mesure du juge, 10.10, v2 37c7916)

GEDv2 couvre ~80 % des fonctions de Paperless-ngx 2.20 ; la modification en masse manque entièrement
et 4 écrans n'ont aucun test E2E. Cible de la ligne de matrice : « Modification en masse (type,
étiquettes, émetteur, dossier, corbeille) — niveau 9, droits respectés ».

## Doc Paperless-ngx (rubrique Usage, bulk editing) et adaptation GEDv2

Source : `docs.paperless-ngx.com/usage/#bulk-editing` (2.20). Paperless sélectionne des documents par
cases ; la case d'en-tête sélectionne la PAGE, puis « Select all matching » sélectionne tout le
RÉSULTAT FILTRÉ. La barre d'actions propose Edit / Delete / Download / Reprocess / Merge ; le dialogue
d'édition porte : set correspondent, set document type, set storage path, add/remove/set tags,
set permissions, set owner, modify custom fields. En 2.20, la suppression part à la **corbeille**
(Trash), pas d'effacement immédiat. Les droits exigés sont *change* pour éditer et *delete* pour
supprimer, sur les documents accessibles.

Adaptation au modèle GEDv2 (le dossier fait foi, `.meta.json` à côté, zéro suppression) :

| Paperless | GEDv2 (ce lot) |
| --- | --- |
| case page + « Select all matching » | `coche-tout-page` + `coche-tout-resultat` (« tout le résultat filtré ») |
| set document type | changer le type (`documents:modifier`) |
| add tags / remove tags | ajouter / retirer des étiquettes |
| set correspondent | changer l'émetteur |
| set storage path | déplacer vers un dossier |
| Delete → Trash | mettre en corbeille (fichier sous `.corbeille/`, ligne drapeautée, réversible) |
| set permissions / owner / custom fields, Download, Reprocess, Merge | hors de ce lot (droits par dossier, champs et édition de pièces vivent déjà dans leurs écrans) |

## Avancement

### Fait (commits)
- `8a24ef3` — service `ModificationMasse` + oracle PHPUnit (refus, effets unitaires, 500 docs par
  paquets avec reprise, corbeille).
- `e4c7f00` — une seule source de menu (`Ged\Ui\Rail`) : « Partagés avec moi », section Administration
  (visible seulement à `application:administrer` ou `*`), catalogue des workflows servi en page lisible
  à un navigateur (JSON conservé pour le designer/l'API), oracle `RailMenuTest` + test du catalogue HTML.
- `b1bc516` — écran Bibliothèque : cases à cocher (`coche-doc-<id>`), « toute la page » et « tout le
  résultat filtré », barre d'actions en masse à gauche sous le titre, confirmation qui annonce le nombre,
  bilan des traités/refusés avec raisons ; routes `POST /bibliotheque/masse` et
  `POST /bibliotheque/masse/{id}/tranche` ; permissions `documents:modifier` ; lien « droits du dossier »
  depuis le dossier actif ; charges CSRF de la sonde (`tools/banc/sonde-securite.php`, fichier partagé
  signalé) ; filtres de la sélection résolus par la MÊME méthode que la liste paginée
  (`EcranBibliotheque::resoudre`/`idsFiltres`).
- `d75e3b8` — E2E `tests/e2e/parite-masse.spec.ts` (5 parcours) + préparation
  `tests/e2e/preparer-parite-masse.php` : en masse par l'écran (cocher, choisir, confirmer, bilan,
  liste, fiche), « tout le résultat filtré » au-delà de la page, et les 4 écrans jamais parcourus via
  le menu (groupes, rôles, partagés avec moi, catalogue).
- `37985ac` — journalisation d'un état de masse illisible (`error_log`), E2E : « toute la page »,
  « droits du dossier » depuis la Bibliothèque.
- `b64c59d` — budgets d'action de la connexion du parcours (15 s, comme `tests/e2e/connexion.ts`) : un
  `fill` à 5 s expirait sous la charge du harness complet (défaut de test, pas de produit).

### En cours
- (aucun)

### Reste
- (aucun ; voir le harness final et la porte qualité ci-dessous.)

## Porte qualité (ECC)

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur hors baseline**
  (`phpstan-baseline.neon` reste **vide**, jamais grossie). `composer audit --locked` → **aucune
  vulnérabilité**. Couche `8-statique` du harness : **VERT**.
- **TDD** : oracle d'abord — `tests/php/Socle/ModificationMasseTest.php` a d'abord échoué (`Class
  "Ged\Socle\ModificationMasse" not found`), puis le service l'a fait passer **4 tests / 86 assertions**.
  Aucun test existant affaibli : le test JSON du catalogue et le contrat de `ModuleTest` restent
  inchangés ; `maquettes`, `bibliotheque`, `actions-v2-12c` rejoués verts.
- **Revue du diff** contre les grilles `php-reviewer`, `security-reviewer`, `silent-failure-hunter` :
  - SQL entièrement statique et paramétré (`?`) ; `idsFiltres` lie les identifiants de recherche
    (`FIELD(d.id, ?…)`) et borne `LIMIT` par un entier local ; aucun fragment construit à partir d'une
    entrée utilisateur. L'alias de table est une constante du code.
  - Droits : la garde vérifie la permission globale `documents:modifier` et le CSRF ; le service vérifie
    le niveau `modifier` **par document** (`DroitsDossiers::autorise`). « Tout le résultat filtré »
    applique la MÊME clause ACL que la liste : un document non modifiable n'est jamais modifié et le
    refus est compté avec sa raison.
  - Chemins : `dossier` refuse `..` et `/` ; `DepotFichiers::cheminAbsolu` garde la racine du fonds.
  - XSS : aucun `|raw` ajouté, Twig échappe tout ; les `data-testid` sont des constantes.
  - Erreurs : aucun `catch` vide ajouté ; un refus est nommé et compté, un audit illisible et un état de
    tâche illisible sont tracés (`error_log`) ; l'échec imprévu d'une tranche marque la tâche `echec`
    avec sa cause et lève une `RuntimeException` nommée (422 à l'écran, jamais un 500 muet).
  - Constats MEDIUM assumés : la borne de sélection (5000) est un garde-fou local documenté dans
    `idsFiltres`, pas un jeton métier ; `EcranBibliotheque::donnees()` reste une méthode de rendu
    (construction du tableau vue), la logique de sélection vivant dans `resoudre`/`idsFiltres`. Aucun
    constat CRITIQUE ou ÉLEVÉ.

## Mesures exactes — harness final `-SansBanc`

Commande : `powershell -ExecutionPolicy Bypass -File tools/harness.ps1 -SansBanc`
(arbre commité `b64c59d`, 11 min 49 s).

- `0-garde-fous` **VERT** (vendor/node_modules présents, `DB_NAME=gedv2_test` imposé).
- `1-unitaire` **VERT** : **479 tests, 2747 assertions, 1 skip, 0 échec**
  (dont `ModificationMasseTest` 4/86, `RailMenuTest` 3, catalogue HTML +1).
- `2-contrat` **VERT** : toutes les routes déclarées sont couvertes (dont les 2 du lot).
- `4-ecrans` **VERT** : **70 passed** (dont `parite-masse.spec.ts` 5/5).
- `6-charge` **VERT** : p95 lecture **655 ms**, p95 écriture **241 ms**, 0 erreur, 0 collision, ingestion
  finie.
- `7-securite` **VERT** : sonde offensive, aucun contournement accepté (CSRF absent/rejoué refusé sur les
  2 nouvelles mutations).
- `8-statique` **VERT** : PHPStan 0 erreur hors baseline ; `composer audit` : aucune vulnérabilité.
- Rouges attendus par construction : `3-banc` (sautée par `-SansBanc`) et `5-personas` (couche non
  écrite, hors lot). **Aucun rouge fonctionnel dû au lot.**
- `tests/corpus/` reste intact ; le fonds d'oracle vit sous `var/oracles/parite-masse/`.

ETAT: fini
