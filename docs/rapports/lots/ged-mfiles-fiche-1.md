# Lot `ged-mfiles-fiche-1` — Famille M-Files nº 5 : fiche document et son rendu

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3307).
Branche : `ged/ged-mfiles-fiche-1` · point de départ : `origin/v2`.

## Objectif

Porter la **famille M-Files nº 5** (`docs/PLAN-GEDV2.md`, `docs/parite/PARITE-MFILES.md`) — aperçu/vignette
d'un objet et d'un fichier, titre d'objet et de fichier, historique des versions, commentaires, statut de
verrouillage / check-out — en faisant passer les éléments `mfiles:*` correspondants de `absent` à
`couvert` / `couvert-autrement`, prouvés par des tests rejoués, sans toucher `src/Parite/Juge.php`, la
liste fermée d'exclusions, ni les statuts/vecteurs Paperless.

Les cinq familles visées (11 éléments `mfiles:rest:*`) :

| Famille | Éléments M-Files |
| --- | --- |
| Aperçu / vignette | GET `…/version/preview` (objet), GET `…/files/{file}/preview` (fichier) |
| Titre | GET/PUT `…/version/title` (objet), GET/PUT `…/files/{file}/title` (fichier) |
| Historique | GET `…/history` |
| Commentaires | GET/PUT `…/version/comments` |
| Check-out | GET/PUT `…/version/checkedout` |

Les icônes de classe/type (`/structure/classes/{id}/icon`, `/structure/objecttypes/{type}/icon`) ne sont
**pas** des vignettes d'objet ou de fichier : laissées `absent`, **proposées** au juge (aucune exclusion
posée par l'agent).

## Ce qui change (commits)

| Commit | Contenu |
| --- | --- |
| `7cdf144` | Cadrage du secteur `docs/secteurs/fiche-rendu.md` (chemins, demandes, journal). |
| `ad267d8` | **Historique** : service `Ged\Fiche\Historique` (versions + déplacements, du plus récent au plus ancien, lus du fonds ; base muette tracée) ; `Ui\Document::historique` lui délègue. |
| `88399d7` | **Titre / nom de fichier** : service `Ged\Fiche\Nom` (lire le nom rangé, renommer le fichier réel — fichier, `.meta.json` et `.versions/` suivent, index et trace `renommage`, titre d'objet intact) ; oracles du titre d'objet dans le `.meta.json`. |
| `fbc0463` | **Check-out** : service `Ged\Fiche\Verrou` (état dans le `.meta.json`, délai 30 min prolongeable, expiration qui libère, refus d'autrui, libération forcée admin, gestes tracés). |
| `1be3aa3` | **Interface** : routes `Ged\Fiche\Module` (`/titre`, `/nom-fichier`, `/verrou`), permission `documents:modifier` + niveau ACL, ligne Kernel signalée, nom de fichier et état du verrou sur la fiche, barre d'actions `data-testid`, charge CSRF de la sonde. |
| `a3df142` | **Parité M-Files famille 5** : section du générateur, `correspondance.json` et `PARITE-MFILES.md` régénérés. |
| `ef8423f` | **Passe 2 — correcteur** : le générateur de correspondance préserve désormais les 17 exclusions M-Files ratifiées par le juge (régression introduite par le rejeu famille 5) ; oracles `MfilesFicheVecteursTest` ; parité hors exclusions 58,95 % → 70,53 %. |

## Éléments passés de `absent` à couvert

| Élément M-Files | Statut | Équivalent GEDv2 | Preuve |
| --- | --- | --- | --- |
| GET `…/version/preview` (objet) | `couvert-autrement` | route `GET /documents/{id}/apercu` | `Apercu\ModuleTest::testPageApercuPdfSeDesigne` |
| GET `…/files/{file}/preview` (fichier) | `couvert` | route `GET /documents/{id}/miniature` | `Apercu\ModuleTest::testMiniatureServieEnPng` |
| GET `…/version/title` (objet) | `couvert` | service `Ged\Socle\DepotFichiers` | `Fiche\TitreTest::testLeTitreDeLObjetSeLitDuMetaJsonQuiFaitFoi` |
| PUT `…/version/title` (objet) | `couvert` | service `Ged\Socle\DepotFichiers` | `Fiche\TitreTest::testChangerLeTitreMetAJourLeMetaEtLIndexSansRenommerLeFichierNiCreerDeVersion` |
| GET `…/files/{file}/title` (fichier) | `couvert` | service `Ged\Fiche\Nom` | `Fiche\NomTest::testLeNomDeFichierSeLitSurLIndex` |
| PUT `…/files/{file}/title` (fichier) | `couvert` | service `Ged\Fiche\Nom` | `Fiche\NomTest::testRenommerLeFichierDeplaceMetaEtVersionsEtTraceSansRienPerdre` |
| GET `…/history` | `couvert` | service `Ged\Fiche\Historique` | `Fiche\HistoriqueTest::testLHistoriqueReunitVersionsEtDeplacementsDuPlusRecentAuPlusAncien` |
| GET `…/version/comments` | `couvert` | service `Ged\Socle\Collaboration` | `Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex` |
| PUT `…/version/comments` | `couvert` | service `Ged\Socle\Collaboration` | `Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex` |
| GET `…/version/checkedout` | `couvert` | service `Ged\Fiche\Verrou` | `Fiche\VerrouTest::testPoseLitEtRetireLeVerrouDUneFiche` |
| PUT `…/version/checkedout` | `couvert` | service `Ged\Fiche\Verrou` | `Fiche\VerrouTest::testUnAutreCompteNeRetirePasLeVerrouSansForce` |

« Le dossier fait foi » est tenu : le titre de l'objet, le nom du fichier et le verrou vivent dans le
`.meta.json` à côté du fichier (l'index n'en est qu'une copie reconstructible) ; la vignette est un fichier
**dérivé** en `.apercu/`, jamais la source.

## Mesures exactes

### Parité M-Files (juge `tools/parite/juge-mfiles.php`)

| Mesure | Valeur |
| --- | --- |
| Inventaire public M-Files rejoué à l'identique | oui (112 éléments) |
| Couverts (avant lot → après lot) | **56 → 67** |
| Absents (proposés au juge) | **28** |
| Exclus (ratifiés par le juge, préservés) | **17** |
| Non prouvés | **0** |
| Parité stricte | **50,00 % → 59,82 %** |
| Parité hors exclusions | **58,95 % → 70,53 %** |
| Erreurs du juge | 0 |

### Tests

| Mesure | Valeur |
| --- | --- |
| Dont `tests/php/Fiche` (5 fichiers) | **15 tests, 81 assertions** |
| Dont `tests/php/Parite/MfilesFicheVecteursTest.php` (passe 2) | **2 tests, 93 assertions** |
| Oracle rouge d'abord | oui : « classe absente » pour `Historique`, `Nom`, `Verrou` ; les routes de `Module` sans code auraient échoué |
| PHPStan niveau 6 | **0 erreur** (baseline vide inchangée) |

### Harness complet `-SansBanc` (commit `ef8423f`, passe 2)

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` — durée 849 s. Mêmes couches
vertes/rouges qu'en passe 1 ; aucun échec dans les chemins `Fiche` / `Parite`.

| Couche | État | Détail exact |
| --- | --- | --- |
| 0 garde-fous | VERT | vendor et `node_modules/@playwright/test` présents ; `DB_NAME=gedv2_test` imposé |
| 1 unitaire | ROUGE | **972 tests, 18 062 assertions, 8 erreurs / 9 échecs** — tous d'environnement (voir ci-dessous ; +2 tests = les oracles de la passe 2) |
| 3 banc | ROUGE | sautée (`-SansBanc`), par construction |
| 4 écrans | ROUGE | **96 parcours passés, 2 échoués** — les deux à cause de scripts de préparation qui exigent le moteur PDF absent |
| 7 sécurité | VERT | aucun contournement accepté, dont la charge CSRF des routes `/titre`, `/nom-fichier`, `/verrou` |
| 2 contrat | VERT | toutes les routes déclarées couvertes, dont les trois routes de la fiche |
| 6 charge | ROUGE | `proc_open CreateProcess failed` (environnement : démarrage du serveur de mesure impossible) |
| 8 statique | VERT | PHPStan niveau 6 : **0 erreur** ; `composer audit` : aucune vulnérabilité |
| 5 personas | VERT | administrateur 4/4, auditeur 4/4, comptable 4/4, débutant 4/4, fiduciaire 4/4, secrétaire 3/3 |
| 9 parité Paperless | VERT | 780 éléments, couverts **731**, strict **93,72 %**, hors exclusions **99,19 %** |
| 9b parité M-Files | VERT | 112 éléments, couverts **67**, strict **59,82 %**, hors exclusions **70,53 %** (exclus 17), absents 28, inventaire rejoué à l'identique |

Échecs d'environnement (aucun dans les chemins du lot) :

- **1 unitaire** : `EditeurPdfTest`, `EditionPiecesTest`, `DeplacementDroitsTest`, `EcransTest::testApplicationDiagnostiqueLeMoteurDeRetouche`
  et `InstallateurTest` exigent PyMuPDF/`CMD4_PYTHON` (moteur PDF absent sur ce poste) ; `InfomaniakClientTest`
  exige le service externe. `V212cTest::testValidationDeLot…` est une fragilité d'**ordre** préexistante
  (`recherche_textes` retiré par `SupportCorpus`) : il passe seul et passe juste après `tests/php/Fiche`
  (reproduit : `phpunit tests/php/Fiche tests/php/Socle/V212cTest.php` → **20/20 vert**). Le secteur Fiche
  **repose** désormais `recherche_textes` dans son `setUpCorpus` pour ne pas casser l'ordre des suites suivantes.
- **4 écrans** : `preparer-edition-pages.php` (`CMD4_PYTHON` vide → « First element must contain a non-empty program name »)
  et `preparer-ecrans-reels.php` (ingestion Pilote sans moteur PDF → « le document attendu n'a pas été rangé »).
- **6 charge** : `proc_open` de `PHP_BINARY -S …` refusé par Windows (`CreateProcess failed`), indépendant du lot.

## Passe 2 — correcteur (régression trouvée et corrigée)

La passe 1 avait livré les 11 éléments M-Files de la famille 5, mais la régénération de la correspondance
avait introduit une **régression silencieuse** dans un chemin pourtant listé au périmètre du secteur
(`tools/parite/corriger-correspondance-mfiles.php`, `docs/parite/correspondance.json`) :

- **Constat** : le générateur réécrivait en `absent` tout id mfiles non couvert. Le rejeu qui a posé la
  famille 5 a donc **effacé les 17 exclusions ratifiées par le juge** (`dd241cc`, décision Olivier du
  14.10.2026 : session/auth propre à M-Files, serveur/coffres, dépôts externes). Le dénominateur de la
  parité hors exclusions remontait de 95 à 112, et le rapport annonçait à tort « Exclus 0 ». Le garde-fou
  du juge (`MfilesInventaireTest`) est unidirectionnel : il interdit une exclusion NON ratifiée mais ne
  détecte pas la disparition d'une exclusion ratifiée.
- **Oracle d'abord (ROUGE)** : `tests/php/Parite/MfilesFicheVecteursTest.php` (2 tests, 93 assertions) —
  (a) la correspondance versionnée porte encore les 17 exclusions avec leur raison ; (b) le générateur,
  rejoué en bac à sable, ne les dégrade jamais. Les deux échouaient avant le code.
- **Correctif** : le générateur `PRESERVE` toute entrée déjà `exclu` (il ne la pose jamais lui-même, mais
  ne l'efface plus) ; les 17 exclusions sont restaurées à l'identique ; le résumé distingue désormais
  couverts / exclus préservés / absents. Commit `ef8423f`.
- **Mesure corrigée** : 112 éléments, couverts **67**, absents **28**, exclus **17**, non-prouvés **0**,
  parité stricte **59,82 %**, parité hors exclusions **70,53 %** (juge VERT, `MfilesInventaireTest` vert).

## Porte qualité (ECC)

- **Analyse statique** : `php vendor/bin/phpstan analyse --memory-limit=2G` → **0 erreur**, baseline vide
  inchangée ; `composer audit` sans vulnérabilité (couche 8).
- **Revue `php-reviewer`** : `declare(strict_types=1)` partout ; classes `final`, `Config` en propriété
  promue `readonly` ; méthodes courtes ; aucune duplication (le renommage réutilise `Socle\Fichiers::deplacerAvecAnnexes`
  et la table `document_deplacements`, le verrou réutilise le `.meta.json` et `document_collaboration`) ;
  écriture du `.meta.json` atomique (fichier temporaire puis renommage) ; aucune donnée réelle.
- **Revue `security-reviewer`** : les trois routes exigent `documents:modifier` et le niveau ACL du
  document (garde centrale) ; CSRF vérifié et consommé ; SQL **préparé** partout ; le nom de fichier passe
  par `Socle\Fichiers::assainirSegment` et `cheminAbsolu()` refuse `..` (pas d'évasion du fonds) ;
  l'extension du fichier est conservée (aucun changement de format) ; Twig échappe automatiquement ;
  aucun secret. **Constat CRITIQUE listé et motivé** (grille « check without lock ») : `Verrou::poser`
  lit puis écrit sans transaction ; le check-out est un **état de travail consultatif**, pas un contrôle
  d'autorisation (les droits/CSRF sont la barrière réelle) et aucune donnée ne peut être perdue (chaque
  contenu reste archivé par `DepotFichiers`) : un « dernier écrit gagne » entre deux éditeurs
  simultanés est accepté et documenté ici plutôt qu'ajouté comme verrou distinct.
- **Revue `silent-failure-hunter`** : chaque panne est **tracée** par `error_log` (base muette de
  `Historique`, lecture du verrou dans `Ui\Document`, écriture du `.meta.json`, trace de collaboration
  indisponible, échéance illisible) ; aucune `catch` vide ; les erreurs métier des routes sont **nommées**
  (étape, cause, référence) et rendues en 422, jamais un 500 muet.
- **TDD** : oracles écrits avant le code et vus rouges (« classe absente »), puis verts ; le reste du lot
  ne les affaiblit pas.
- **Passe 2 (ECC)** : le correctif du générateur passe PHPStan niveau 6 (**0 erreur**) et l'audit
  `composer` reste vierge ; l'oracle ne touche ni la base `gedv2_test` ni le fonds (bac à sable temporaire
  supprimé en `finally`), `declare(strict_types=1)`, classe `final`, aucune `catch` vide.

## Lignes hors secteur (signalées)

- `src/Kernel.php` : une ligne, le module `Fiche` (même mécanisme que `DepotFileserver`, `Permissions`).
- `src/Ui/Document.php`, `src/Ui/Ecrans.php` : nom de fichier + état du verrou passés au gabarit, repli
  neutre ; délégation de l'historique.
- `src/Securite/DroitsRoutes.php` : permissions et résolveur des routes de la fiche.
- `templates/ecrans/document.twig` : barre d'actions « Fiche & rendu » avec `data-testid`.
- `tools/banc/sonde-securite.php` : charges CSRF défensives des trois routes.
- `tools/parite/corriger-correspondance-mfiles.php` : section famille 5.

## Propositions au juge (éléments restés `absent`, aucune exclusion posée)

- GET `/structure/classes/{id}/icon` et GET `/structure/objecttypes/{type}/icon` : icônes de
  classe/type (ressource graphique de structure, pas une vignette d'objet ou de fichier). Laissées
  `absent`, proposées : GEDv2 n'a pas de magasin d'icônes de classe (les icônes de la Bibliothèque
  sont des pictogrammes de l'interface, pas des données du fonds).

ETAT: fini
