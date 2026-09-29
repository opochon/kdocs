# Secteur WORKFLOWS — déclencheurs, actions, états, designer

Agent : GLM · Juge : DeepCode · Lot : V2-06 (après V2-03 et V2-07a)

## Mission
Deux niveaux, un seul moteur :
- **Règles** (Paperless) : un déclencheur (document reçu, ajouté, modifié, échéance planifiée) + des conditions
  (type, émetteur, étiquette, montant, texte) → des actions (poser type/émetteur/étiquettes/champs, assigner, notifier,
  demander une validation, appeler une jonction). Couvre 80 % des besoins sans dessin.
- **Circuits à états** (M-Files) : un document traverse des états (reçu → à valider → validé → payé / refusé), chaque
  transition a ses droits et sa trace. Gabarits livrés : *facture à seuil* (montant → rôle qui valide) et
  *contrat à échéance* (rappel avant la date de résiliation).
- **Designer** : les deux se dessinent au même endroit ; **essai à blanc** sur un vrai document du fonds, qui montre ce
  qui se passerait sans rien écrire.

## Chemins
`src/Workflows/`, `templates/ecrans/workflows*.twig`, `tests/php/Workflows/`, `tests/e2e/workflows*.spec.ts`

## Lot V2-06 — porte
1. Les deux gabarits s'installent d'un geste et tournent sur le corpus : une facture de 12 000 part chez le rôle
   « direction », une de 300 chez « comptabilité » ; un contrat à échéance produit son rappel à J-90.
2. Essai à blanc : même résultat que l'exécution réelle, **zéro écriture** (vérifié par empreinte du fonds et de la base).
3. Toute transition est tracée (qui, quand, depuis quel état) et refusée sans le droit du secteur Droits.
4. Une règle en erreur ne bloque jamais l'ingestion : elle est signalée, le document continue.
5. Playwright : créer une règle, la tester à blanc, l'activer, la voir agir sur un dépôt — au clavier.

## Reprendre de GEDv1
`WorkflowEngine`, `approval_rules`, designer `/admin/workflows/*/designer` — à trier selon l'audit
`GEDv1/var/audit-v2/glm-workflows-interface.md` (en cours) : ne reprendre que ce qu'un test exécute.

## Demandes
- Au Socle : événement « document déposé / mis à jour » (point d'accroche unique, pas d'appel direct depuis l'ingestion).
- Aux Droits : permissions `workflow.gerer`, `workflow.transition.<etat>`.
