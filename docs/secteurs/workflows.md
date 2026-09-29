# Secteur WORKFLOWS — déclencheurs, actions, états, designer

Agent : DeepCode · Juge : Claude Code (GLM est pris par V2-04) · Lot : V2-06 (après V2-03 et V2-07a)

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
Selon l'audit `GEDv1/var/audit-v2/glm-workflows-interface.md` (29-09) : s'inspirer de `app/Workflow/ExecutionEngine.php`
(machine à états start/step/resume/cancel, attentes, journal par nœud), du catalogue introspectif `NodeExecutorFactory`
(24 nœuds), de la boucle d'approbation par courriel à jeton (`WorkflowApprovalController` + `ApprovalWait`), du modèle
`approval_rules` (montant → rôle, délai) et de l'éditeur `workflow-designer.js`. Ne PAS reprendre : le double système
(linéaire + designer), le pont d'événements à FQCN mort, `WorkflowWorker`/`WorkflowScheduler` (morts). L'essai à blanc
n'a jamais existé : il est à écrire.

## Demandes
- **Au Socle** (point d'accroche, V2-06) : après avoir rangé et indexé un document, appeler le secteur
  Workflows — jamais depuis l'Ingestion directement :
  ```php
  if (class_exists(\Ged\Workflows\Ecouteur::class)) {
      \Ged\Workflows\Ecouteur::prevenir($config, 'document_depose', $id, $meta);   // ou 'document_modifie'
  }
  ```
  `Ecouteur::prevenir(Config, string $evenement, int $idDocument, array $meta, string $auteur = 'systeme')`
  ne lève **jamais** : une règle en erreur est signalée, le document continue. `$meta` = le `.meta.json`
  canonique. Appel à la fin de `DepotFichiers::deposer()` (événement `document_depose`) et de
  `mettreAJour()` (événement `document_modifie`).
- **Au Socle** (constat V2-06) : le `.meta.json` écrit au dépôt ne porte pas `id`. `Apercu\Source` et
  `Workflows\Fonds` le résolvent donc par `chemin_relatif` (lecture seule) en attendant ; l'id devrait
  être réécrit dans le `.meta.json` après l'`INSERT` (le dossier resterait auto-descriptible).
- **Aux Droits** : permissions `workflow.gerer` (règles, gabarits, essai), `workflow.executer`
  (événement de dépôt/mise à jour), `workflow.transition` (route de transition ; le droit fin
  `workflow.transition.<etat>` est vérifié par le moteur contre les permissions de la session).
  Ces trois routes sont déclarées dans `Ged\Securite\DroitsRoutes` ; le compte de test e2e a reçu
  `workflow.gerer`, `workflow.executer`, `workflow.transition`, `workflow.transition.valider`
  (modification minimale de `Securite\Authentification`, signalée ici).
- **À l'Interface** : branchement minimal de `Ui\Ecrans::workflows()` (comme `/recherche`) — le gabarit
  `templates/ecrans/workflows.twig` est réécrit par ce secteur, mêmes classes et mêmes `data-testid`
  (`ecran-workflows`, barre d'actions à gauche).
- **Au Harness** (constat V2-06) : `tests/e2e/apercu.spec.ts:18` (« première page en moins d'une seconde »)
  est fragile dès qu'un 5ᵉ fichier de specs tourne en parallèle (serveur PHP `-S` mono-requête). Mesuré :
  4/4 vert sans le fichier `workflows.spec.ts`, ~1/3 vert avec, y compris `--workers=4`. Le seuil de 1 s
  de ce test devrait être desserré (ou les parcours chronométrés sérialisés) ; ce n'est pas un défaut de
  Workflows (le parcours `workflows.spec.ts` est 2/2 vert seul).

## Journal V2-06 — 29-09-2026

**Oracle d'abord**
- `tests/php/Workflows/` écrits avant le code (21 cas) : rouge « Class Ged\Workflows\Schema not found » (21 erreurs),
  puis verts après implémentation. `tests/e2e/workflows.spec.ts` écrit avant le branchement de l'écran.

**Fait, clause par clause**
1. **Règles + circuits, un seul moteur.** `Moteur` interprète les deux niveaux : `planifier()` lit les règles du
   déclencheur, évalue `Conditions` (type, émetteur, titre, montant, date, étiquettes, texte, délai d'échéance) et
   produit un plan d'actions (poser, étiqueter, démarrer/assigner un circuit, notifier, appeler une jonction).
   `Gabarits` livre *facture à seuil* (seuil 10 000 : ≥ part chez `direction`, < chez `comptabilité`, circuit
   reçu → à valider → validé/refusé) et *contrat à échéance* (rappel à J-90 + circuit de renouvellement), installables
   d'un geste et idempotents.
2. **Essai à blanc, zéro écriture.** `essayer()` rend le plan sans écrire ; `executer()` applique **le même plan**.
   Preuve : deux documents identiques déposés dans un fonds éphémère ; l'essai à blanc laisse **empreinte du fonds ET
   empreinte de toute la base inchangées**, l'exécution réelle écrit, et `regles`/`etapes`/`erreurs` sont identiques.
3. **Transitions tracées et soumises aux droits.** `workflow_journal` (ajout seul) écrit qui, quand, depuis quel état ;
   `Moteur::transitionner()` vérifie le droit de la transition contre les permissions de la session, journalise un
   refus et laisse l'état en place. Test : `valider` passe, `refuser` (droit absent) est refusé et nommé.
4. **Une règle en erreur ne bloque jamais.** `Ecouteur::recevoir()` ne lève jamais (ni au planning — action/circuit
   inconnus —, ni à l'application — dépôt indisponible), journalise l'erreur et laisse les autres règles agir ;
   il résiste même à l'absence des tables.
5. **Écran + Playwright.** `/workflows` branché sur les vrais workflows (branche minimale dans `Ui\Ecrans`, comme
   `/recherche`) : gabarits, règles activables, circuits, essai à blanc, événement de dépôt, transition, journal.
   Parcours Playwright (connexion `tests/e2e/connexion.ts`, clavier) : créer une règle → l'essayer à blanc →
   l'activer → la voir agir sur un dépôt → installer le gabarit et lire son circuit.
6. **Horloge injectable.** `Horloge` (système / figée) traverse le moteur ; les tests figent l'instant, le délai
   J-90 se compte en jours de calendrier et l'horodatage du journal vient de l'horloge injectée.

**Preuves**
- `phpunit tests/php/Workflows` : **21 tests, 106 assertions, vert** (GabaritsTest, MoteurTest, TransitionsTest,
  ModuleTest, SupportWorkflows).
- Empreintes : fonds (chemins + sha256) et base entière (toutes tables, sha256) identiques après l'essai à blanc ;
  différentes après l'exécution réelle.
- `npx playwright test tests/e2e/workflows.spec.ts` : **1/1 vert**, idempotent (nom de règle unique).
- Installateur : table count passe de 16 à 19 (≤ 20), `Ged\Workflows\Schema::installer` appelé par `tools/installer.php`.
- `harness.ps1 -SansBanc`, premier rejeu : couche 0 VERT ; 1-unitaire ROUGE (erreurs `Ged\Socle\Nomenclature` :
  travaux V2-04 d'un autre agent, hors secteur) ; 4-écrans ROUGE intermittent sur `apercu.spec.ts:18` (seuil 1 s
  sous charge parallèle, voir Demande au Harness) ; 3-banc sautée, 2/5/6/7 non écrites.
- `harness.ps1 -SansBanc`, dernier rejeu : le vestiaire contenait des modifications **non commitées d'un autre
  secteur** (`src/Securite/GardeCentrale|ConnexionController|RegistreSessions`, en-tête CSP) qui cassent 10 tests
  d'écrans (`maquettes`, `apercu`, `recherche`) sur des violations d'inline-script/inline-style — sans rapport avec
  Workflows ; le parcours `workflows.spec.ts` reste vert. Couche 1 : 207 tests, 29 erreurs, 3 avertissements, tous
  issus des travaux Socle en cours.

**Commits**
- `f1703fe` — moteur, schéma, gabarits, écouteur, écran, routes (DroitsRoutes + compte de test e2e).
- `524617a` — parcours Playwright au clavier.

**Dettes / à suivre**
- Brancher l'appel `Ecouteur::prevenir` dans `DepotFichiers` (demande déposée au Socle) : aujourd'hui le point
  d'accroche est exposé, testé et appelable, mais pas encore câblé dans le rangement.
- Écrire l'`id` dans le `.meta.json` (Socle) pour supprimer le repli SQL de `Workflows\Fonds`.
- Éditeur graphique (designer SVG) et approbation par courriel à jeton : non repris de GEDv1 dans ce lot.

ETAT: fini

## Jugement V2-06 (Claude Code, 29-09) — porte franchie
Rejoué sur le code commité (copie de travail du juge) : unitaire 176/176, bancs verts, écrans 26/26 dont le parcours workflows au clavier. Essai à blanc prouvé par empreintes du fonds et de la base. Les rouges du journal venaient du vestiaire partagé (en-tête CSP non commité du secteur Droits), pas de ce lot. Reste : brancher `Ecouteur::prevenir` dans `DepotFichiers` (Socle), designer SVG et approbation par courriel (lot suivant).
ETAT: fini (jugé)

## Note reçue de ARCHIVAGE (V2-09, DeepCode, 29-09) — les actions du moteur n'écrivent pas un document scellé
Les actions `poser` / `etiqueter` du moteur passent déjà par `Ged\Contrats\Depot::mettreAJour()` : la garde
du secteur Archivage est donc branchée dans `DepotFichiers::mettreAJour()` et une règle qui viserait un
document scellé échoue **nommée** (le moteur la rattrape et la journalise, le document n'est pas touché).
Aucune modification de ce secteur n'est requise ; test de non-régression côté Archivage (`GardeTest`).

