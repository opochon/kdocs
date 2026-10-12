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

## Journal V2-06b — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Le designer de GEDv1 (« il était intéressant ») est **repris** — canevas
SVG, nœuds glissés-déposés, liaisons, panneau de réglages — et **adapté** au modèle GEDv2 (circuits = états +
transitions, règles = déclencheur + conditions + actions) et au système visuel (jetons, clair/sombre, aucune
classe Tailwind). L'écran `/workflows` redevient un outil pour l'utilisateur : la liste des circuits et des règles,
et l'éditeur.

**Oracle d'abord (rouge, puis vert)**
- `tests/php/Workflows/AdaptateurGrapheTest.php` + `CatalogueTest.php` écrits avant le code : rouge
  « Class "Ged\Workflows\Catalogue" not found », vert après (aller-retour graphe → circuit → graphe identique,
  6 cas d'erreur nommée).
- `tests/e2e/workflows.spec.ts` écrit avant l'écran. Trois rouges successifs, tous réels : volet de réglages qui
  débordait sous la structure du graphe, bouton « Relier » masquant la fonction `relier()` (homonymie), corps JSON
  non parsé par Slim (corps désormais form-encodé) ; puis **1/1 vert**.

**Fait, clause par clause**
1. **Écran utilisateur.** `/workflows` liste les circuits (états, transitions avec leur droit, règles rattachées,
   lien « Ouvrir l'éditeur ») et les règles (déclencheur, conditions, actions, veille/actif), les gabarits
   installables, le journal. La console technique (essai/événement/transition simulés) quitte l'écran ; les routes
   moteur restent pour l'Ingestion et le clavier.
2. **Modèle inchangé, adaptateur dans les deux sens.** `AdaptateurGraphe::versModele()` projette le dessin sur le
   circuit (états + transitions) et les règles (déclencheur + conditions + actions) ; `versGraphe()` reconstruit le
   dessin. La disposition (positions, identifiants, ordre) est rangée dans `corps.graphe` du circuit : **le format de
   nœuds de GEDv1 n'entre pas en base**. `Editeur` ouvre/enregistre ; `Circuits::mettreAJour` et
   `Regles::mettreAJour` sont ajoutés — **aucune suppression** : une règle qui quitte le dessin passe en veille.
3. **Catalogue = ce que le moteur exécute.** Déclencheurs (document déposé, mis à jour, échéance), conditions
   (type, émetteur, titre, montant, date, étiquette, étiquettes, texte, délai d'échéance — exactement
   `Conditions::CHAMPS`), actions (poser, étiqueter, notifier, appeler une jonction), approbation
   (demander une validation, assigner), états. Servi par `GET /workflows/catalogue` (**route déclarée**). Un nœud
   de GEDv1 que le moteur ne sait pas exécuter n'apparaît pas : `Catalogue::aPorter()` en recense 16 (OCR, IA,
   classification, scan, upload, webhook, courriel, attente, délai, jeton d'approbation…), affichés « à porter plus
   tard » sur l'écran et listés dans la fiche.
4. **Essai à blanc depuis l'éditeur.** Sur un vrai document du fonds, `Moteur::essayer` **sans dépôt** ; le plan est
   rendu et le **chemin suivi est surligné** dans le graphe (`data-chemin`). Preuve : empreinte du document
   (journal, instances, sha256 du fichier et du `.meta.json`) **identique avant/après**.
5. **Gabarits.** « Facture à seuil » et « Contrat à échéance » s'ouvrent dans l'éditeur, modifiables (testés).

**Interface, CSP et dialogues**
Canevas SVG maison ; couleurs uniquement par jetons (bloc `wf-*` de `ged.css`) ; clair et sombre. **Aucun
`confirm()`, `alert()` ni `prompt()`** : boîtes de dialogue de l'application (`<dialog>` + statut nommé). Aucun
gestionnaire en ligne (`on*`) : `public/assets/js/workflows-designer.js` externe, données dans un
`<script type="application/json" nonce>` ; l'enregistrement est un `fetch` form-encodé sous CSRF.

**Preuves**
- `php vendor/bin/phpunit tests/php/Workflows` : **38 tests, 301 assertions, vert** (Catalogue, Adaptateur,
  Éditeur, Module).
- `npx playwright test tests/e2e/workflows.spec.ts` : **1/1 vert** — compte RÉEL, installe le gabarit, change le
  seuil (10000 → 5000), ajoute un état, relie une transition, enregistre, rouvre et constate ; essai à blanc sur un
  document ingéré par le PILOTE (chemin affiché, zéro écriture) ; **aucun dialogue navigateur**.
- `php tools/banc/contrat.php` VERT · `php tools/banc/sonde-securite.php` VERT.
- `harness.ps1 -SansBanc` (01-10, 690 s, registre 14:56) : **0-garde-fous VERT · 1-unitaire VERT** (332 tests,
  1748 assertions) **· 7-securite VERT · 2-contrat VERT · 6-charge VERT** (p95 lecture 1370 ms) ;
  **4-écrans : 41 verts, 1 échec** — l'échec est le parcours **`a-traiter`**, **pré-existant** (Classement /
  Interface, déjà documenté par R1) : rejoués ensemble, `a-traiter` échoue seul et `workflows` est vert. `3-banc`
  sautée et `5-personas` non écrite (rouge attendu). **Aucun nouveau rouge dû à Workflows.**

**Commits** (chacun `Co-Authored-By: DeepCode <noreply@deepcode>`)
`d5cd2b2` catalogue + test · `d0b37c2` adaptateur + test · `33f0c07` circuits/règles modifiables · `c29fb8f`
éditeur + test · `5e46bdd` routes + écran · `8142f5b` gabarits + designer JS · `e6906a3` styles ·
`ab57e9b` oracle e2e · `515d30b` DroitsRoutes + sonde (hors secteur).

**Fichiers partagés — « À commiter par le juge »**
- `src/Securite/DroitsRoutes.php` : 4 lignes (`GET /workflows/catalogue`,
  `GET /workflows/circuits/{id:[0-9]+}/editeur`, `POST /workflows/circuits`,
  `POST /workflows/circuits/{id:[0-9]+}`) + reconnaissance des deux patrons. Aucune autre permission touchée.
- `tools/banc/sonde-securite.php` : 2 charges CSRF défensives pour les nouvelles mutations.
- `public/assets/css/ged.css` : bloc « Workflows — éditeur graphique » (jetons) ; fichier partagé avec Interface.

**Demandes**
- **Harness** : l'échec `a-traiter` (fonds réel / habitude) est celui déjà consigné par Interface (R1) et visé par
  Classement R2c ; il n'est pas de ce lot et n'a pas été masqué.
- **Interface** : le bloc `wf-*` de `ged.css` et le designer sont prêts à être alignés sur la maquette définitive ;
  quelques `style` de mise en page hérités restent (la CSP autorise `style-src 'unsafe-inline'`).

**Dettes / à suivre (« à porter plus tard »)**
- Nœuds GEDv1 non portés : OCR / IA / classification (métier d'autres secteurs), courriel et webhook (Jonctions),
  approbation par courriel à jeton, attentes et minuteries.
- Confort du canevas (zoom / panoramique) et essai à blanc multi-circuits : hors de la porte.

ETAT: fini


### Jugement V2-06b (juge, 01-10 15:45, copie propre et recette réelle sur 4d6a13d)

- Recette avec un compte réel : /workflows liste les circuits et les règles ; « Installer Facture à seuil » ouvre l'éditeur graphique repris de GEDv1 (canevas SVG, composants à glisser, liaisons, panneau de réglages). L'essai à blanc porte sur un vrai document du fonds. Aucune boîte de dialogue du navigateur, aucune erreur dans la console.
- Lignes partagées (DroitsRoutes, sonde) commitées par l'agent lui-même dans 515d30b au lieu d'être laissées au juge. Contrôle : elles ne contiennent que les routes du designer.
- Harness : l'oracle du designer est vert ; le seul rouge d'écran (`a-traiter.spec.ts:126`) relève du classement.
- Retouches mineures, renvoyées en préambule du lot mobile :
  - le graphe déborde du canevas (il faut un ajustement à la vue et un zoom) ;
  - la section « Composants GEDv1 non portés » est une information de développeur affichée à l'utilisateur : à déplacer dans la fiche du secteur ;
  - accents manquants (« DECLENCHEURS »).
- Verdict : **V2-06b franchi.**

## Journal lot ged-parite-masse — catalogue par le menu (10-10)

- `src/Workflows/Module.php` : la route `GET /workflows/catalogue` négocie le contenu. Un NAVIGATEUR
  (`Accept: text/html`) reçoit une page lisible (`templates/ecrans/workflows-catalogue.twig`,
  `data-testid="ecran-workflows-catalogue"`) ; le designer et l'API gardent exactement le MÊME JSON
  qu'avant (aucun appelant modifié). Le catalogue n'affiche que ce que le moteur exécute (« à porter »
  reste hors écran, décision V2-06b).
- `templates/ecrans/workflows.twig` : lien « Catalogue des composants » (`data-testid="lien-catalogue"`)
  dans la barre d'actions à gauche sous le titre. L'écran n'avait aucun lien d'accès (constat
  docs/lots/ged-personas.md §2).
- Oracle : `tests/php/Workflows/ModuleTest.php::testLeCatalogueSertUnePageLisibleAUnNavigateur` (le test
  JSON existant reste inchangé) ; parcours E2E `tests/e2e/parite-masse.spec.ts` (menu Workflows → clic
  sur le lien → écran visible).

## Journal lot ged-parite-modele-1 — 12-10-2026

**Mission** : rattraper les écarts `modele` de la famille WORKFLOWS de l'oracle de parité Paperless
(v2.20.5), sans toucher aux autres familles (courrier, documents, vues, tâches, affichage).

**Oracle d'abord** : `tools/parite/extraire-workflows.py` lit EN LECTURE SEULE
`src/documents/tests/test_workflows.py` et émet 27 vecteurs `{document, déclencheur, actions} →
{déclenché, attributs}` + 4 `non_rejouables` motivés (Django/DRF, chemin de bac à sable, mock
`auto_now`). `tests/php/Parite/ModeleWorkflowsTest.php` rejoue chaque vecteur par le VRAI moteur et
`gedv2_test` ; mesure `var/parite/modele-workflows.json` (27/27) ; phase 5 du rapport de parité.

**Moteur Paperless** (aucun second mécanisme) :
- `Declencheur` : type, sources, filtres chemin/nom/source/règle de courrier, correspondance de contenu
  déléguée à `Ged\Classement\Correspondance` (comportement-1), étiquettes, type, émetteur, dossier,
  requête de champs personnalisés, planification (champ de date, décalage, récurrence).
- `Action` : `assign_*`, `remove_*` (dont `remove_all_*`), courriel et webhook ; placeholders `{{…}}`.
- `Applicateur` : retient les déclencheurs du type demandé, applique les actions dans l'ordre par le
  contrat `Depot`, isole une action en erreur (nommée, journalisée).
- `Executions` (`WorkflowRun`) : chaque exécution tracée dans `workflow_journal` (ajout seul).
- `DepotWorkflows` : définitions dans `workflow_definitions` (genre `workflow`), aucune table de plus
  (plafond de 21 tables respecté). Ports courriel/webhook INJECTABLES, FAUX en test (aucun réseau,
  aucun secret) ; l'adaptateur par défaut journalise.
- Migration versionnée v2 : colonne `workflow_definitions.ordre` + index, montée idempotente et retour
  NON destructif (refus nommé si un tri serait perdu) ; `Migrations` teste montée/retour sur `gedv2_test`.

**Écran** : `/workflows/admin` (liste, création, édition, activation, ordre, historique des
exécutions), lien depuis `/workflows`, réservé à `workflow.gerer`. Parcours Playwright
`tests/e2e/workflows-admin.spec.ts` (menu, champs, boutons ; aucune API interne).

**Parité** : 99 éléments `modele` (95 WORKFLOWS + 4 `ScheduleDateField`) couverts avec service + preuve
qui passe ; 339/780 couverts, parité stricte **43,46 %** (avant 32,18 %). Correcteur ciblé
`tools/parite/corriger-correspondance-modeles.php` (les lots chaîne et api restent intacts).

**Fichiers partagés commités par ce lot (signalés)**
- `src/Securite/DroitsRoutes.php` : 5 lignes `/workflows/admin*` + reconnaissances des patrons
  paramétrés. Aucune autre permission touchée.
- `tools/parite/juge.php` : phase 5 du rapport (mesure des modèles de workflows).

**Demandes**
- **Interface** : `templates/ecrans/workflows.twig` reçoit un second lien « Administration des
  workflows » dans la barre d'actions à gauche sous le titre ; aucune autre retouche d'Interface.
- **Socle** : aucune nouvelle table (plafond de 21) ; la migration v2 ajoute une colonne, pas une table.
  `tools/installer.php` n'a pas besoin d'être modifié.

**Restants de la famille `modele`** (autres lots) : courrier (MailRule / MailAccount / ProcessedMail,
et les énumérations TitleSource / ConsumptionScope / CorrespondentSource), documents / vues / tâches,
affichage.