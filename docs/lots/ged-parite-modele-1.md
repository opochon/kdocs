# Lot ged-parite-modele-1 — rattraper les écarts `modele` de la famille WORKFLOWS

Base : `ged-parite-api-1` fusionné (`25fecff`) : 780 éléments, 251 couverts, parité stricte 32,18 %.
Cible : les 95 éléments `modele` de la famille Workflows (`Workflow`, `WorkflowTrigger`,
`WorkflowAction` et ses variantes courriel/webhook, `WorkflowRun`, énumérations associées), les autres
familles du modèle (courrier, documents, vues, tâches, affichage) restant à des lots suivants.

Méthode oracle d'abord : vecteurs de comportement extraits en LECTURE SEULE des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5) → rejeu PHPUnit contre le VRAI moteur Workflows de GEDv2 et
`gedv2_test` → rouge → implémentation → vert → commit par sous-famille.

## Fait (commits)

- `6f06cdd` — **déclencheurs + migration** : `Declencheur` (type, sources, chemin/nom/source/règle de
  courrier, correspondance de contenu déléguée à `Ged\Classement\Correspondance`, étiquettes, type,
  émetteur, dossier, requête de champs personnalisés, planification) ; migration versionnée v2
  (`workflow_definitions.ordre` + index), montée idempotente et retour non destructif testés sur
  `gedv2_test`.
- `8cebb60` — **actions et ports** : `Action` (assign_*, remove_* et remove_all_*, courriel, webhook),
  ports courriel/webhook INJECTABLES (faux en test, adaptateur par défaut journalisé, aucun secret) ;
  `Fonds` expose nom d'origine, dossier, propriétaire, droits, champs et dates du `.meta.json`.
- `c8b3d47` — **exécutions et application** : `DepotWorkflows` (genre `workflow`, aucune table de plus),
  `Executions` (`WorkflowRun` tracé dans `workflow_journal`, ajout seul), `Applicateur` (retient les
  déclencheurs du type demandé, applique dans l'ordre par le contrat `Depot`, isole une action en erreur).
- `e16bd00` — **oracle de parité** : `tools/parite/extraire-workflows.py` (lecture seule) +
  `tests/parite/vecteurs/modele-workflows.json` (27 vecteurs, 4 non rejouables motivés) +
  `tests/php/Parite/ModeleWorkflowsTest.php` (rejeu vrai moteur / `gedv2_test`, mesure écrite).
- `fa6afbb` — **écran d'administration** : `/workflows/admin` (liste, création, édition, activation,
  ordre, historique des exécutions), réservé à `workflow.gerer`, parcours Playwright
  `tests/e2e/workflows-admin.spec.ts`. `src/Securite/DroitsRoutes.php` (lignes partagées signalées).
- `4d29189` — **parité + qualité** : `correspondance.json` (99 éléments couverts), phase 5 du rapport,
  correcteur ciblé, PHPStan niveau 6 vert (baseline vide), oracle V2-13x respecté.

## Mesures exactes

### Parité (`recette/parite-paperless.json`, régénéré par le juge)

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 251 / 780 | **339 / 780** |
| Parité stricte | 32,18 % | **43,46 %** |
| Parité hors exclusions | 34,29 % | **46,31 %** |
| `modele` Workflows couverts | 10 / 95 | **95 / 95** |
| `modele` ScheduleDateField couverts | 1 / 4 | **4 / 4** |

### Comportement rejoué (phase 5 du rapport)

| Famille | Passes | Total | Non rejouables | Taux |
| --- | ---: | ---: | ---: | ---: |
| déclencheurs | 16 | 16 | — | 100,00 % |
| affectation | 1 | 1 | — | 100,00 % |
| retrait | 2 | 2 | — | 100,00 % |
| courriel-webhook | 2 | 2 | — | 100,00 % |
| planification | 6 | 6 | — | 100,00 % |
| **total** | **27** | **27** | **4** | **100,00 %** |

Les 4 non rejouables : `test_workflow_fnmatch_path` (chemin de bac à sable Django), trois cas
Django/DRF (`new_trigger_type`, serializer de consommation, `auto_now` mocké). Référence :
`src/documents/tests/test_workflows.py`.

### PHPUnit

789 tests, 10 300 assertions, 1 sauté (préexistant), 0 échec. Nouveaux : `MigrationTest` (3),
`DeclencheurTest` (~30), `ActionTest` (~40), `ApplicateurTest` (7), `ModeleWorkflowsTest` (1),
`ModuleTest::testLAdminCreeEditeActiveEtMontreLHistorique`.

## Restants de la famille `modele` (raison + référence)

| Famille d'écarts | Raison | Référence Paperless |
| --- | --- | --- |
| Courrier : `MailRule`, `MailAccount`, `ProcessedMail` et leurs énumérations (`TitleSource`, `ConsumptionScope`, `CorrespondentSource`) | Modèle absent de GEDv2 (lecture IMAP par `Ingestion\Imap\ImapBoite`, pas d'entités compte/règle/courrier traité). Lot suivant. | `src/paperless_mail/models.py` |
| Documents, vues enregistrées, tâches, champs personnalisés listables | Modèle partiel ; lots suivants. | `src/documents/models.py` |
| Affichage (`SavedView`, `UiSettings`, `ApplicationConfiguration`) | Modèle absent ; lot suivant. | `src/documents/models.py` |

## Écrans impactés et flux F à retester

- `/workflows` : nouveau lien « Administration des workflows » (barre d'actions) — flux F11 (règles).
- `/workflows/admin` et `/workflows/admin/{id}` : écran d'administration — parcours Playwright dédié.
- Aucun écran existant n'est modifié hors l'ajout du lien : flux F1–F10 inchangés (harness complet).

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → **0 erreur, niveau 6**,
  `phpstan-baseline.neon` vide inchangée. Corrections de typage sur les docblocks (`@return` sur lignes
  séparées), `Admin` sans dépendance morte, `array_values` retiré des listes déjà typées.
- **TDD** : oracle rouge avant le code (vecteurs commités, rejeu lancé rouge : 3 écarts,
  implémentation/entrée ajustées) ; aucun test affaibli, aucun vecteur modifié après commit.
- **Revue du diff** (php-reviewer / security-reviewer / silent-failure-hunter) : aucun constat CRITIQUE
  ou ÉLEVÉ.
  - Injection : toutes les requêtes sont préparées (`?`) ; seules concaténations SQL sont des noms de
    tables d'une liste interne figée et un `LIMIT` borné par `int`.
  - Erreurs : une action qui échoue est nommée et journalisée, jamais avalée ; une migration de retour
    qui perdrait un tri est refusée par un message nommé ; aucun `catch (Throwable)` qui avale.
  - SSRF : le port webhook n'émet aucun appel réseau dans le lot (adaptateur par défaut journalisé) ;
    l'URL vient d'un administrateur, jamais d'un document.
  - Secrets : aucun ; ports injectables.
  - DDL : l'installation/migration n'est appelée que par les outils CLI et les amorces de test (oracle
    V2-13x respecté : aucun `Schema::installer` hors `Schema.php`).

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

Rejeu du 2026-10-12. Détail dans `var/harness-modele-1.log` et `recette/registre.json`.

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | à confirmer au rejeu | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT (ciblé) | 789 tests, 10 300 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | à confirmer au rejeu | parcours `workflows-admin.spec.ts` vert seul ; `workflows.spec.ts` inchangé |
| 7-sécurité | à confirmer au rejeu | sonde offensive |
| 2-contrat | à confirmer au rejeu | 5 routes `/workflows/admin*` déclarées |
| 6-charge | à confirmer au rejeu | — |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur |
| 5-personas | à confirmer au rejeu | geste Workflows inchangé |
| 9-parité | VERT | 780 éléments, 339 couverts, strict 43,46 %, 0 non prouvé |

ETAT: en cours
