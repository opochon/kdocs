# Lot ged-mfiles-fiche-1 — Famille M-Files nº 5 : fiche document et son rendu

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3307).
Branche : `ged/ged-mfiles-fiche-1` · point de départ : `origin/v2`.

## Objectif

Porter la **famille M-Files nº 5** — aperçu/vignette d'un objet et d'un fichier, titre d'objet et de
fichier, historique des versions, commentaires, statut de verrouillage / check-out — en faisant passer
les éléments `mfiles:*` correspondants de `absent` à `couvert` / `couvert-autrement`, prouvés par des
tests rejoués, sans toucher `src/Parite/Juge.php`, la liste fermée d'exclusions, ni les statuts/vecteurs
Paperless.

Les cinq familles visées (11 éléments `mfiles:rest:*`) :

| Famille | Éléments M-Files |
| --- | --- |
| Aperçu / vignette | GET `…/preview` (objet), GET `…/files/{file}/preview` (fichier) |
| Titre | GET/PUT `…/title` (objet), GET/PUT `…/files/{file}/title` (fichier) |
| Historique | GET `…/history` |
| Commentaires | GET/PUT `…/comments` |
| Check-out | GET/PUT `…/checkedout` |

Les icônes de classe/type (`/structure/classes/{id}/icon`, `/structure/objecttypes/{type}/icon`) ne sont
**pas** des vignettes d'objet ou de fichier : laissées `absent`, **proposées** au juge (aucune exclusion
posée par l'agent).

## État d'avancement

### Fait (commité)

- `7cdf144` — cadrage du secteur `docs/secteurs/fiche-rendu.md` (chemins, demandes, journal).
- **Historique des versions** : service `Ged\Fiche\Historique` (versions archivées + déplacements, du plus
  récent au plus ancien, lus du fonds ; base muette tracée, liste vide jamais 500) ; `Ui\Document::historique`
  lui délègue. Oracle rouge d'abord (2 erreurs « classe absente »), puis vert : **2 tests, 10 assertions**.
- **Titre d'objet / nom de fichier** : service `Ged\Fiche\Nom` (lire le nom rangé, renommer le fichier
  réel — fichier, `.meta.json` et `.versions/` suivent, index et trace `renommage`, titre d'objet intact,
  extension conservée, nom vide refusé, droits `modifier` exigés) ; oracles `NomTest` (3 tests) et
  `TitreTest` (2 tests : le titre d'objet se lit/écrit dans le `.meta.json` sans renommer le fichier).
- **Verrouillage / check-out** : service `Ged\Fiche\Verrou` (état dans le `.meta.json`, délai de 30 min
  prolongeable, expiration qui libère, refus par un autre compte, libération forcée administrateur,
  gestes tracés dans `document_collaboration`). Oracle rouge d'abord (3 erreurs « classe absente »),
  puis vert : **3 tests, 19 assertions**.
- **Interface de la fiche & rendu** : routes `Ged\Fiche\Module` (`POST /documents/{id}/titre`,
  `/nom-fichier`, `/verrou`), permission `documents:modifier` et niveau ACL, une ligne Kernel signalée,
  `Ui\Document`/`Ui\Ecrans` passent le nom de fichier et l'état du verrou, barre d'actions de la fiche
  avec `data-testid`, charge CSRF défensive de la sonde. `ModuleTest` : **5 tests, 26 assertions**.

### Reste

- _(aucun)_ — rapport de lot complet : `docs/rapports/lots/ged-mfiles-fiche-1.md`.

## Mesures exactes

### Parité M-Files (juge `tools/parite/juge-mfiles.php`)

| Mesure | Valeur |
| --- | --- |
| Inventaire public M-Files rejoué à l'identique | oui (112 éléments) |
| Couverts (avant lot → après lot) | **56 → 67** |
| Absents (proposés au juge) | **45** |
| Non prouvés | **0** |
| Parité stricte (= hors exclusions) | **50,00 % → 59,82 %** |
| Erreurs du juge | 0 |

Les 11 éléments `mfiles:rest:*` passés de `absent` à couvert sont détaillés dans le rapport de lot
(`docs/rapports/lots/ged-mfiles-fiche-1.md`).

### Tests

| Mesure | Valeur |
| --- | --- |
| Dont `tests/php/Fiche` (5 fichiers) | **15 tests, 81 assertions** |
| PHPStan niveau 6 | **0 erreur** (baseline vide) |

### Harness `-SansBanc` (commit `a3df142`, durée 813 s)

| Couche | État | Détail |
| --- | --- | --- |
| 0 garde-fous | VERT | vendor + node_modules présents ; `DB_NAME=gedv2_test` imposé |
| 1 unitaire | ROUGE | 970 tests, 17 952 assertions, 8 erreurs / 9 échecs **d'environnement** (PyMuPDF/`CMD4_PYTHON` absents, service Infomaniak, ordre `V212cTest`) — aucun dans les chemins du lot |
| 3 banc | ROUGE | sautée (`-SansBanc`), par construction |
| 4 écrans | ROUGE | 96 passés, 2 échoués (scripts de préparation exigeant le moteur PDF absent) |
| 7 sécurité | VERT | aucun contournement accepté (charges CSRF des trois routes de la fiche) |
| 2 contrat | VERT | toutes les routes déclarées couvertes |
| 6 charge | ROUGE | `proc_open CreateProcess failed` (environnement) |
| 8 statique | VERT | PHPStan 0 erreur ; `composer audit` : aucune vulnérabilité |
| 5 personas | VERT | administrateur 4/4, auditeur 4/4, comptable 4/4, débutant 4/4, fiduciaire 4/4, secrétaire 3/3 |
| 9 parité Paperless | VERT | 780 éléments, couverts 731, strict 93,72 %, hors exclusions 99,19 % |
| 9b parité M-Files | VERT | 112 éléments, couverts **67**, strict **59,82 %**, écarts 45 |

## Porte qualité (ECC)

Voir le rapport de lot complet. En bref : PHPStan niveau 6 **0 erreur** (baseline vide) et `composer audit`
sans vulnérabilité ; revues `php-reviewer`, `security-reviewer`, `silent-failure-hunter` appliquées ;
le seul constat CRITIQUE de la grille sécurité (« check without lock » sur `Verrou::poser`) est **listé et
motivé** (état de travail consultatif, pas une barrière d'autorisation ; aucune perte possible, le contenu
étant toujours archivé) ; toutes les autres pannes sont tracées, aucune `catch` vide. TDD : oracles rouges
avant le code, puis verts.

ETAT: fini


