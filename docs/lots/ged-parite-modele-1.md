# Lot ged-parite-modele-1 — rattraper les écarts `modele` de la famille WORKFLOWS

Base : `ged-parite-api-1` fusionné (`25fecff`) : 780 éléments, 251 couverts, parité stricte 32,18 %.
Cible : les 95 éléments `modele` de la famille Workflows (statut `absent` pour 85, `couvert-autrement`
pour 10) : `Workflow`, `WorkflowTrigger`, `WorkflowAction`, `WorkflowActionEmail`,
`WorkflowActionWebhook`, `WorkflowRun`, leurs champs, et les énumérations associées
(`WorkflowTriggerType`, `WorkflowTriggerMatching`, `WorkflowActionType`, `ScheduleDateField`).

Méthode oracle d'abord : vecteurs de comportement extraits en LECTURE SEULE des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5) → rejeu PHPUnit contre le VRAI moteur Workflows de GEDv2 et
`gedv2_test` → rouge → implémentation → vert → commit par sous-famille.

## Fait (commits)

- (en cours)

## En cours

- Reconnaissance : moteur Workflows V2-06b lu, modèle Paperless (models.py 980-1600, matching.py,
  workflows/actions.py, workflows/mutations.py) lu, infra de parité (tools/parite/juge.php,
  src/Parite/Juge.php) lue.

## Reste

- Vecteurs `tests/parite/vecteurs/modele-workflows.json` + extracteur `tools/parite/extraire-workflows.py`.
- Rejeu PHPUnit (`tests/php/Parite/ModeleWorkflowsTest.php`) rouge, puis implémentation.
- Moteur : déclencheurs, actions d'affectation, actions de retrait, courriel/webhook, planification.
- Migration versionnée `ordre` (montée/retour testés sur `gedv2_test`).
- Écran d'administration (liste, création, édition, activation, ordre, historique) + E2E.
- `docs/parite/correspondance.json`, `PARITE-PAPERLESS.md`, couche 9.
- Porte qualité (PHPStan, revue ECC), harness `-SansBanc` vert.

## Mesures exactes

- (à compléter)
