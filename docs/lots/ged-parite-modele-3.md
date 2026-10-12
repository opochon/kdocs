# Lot ged-parite-modele-3 — reste de `modele` et petites catégories voisines

Base : `ged-parite-modele-2` fusionné (`4800682`) : 780 éléments, 435 couverts, parité stricte 55,77 %.
Cible : le RESTE de la catégorie `modele` (champs du document, vues enregistrées `SavedView`/`DisplayFields`/
`DisplayMode`, champs personnalisés `FieldDataType`/`CustomFieldInstance`, `MatchingModel`, `Tag`,
`ModelWithOwner`, `UiSettings`, `ShareLink`, `PaperlessTask`/`TaskName`/`TaskType`) et les catégories
voisines `permissions` (4), `masse` (3), `correspondance` (4), `commande` (6), `chaine` (5).

Secteur : Socle. Les éléments relevant d'un autre secteur sont couverts par un équivalent EXISTANT
(Securite, Ingestion, Classement, Apercu) sans modifier ces secteurs ; les deux lignes partagées
réellement touchées (`src/Securite/DroitsRoutes.php`, `templates/ecrans/application.twig`) sont
signalées dans `docs/secteurs/socle.md`, rubrique « Demandes ».

Méthode oracle d'abord, par sous-famille : vecteurs en LECTURE SEULE des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5) → rejeu PHPUnit contre le vrai service et `gedv2_test` → rouge →
implémentation → vert → commit par sous-famille.

## Fait (commits)

- `e865fe3` — **oracle** : `tools/parite/extraire-modele-reste.py` (lecture seule) et 64 vecteurs commis
  (`tests/parite/vecteurs/modele-reste.json` ; familles document, matching, vues enregistrées, champs,
  tâches, permissions, masse, commandes, chaîne ; 3 traits Django/Celery/Tika listés `non_rejouables`).
- `d2655b3` — **reste du modèle** : `Fichiers::nomPublic`/`extensionSupportee`, natures `url`/`lien` et
  validation par valeur (`Champs::validerValeur`), réglages de vue (`Vues`), `Etiquettes` (couleur +
  boîte de réception), `Preferences` (UiSettings), `Matching` (MatchingModel), `Taches` (PaperlessTask
  sans Celery), `ModeleDroits`, action masse `proprietaire`, version partagée (`Collaboration`) et
  migration versionnée non destructive (`Socle\Migrations`). Rejeu `ModeleResteTest` (64/64) +
  `ModelesResteTest` (8) + `MigrationModeleTest` (4).
- `68c360e` — **écrans** : `/vues` (créer, nommer, champs affichés, mode, taille de page, tri, tableau de
  bord / menu) et `/taches` (statut, horodatage, résultat, acquittement, reflet de la file d'ingestion),
  étiquettes et natures url/lien sur `/metadonnees` ; parcours Playwright `taches.spec.ts` et
  `vues.spec.ts` (menus, liens, boutons réels).
- `121afe2` — **parité** : `correspondance.json` (94 éléments du lot), phase 7 de `tools/parite/juge.php`,
  rapport `PARITE-PAPERLESS.md`, journal et demandes dans `docs/secteurs/socle.md`.

## Mesures exactes

### Parité (`recette/parite-paperless.json`, régénéré par `php tools/parite/juge.php`)

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 435 / 780 | **529 / 780** |
| Parité stricte | 55,77 % | **67,82 %** |
| Parité hors exclusions | 59,43 % | **72,17 %** |
| `modele` couverts | 233 / 313 | **310 / 313** |
| `permissions` couverts | 8 / 12 | **11 / 12** |
| `masse` couverts | 14 / 17 | **17 / 17** |
| `correspondance` couverts | 9 / 13 | **13 / 13** |
| `commande` couverts | 9 / 17 | **13 / 17** |
| `chaine` couverts | 55 / 60 | **57 / 60** |
| Non prouvés | 0 | **0** |

Erreurs du juge : aucune. Inventaire rejoué à l'identique.

### Comportement rejoué (phase 7 du rapport)

| Famille | Passes | Total | Taux |
| --- | ---: | ---: | ---: |
| document | 4 | 4 | 100,00 % |
| matching | 22 | 22 | 100,00 % |
| savedview | 3 | 3 | 100,00 % |
| champs | 10 | 10 | 100,00 % |
| tache | 16 | 16 | 100,00 % |
| permissions | 2 | 2 | 100,00 % |
| masse | 2 | 2 | 100,00 % |
| commande | 2 | 2 | 100,00 % |
| chaine | 3 | 3 | 100,00 % |
| **total** | **64** | **64** | **100,00 %** |

3 non rejouables : états Celery/Redis, chiffrement du fonds (absent de GEDv2), Tika (service Java
externe). Références : `src/documents/tests/test_api_tasks.py`, `src/documents/management/commands/`,
`src/paperless_tika/parsers.py`.

## Restants avec raison et référence (écarts assumés)

| Élément | Raison | Référence Paperless |
| --- | --- | --- |
| `modele:champ:Document.page_count` | GEDv2 ne calcule pas le nombre de pages ; la pagination est portée par l'origine d'ingestion (`origine.pages`). | `src/documents/models.py` |
| `modele:champ:Document.archive_serial_number` | Pas d'ASN : le scellement légal porte un jeton TSA. | `src/documents/models.py` |
| `modele:champ:Document.storage_type` | Pas de type de stockage chiffré : le fonds reste lisible sans l'application. | `src/documents/models.py` |
| `commande:documents:decrypt_documents` | GEDv2 ne chiffre pas le fonds. | `src/documents/management/commands/` |
| `commande:documents:prune_audit_logs` | Journal d'audit en ajout seul, aucune purge automatique. | `src/documents/management/commands/` |
| `chaine:parseur:TikaDocumentParser.parse` `extract_metadata` `get_thumbnail` | Tika est un service Java externe ; vignettes et extraction par les parseurs GEDv2. | `src/paperless_tika/parsers.py` |

Les catégories `api` (179 absents) et `ecran` (17 absents) restent pour les lots suivants.

## Écrans touchés et flux F à retester

- `/application` : deux liens d'administration (Suivi des traitements, Vues enregistrées).
- `/vues`, `/taches` : nouveaux écrans (parcours Playwright dédiés).
- `/metadonnees` : section Étiquettes et natures url/lien.
- Flux F3 (courriel entrant) et F4 (dépôt) inchangés ; la file d'ingestion est lue, jamais modifiée.

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` vide inchangée. `composer audit` : à confirmer par la couche 8 du harness.
- **TDD** : rejeu d'abord vu rouge (`ModeleResteTest` : `Fichiers::nomPublic`, `Vues::compilerReglages`,
  `Champs::validerValeur`, `Taches`, `ModeleDroits` absents) puis vert ; vecteurs commis non modifiés.
- **Revue du diff** (php-reviewer / security-reviewer / silent-failure-hunter) :
  - Injection : requêtes préparées ; seules concaténations SQL sont des noms de tables/colonnes d'une
    liste interne figée (`document_versions`, `document_deplacements`, listes de colonnes) ;
  - Erreurs : toute valeur refusée lève une erreur nommée (nature, opérateur, couleur, version) ; les
    écrans rattrapent `RuntimeException` et rendent l'étape et la cause ; aucun `catch (Throwable)` qui avale ;
  - DDL : `Schema::installer` n'est appelé que par les outils CLI et les amorces de test ; la migration
    passe par `Schema::assurerTablesBase` / `Schema::assurerColonneVersionPartage` hors HTTP ; le retour
    refuse de perdre un partage versionné ;
  - Données : les écrans et services n'effacent rien (tâches en ajout seul, étiquettes renommées/recolorées,
    vues modifiées) ;
  - Sans Celery/Redis : le suivi est un journal dans le fonds, testé sans démon.

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

Rejeu du 2026-10-12 sur l'arbre commité (couches 0-9). `tools/composer.phar` (outil gitignoré, absent de
ce worktree) restauré depuis la copie de référence avant le rejeu. Détail dans `var/harness/` et
`recette/registre.json`.

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 826 tests, 10 884 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | ROUGE | 1 échec sous charge machine — voir ci-dessous |
| 7-sécurité | VERT | sonde offensive : aucun contournement accepté (charges CSRF du lot incluses) |
| 2-contrat | VERT | toutes les routes déclarées couvertes (dont `/vues`, `/taches`, `/metadonnees/etiquette`) |
| 6-charge | VERT | p95 lecture 890 ms, p95 écriture 309 ms, 0 erreur, 0 collision, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 382–681 ms) |
| 9-parité | VERT | 780 éléments, 529 couverts, strict 67,82 %, hors exclusions 72,17 %, 0 non prouvé |

**Couche 4 (écrans)** : la machine héberge d'autres worktrees dont les suites tournent en parallèle
(processus `k-time` et `f-suite-isolation` visibles au même moment). Les trois rejeux du harness ont
échoué sur un spec lourd **différent** à chaque fois, jamais sur les specs du lot :
`budget-apercu` (1043 ms puis 1056 ms pour une porte à < 1000 ms — la première page pdf.js côté
navigateur), puis `edition-pages` (délai de 90 s dépassé). La MÊME commande
`npx playwright test --project=parcours --project=budgets` rejouée seule sur une machine au repos donne
**84 passés (4,9 m)**, dont les parcours du lot `taches.spec.ts` et `vues.spec.ts`, et `budget-apercu`
vert. Aucun test n'a été affaibli, aucune sonde retirée, la porte de budget est inchangée.


ETAT: fini
