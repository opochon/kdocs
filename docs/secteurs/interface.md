# Secteur INTERFACE — écrans, système visuel, mobile

Agent : GLM (sur maquettes validées) · Juge : Claude Code (personas) · Lot en cours : MAQUETTES → validation d'Olivier

## Mission
Six écrans maîtres (À traiter, Bibliothèque, Recherche, Fiche document, Workflows, Application/Administration), servis par
des gabarits Twig et un seul système visuel (`public/assets/css/ged.css`, jetons en tête). Tout autre écran est une
variante de l'un d'eux. Pratique, rapide, beau : compact, clavier d'abord, clair et sombre.

## Chemins
`templates/`, `public/assets/`, `src/Ui/`, `tests/e2e/`

## Règles (chacune a son test dans `tests/e2e/`)
Barre d'actions à gauche sous le titre ; une seule source de menu ; aucun défilement horizontal ; chaque élément actionnable
a un `data-testid` ; aucun écran > 1,5 s ; 0 erreur console ; erreurs nommées ; dossier d'ingestion jamais montré ;
couleurs uniquement par jetons ; le mobile a ses propres écrans.

## État au 29-09
Lot MAQUETTES fait : six écrans sur données fixes (`src/Ui/Maquette.php`), 12 tests Playwright verts, captures claires et
sombres dans `var/captures/`. En attente : la validation d'Olivier, écran par écran. Ensuite chaque secteur branche ses
vraies données sans changer le gabarit.

## Demande reçue de ARCHIVAGE (V2-09, DeepCode, 29-09)
Deux branchements à prévoir quand le Kernel appellera `Archivage\Module` (voir demandes du secteur) :
- sur la **fiche document**, un bouton « Sceller » (`data-testid="document-sceller"`), barre d'actions à
  gauche sous le titre, qui appelle `POST /documents/{id}/sceller` (permission `archivage:sceller`) ;
- une **liste de rétention** (« à échéance ») servie par `Ged\Archivage\Retention::aEcheance()`, avec le
  geste humain « Lever le scellement » (`Retention::lever()`) et son échéance affichée — jamais
  d'effacement automatique, le refus d'écriture se nomme (« scellé jusqu'au … »).

## Journal V2-03-écran — 29-09-2026

**Fait**
- `Ged\Ui\ATraiter` : l'écran « À traiter » lit le FONDS réel (via `Ged\Socle\DepotFichiers`, le dossier fait
  foi : une ligne d'index dont le fichier a disparu est ignorée). Bilan = classés automatiquement / à
  contrôler (statut des documents vivants) + en cours de lecture (travaux `recu|decoupe|pret`) ; file des
  `a_controler` du plus ancien au plus récent ; proposition du Classeur telle qu'écrite à l'ingestion
  (`source` IA/habitude/règle, `raison`, `preuve`) ; aperçu réel par la macro `visionneuse.moteur` du
  secteur Aperçu ; types lus dans la nomenclature (`Nomenclature`), repli maquette.
- `Ui\Ecrans::aTraiter` branché sur l'adaptateur ; **le gabarit garde ses classes, ses data-testid et sa
  disposition** : seuls sont ajoutés les `name` des champs, le champ caché `csrf` et l'action POST.
- `Ui\Module` : `POST /a-traiter/{id:[0-9]+}/valider` (permission `documents:traiter`). Valider (Entrée)
  écrit par `Ged\Contrats\Depot::mettreAJour` (statut `valide`, rangement recalculé, trace) puis enseigne
  l'habitude par `Ged\Classement\Habitudes::retenirChoix` — **choix humain seulement** ; Passer (→) va au
  suivant. Tout au clavier (`public/assets/js/ged.js`).
- `templates/partiels/visionneuse.twig` : l'`import` d'icônes est porté DANS la macro `moteur` (une macro
  importée n'exécute pas le corps du gabarit, `ic` manquait à l'import). Correctif d'une ligne, chemin du
  secteur Interface (`templates/`) ; signalé à Recherche & aperçu.

**Preuves**
- Oracle d'abord : `tests/e2e/a-traiter.spec.ts` écrit rouge (l'écran rendait encore les données fixes),
  puis vert. Fonds déposé par `tests/oracles/interface/preparer-a-traiter.php` via `DepotFichiers` dans
  `var/oracles/interface/` (**jamais dans `tests/corpus`**) ; parcours connecté (`tests/e2e/connexion.ts`)
  sur un serveur isolé, **trois validations à Entrée sans souris**, puis
  `tests/oracles/interface/classer-piece-4.php` rejoue le vrai classeur (IA inerte) : la 4ᵉ pièce du même
  émetteur arrive en source **« habitude »**, `valide`. 3 parcours verts (dont « Passer » (→) qui va au
  suivant sans rien écrire).
- `vendor/bin/phpunit` : **241 tests, 1073 assertions** verts (dont `SecuriteTest` qui vérifie que la
  nouvelle route déclare sa permission).
- `php tools/banc/sonde-securite.php` : **VERT** (CSRF absent/rejoué refusé sur la nouvelle mutation).
- `tests/e2e/maquettes.spec.ts` : l'ancien parcours clavier (faux documents 101→103, sémantique changée par
  la validation réelle) devient un contrôle du contrat visuel ; le comportement vit dans l'oracle.

**Modifications minimales signalées (hors secteur, assumées)**
- `src/Kernel.php` : « Ui » ajouté à la liste des modules de secteur — l'écran porte désormais une route.
  Signalé ici et dans les demandes ci-dessous.
- `src/Securite/DroitsRoutes.php` : permission `POST /a-traiter/{id:[0-9]+}/valider` + reconnaissance de la
  route paramétrée (signalé dans `docs/secteurs/droits-securite.md`).
- `tools/banc/sonde-securite.php` : charge CSRF défensive de la nouvelle mutation (signalé dans
  `docs/secteurs/harness.md` et `droits-securite.md`).

**Demandes aux autres secteurs**
- **Socle** : `DepotFichiers` n'écrit toujours pas l'`id` dans le `.meta.json` ; l'aperçu réel de
  `/a-traiter` résout la pièce par `id`, l'oracle le pose donc après dépôt. Poser l'`id` au dépôt rendrait le
  dossier auto-descriptible (déjà demandé par Workflows/Recherche).
- **Vue d'ensemble** : la ligne « Ui » ajoutée à `src/Kernel.php` est une modification minimale signalée ;
  à confirmer/reporter dans le contrat si le Kernel doit rester figé.
- **Recherche & aperçu** : la macro `visionneuse.moteur` était inutilisable à l'import (import d'icônes hors
  macro) ; corrigé (voir ci-dessus), à réutiliser dans `bibliotheque.twig` / `document.twig`.
- **Droits & sécurité** : le refus de validation (permission manquante, CSRF) est porté par la garde
  centrale ; l'écran n'a pas de garde propre.

**Reste hors de ce lot**
- Brancher les autres écrans (bibliothèque, fiche) sur le fonds : suivis par leurs secteurs (Socle/Lot V2-04).
- `harness.ps1 -SansBanc` (dernier rejeu, 29-09) : 0-garde-fous VERT, 7-securite VERT ; 4-écrans ROUGE avec
  6 échecs **hors lot** — `bibliotheque.spec` (routes Socle V2-04 absentes : `/metadonnees`, vues réelles),
  `recherche.spec` (l'index de la base partagée porte les ids de PHPUnit, attend les ids 201/204 du corpus),
  `apercu.spec:18` (flaky sous charge parallèle, vert en isolé) ; les **3 parcours `a-traiter`** et les 12
  parcours maquettes sont verts.
- 1-unitaire : **241/241 tests, 1073 assertions passent** (rejeu direct du lot : OK, vert). Le dernier
  harness l'a compté ROUGE sur 2 « PHPUnit warnings » introduits par un travail concurrent hors lot
  (`phpunit.xml` + `tests/contrats/` non commités d'un autre agent : classes de contrat abstraites =
  avertissements, `failOnWarning=true`). Aucun test en échec ; hors périmètre Interface.

ETAT: fini



## Jugement V2-03-écran (Claude Code, 29-09) — porte franchie
Rejoué sur le code commité : unitaire 212/212, 3 bancs verts, écrans 29/29 dont les 3 parcours « À traiter » (trois validations à Entrée, la 4e pièce en habitude, « Passer » sans écriture), sonde de sécurité verte. Le dessin validable par Olivier est inchangé (mêmes classes et data-testid).
ETAT: fini (jugé)

## Demandes du HARNESS (lot H-6 « 6-charge », 29-09-2026)

Mesure : fonds synthétique de 10 000 documents (`var/charge/`, `tools/banc/charge.php`), 3 puis 10 utilisateurs
simultanés, tâche d'ingestion `courrier-100p` en fond. Preuves : `recette/preuves/charge/charge.{json,md}`.

- **`/a-traiter` ne tient pas le seuil (défaut nommé)** : p50 2,9 s / p95 3,4 s à 3 utilisateurs ;
  p50 5,2 s / p95 5,5 s à 10. Cause : `Ged\Ui\ATraiter::documents()` appelle `DepotFichiers::lister()`
  (toutes les lignes de l'index) puis fait un `is_file` **par document** à chaque rendu, et `donnees()`
  reparcourt ces documents pour le bilan — le coût croît avec la taille du fonds, pas avec la file.
  Attendu : borner le travail au statut `a_controler` (requête indexée) et ne plus `stat`/décoder tout le
  fonds à chaque GET. La couche 6 (< 1,5 s en lecture) reste donc ROUGE tant que ce n'est pas corrigé.

Aucune autre attente : les validations (écriture) tiennent le seuil (p95 2,6–2,9 s < 3 s), 0 collision,
0 erreur, la tâche d'ingestion finit.

## Journal R1-ecrans — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Défauts de la recette réelle (instance installée, scan 103 pages).
Reprise de zéro sur l'arbre courant (un premier agent n'avait rien modifié).

**Oracle d'abord (rouge, puis vert)**
- `tests/e2e/ecrans-reels.spec.ts` + `tests/e2e/preparer-ecrans-reels.php` : parcours avec un compte RÉEL
  (créé comme par l'installateur : courriel + mot de passe haché, rôle admin), sur son propre fonds
  synthétique `var/oracles/ecrans-reels/documents`, serveur à lui, base `gedv2_test` (verrou partagé).
- Écrit rouge : « documents@exemple.ch » restait affiché, le type valait « Facture » sans proposition,
  les vues du rail étaient figées, le compteur différait, « 1 pages » apparaissait. Puis vert : 5 parcours.

**Fait (défauts de la recette)**
1. **Application** (`src/Ui/Application.php`, `templates/ecrans/application.twig`) : plus aucune valeur de
   maquette. Dossier/adresse d'ingestion → « configuré »/« non configuré » (le chemin n'est jamais montré) ;
   agent d'ingestion = DERNIÈRE tâche réelle de la zone de travail de l'instance + horodatage ; racine =
   `STORAGE_ROOT` ; modèle = `INFOMANIAK_AI_MODEL_CLASSIFY` réel ; seuil et seuil d'habitude lus de la
   config/`Habitudes` ; K-Time et ClearMyMails = état RÉEL des appairages `jonctions_jetons` (actif/non
   révoqué) ; une information absente le dit.
2. **À traiter sans proposition** (`src/Ui/ATraiter.php`, `templates/ecrans/a-traiter.twig`,
   `public/assets/js/ged.js`) : un `classement` absent laisse `type` VIDE, n'affiche AUCUN badge de source,
   et la validation est refusée tant que le type n'est pas choisi — côté navigateur (`required` + garde JS,
   le refus se nomme) et côté serveur (`valider()` lève « type requis », 422 nommé). Le titre affiché reste
   celui proposé par le Classeur, jamais le nom de fichier technique.
3. **Accords** : « 1 page / N pages », « 1 document / N documents », « lot/lots scanné(s) », règles,
   circuits, résultats — singulier/pluriel partout.
4. **Compteur unique** (`src/Ui/Rail.php`, `src/Ui/Vue.php`, `templates/layout.twig`) : le rail lit la file
   réelle du fonds (documents `a_controler` dont le fichier existe), calculée à UN endroit et exposée à tous
   les gabarits par la fonction Twig `rail()` — plus de « 5 » de maquette à côté d'un « 48 » réel.
5. **Vues de la barre latérale** : elles viennent du registre du fonds (`.ged/vues.json`) et pointent vers
   leur VRAI identifiant (`/bibliotheque?vue=<id>`) ; aucune vue enregistrée → l'écran le dit.

**Preuves**
- `tests/e2e/ecrans-reels.spec.ts` : 5/5 verts en isolé (Application réelle ; type vide + refus serveur ;
  proposition affichée + compteur unique sur 5 écrans ; vues réelles filtrantes ; accords).
- `php vendor/bin/phpunit` : mes tests d'écrans passent ; les rouges unitaires du rejeu sont ceux du
  Classement en cours (`ReglesTest`/`ClasseurGedTest` : 16 échecs + 1 erreur, travail concurrent hors lot)
  et de `Contrats\ClasseurGedContratTest` (déjà en échec avant mes modifications).
- `powershell -File tools\harness.ps1 -SansBanc` (01-10, 8 min 34 s) : **0-garde-fous VERT · 7-securite VERT ·
  6-charge VERT** (p95 lecture 704 ms) ; `4-ecrans` : **1 échec / 38 verts** — l'unique échec est
  `a-traiter.spec` « valider trois pièces … habitude », **pré-existant hors lot** (le R2 classement a durci
  `Regles::lireEmetteur` : « Emetteur Oracle … » n'a plus de raison sociale et rend `null`, donc l'habitude
  n'est plus corroborée) ; `1-unitaire` et `2-contrat` rouges par le travail Classement en cours.
  **Aucun nouveau rouge d'écran** ; mon parcours compte dans les 38 verts.
- `/a-traiter` et les écrans restent sous 1,5 s (le rail ne fait qu'une file bornée déjà bornée par
  `ATraiter`) ; charge p95 lecture 704 ms.

**Demandes aux autres secteurs**
- **Classement** : `Regles::candidatEmetteur()` exige désormais une raison sociale (`sa|sarl|banque|…`).
  L'oracle `tests/oracles/interface/preparer-a-traiter.php` fabrique des émetteurs fictifs
  `« Emetteur Oracle <jeton> »` sans suffixe : `lireEmetteur()` rend `null`, la 4ᵉ pièce n'a plus ni
  émetteur ni habitude, et `a-traiter.spec` échoue — vérifié indépendamment de mes fichiers. Deux issues :
  soit autoriser un nom d'organisation déjà corroboré par l'IA/le texte, soit mettre l'oracle d'interface à
  jour (nom fictif avec suffixe légal) — je ne modifie pas `tests/oracles/` (hors lot).
- **Vue d'ensemble / Socle** : `EcranProfil` et `EcranMetadonnees` passent encore `Maquette::bilan()` ;
  le rail n'en dépend plus (fonction `rail()`), leur `bilan` local est donc inutilisé — à retirer un jour.

Aucune ligne sous « À commiter par le juge » n'est due : ni `DroitsRoutes.php` ni `sonde-securite.php` n'ont
été touchés.

ETAT: fini


### Jugement R1-ecrans (juge, 01-10 13:16, copie propre et recette réelle sur 534f8c0)

- Repris par DeepCode à 11:58 : l'agent précédent n'avait rien modifié en 2 h 20.
- Recette réelle (compte réel, 48 documents d'un vrai scan) :
  - L'écran Application affiche la vraie configuration et le vrai état : racine réelle, IMAP « non configuré », dernière activité de l'ingestion, IA active, connecteurs non reliés.
  - À traiter affiche les vraies propositions (source IA ou règle, score, raison lisible) ; sans proposition, aucun type n'est pré-rempli.
  - Pluriels corrigés, compteur unique (48 partout), vues latérales réelles (« Aucune vue enregistrée »).
  - Plus aucune erreur dans la console.
- Harness : aucun nouveau rouge dû à ce lot (4-écrans : 38/39). Les rouges 1, 2 et 4 restants viennent de R2-classement, en correction chez Codex.
- Défaut restant, qui existait avant le lot : sur À traiter, l'aperçu reste « Chargement de l'aperçu… ». La visionneuse n'y est jamais chargée (aucune requête vers visionneuse.js, pdf.js ni le fichier), alors qu'elle fonctionne dans la Bibliothèque. Les parcours ne vérifient pas que l'aperçu est réellement affiché.
- Verdict : **R1-ecrans franchi pour ses cinq points** ; l'aperçu d'À traiter est renvoyé en R1b-ecrans (DeepCode, 13:08), avec un oracle de rendu réel.

## Journal R1b-ecrans — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Défaut bloquant de la recette réelle : sur À traiter, l'aperçu du
document sélectionné restait « Chargement de l'aperçu… » indéfiniment (aucune requête vers
`visionneuse.js`, `pdf.min.js` ni le fichier ; aucun canvas), alors que la Bibliothèque fonctionnait. Les
parcours passaient sans jamais prouver qu'une page était RENDUE.

**Oracle d'abord (rouge, puis vert)**
- `tests/e2e/ecrans-reels.spec.ts` : 3 parcours ajoutés (À traiter, Bibliothèque, Fiche) qui attendent la
  PREMIÈRE page rendue — canvas `data-rendu="1"` **non vide** (pixels d'encre), pas seulement présent — en
  moins de 2 s, et vérifient les requêtes réellement faites (`visionneuse.js`, `pdf.min.js`,
  `/documents/{id}/apercu/fichier`). L'en-tête « page 1 / 13 » est contrôlé sur les trois écrans ; le
  parcours des accords vérifie « 13 pages » et l'absence de « 1 pages ».
- `tests/e2e/preparer-ecrans-reels.php` : un PDF synthétique de 13 pages est ingéré par le VRAI
  `Ged\Ingestion\Pilote` (`Travaux` → `ClasseurGed` réel → `DepotFichiers`), `origine.pages = « 1-13 »`.
  Le moteur de découpe externe (ClearMyDocs, autre dépôt) est remplacé par un découpeur de test : seul
  point hors dépôt, l'orchestration/classement/rangement sont ceux de production.
- Écrit ROUGE (compteur « 1 page », aucun canvas sur À traiter/Fiche), puis VERT.

**Fait**
1. **Chargement de la visionneuse** (`templates/layout.twig`, `templates/ecrans/bibliotheque.twig`) : le
   comportement est chargé UNE fois par le layout (script externe `'self'`, la CSP nonce reste en place),
   donc sur tous les écrans qui incluent la macro `visionneuse.moteur` — À traiter, Bibliothèque, Fiche,
   et Recherche le jour où elle y est posée. La balise dupliquée de Bibliothèque est retirée. L'oubli qui
   a produit le défaut n'est plus possible : le chargement ne dépend plus de l'écran.
2. **Fiche document** (`src/Ui/Document.php`, `src/Ui/Ecrans.php`, `templates/ecrans/document.twig`) : la
   fiche lit le document réel (index + `.meta.json` à côté du fichier, le dossier fait foi), ses versions
   et son historique, et affiche la MÊME visionneuse que les listes. Un identifiant inconnu du fonds
   retombe sur la maquette : jamais d'écran muet ni de 500 ; classes et `data-testid` inchangés.
3. **Compteur de pages** (`src/Ui/Rendu.php`, `src/Ui/ATraiter.php`) : l'ingestion écrit `origine.pages` en
   PLAGES (« 1-13 », « 25-28,30 ») ; `Rendu::compterPages` en lit la longueur (13, pas 1). Le rendu est
   construit une seule fois (`Rendu::pour`) pour À traiter et la fiche.

**Preuves**
- `tests/e2e/ecrans-reels.spec.ts` : **8/8 verts** (compte RÉEL, fonds dédié, base `gedv2_test`), dont les
  3 parcours de rendu sous 2 s ; `maquettes.spec` / `apercu.spec` / `bibliotheque.spec` inchangés.
- `powershell -File tools\harness.ps1 -SansBanc` (01-10, 12 min 43 s, commit `0e227f3`) :
  **0-garde-fous VERT · 4-écrans VERT (43/43, aucun échec) · 7-securite VERT · 2-contrat VERT ·
  6-charge VERT** (p95 lecture 713 ms, p95 écriture 183 ms). Rouges restants : `1-unitaire` = les
  3 échecs + 2 warnings de Socle en cours chez Codex (`CorbeilleTest`, `EcranMetadonneesTest`,
   `IndexeurTest` — vérifiés nommément, aucun n'est Interface), `3-banc` (sautée par `-SansBanc`) et
  `5-personas` (pas encore écrite). **Aucun nouveau rouge d'écran.**
- Vérification du bruit de fond partagé : les deux échecs Playwright observés en cours de lot
  (`maquettes.spec` bibliothèque > 1,5 s ; `bibliotheque.spec` 404 d'aperçu) se reproduisent **avec les
  gabarits de base** : ils viennent des 10 000 documents laissés par la couche 6 (banc de charge) dans
  `gedv2_test`, pas du lot. `ATraiterBorneTest` (compteur MySQL partagé) est également sensible à la
  concurrence ; il passe sur base au repos et l'est dans le rejeu complet ci-dessus.

**Demandes aux autres secteurs**
- **Socle** : `EcranBibliotheque::apercu()` pose `pages => 1` en dur ; l'en-tête de la visionneuse n'est
  juste qu'après le JS. Lire le nombre de pages du `.meta.json` (comme `ATraiter`/`Document`) le rendrait
  juste dès le HTML.
- **Recherche & aperçu** : la macro `visionneuse.moteur` n'est pas encore posée sur l'écran Recherche ; le
  chargement désormais global la couvre le jour venu.

ETAT: fini
### Jugement R1b-ecrans (juge, 01-10 14:30, copie propre et recette réelle sur 918a9dd)

- Recette réelle avec un compte réel : sur À traiter, la première page du PDF est rendue en 346 ms (canvas dessiné, plus de « Chargement de l'aperçu… »).
- Harness : 4-écrans à 43/43, dont l'oracle de rendu réel.
- Verdict : **R1b-ecrans franchi.**

## Journal — préambule V2-06b (retouches designer) — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Trois retouches demandées par le jugement V2-06b, faites avant le lot mobile.

**Oracle d'abord (rouge, puis vert)**
- `tests/e2e/workflows.spec.ts` : parcours ajouté — titres de groupes accentués, section technique absente, graphe ajusté
  à la vue, zoom par boutons, molette et clavier. Rouge constaté (« DECLENCHEURS », nœuds qui débordaient), puis vert.

**Fait**
1. **Graphe ajusté et zoomable** (`public/assets/js/workflows-designer.js`, `templates/ecrans/workflows-editeur.twig`,
   `public/assets/css/ged.css`) : une vue `translate/scale` sur `#wf-vue` ; `ajuster()` cadre le dessin à l'ouverture ;
   molette (zoom vers le pointeur), boutons « ajuster / + / − », clavier `0`, `+`, `−`, flèches (déplacement) ;
   l'indicateur affiche le pourcentage (`data-zoom`). Le glisser-déposer des nœuds, les liaisons et le dépôt
   convertissent les coordonnées écran → canevas, donc restent justes à toute échelle.
2. **Section « Composants GEDv1 non portés » retirée de l'écran** (`templates/ecrans/workflows.twig`) : l'information
   de développeur reste recensée par `Catalogue::aPorter()` et dans `docs/secteurs/workflows.md` ; elle n'est plus
   affichée à l'utilisateur.
3. **Titres de groupes accentués** (`templates/ecrans/workflows-editeur.twig`) : libellés explicites
   (Déclencheurs, Conditions, Actions, États, Approbation) + `.wf-outils .titre-section` en casse de phrase.

**Preuves**
- `npx playwright test tests/e2e/workflows.spec.ts` : **2/2 verts** (le parcours V2-06b d'origine et le nouveau).
- Commit `f9d62c2`.

## Journal V2-10a — mobile, étape 1 — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Décision d'Olivier (01-10) : « les mêmes écrans, en version adaptée au
smartphone ». **Une seule base de gabarits Twig et de CSS**, adaptée par points de rupture (≤ 768 px, ≤ 480 px) ;
mêmes routes, mêmes droits, même CSP. Aucune autre application.

**Oracle d'abord (rouge, puis vert)**
- `tests/e2e/mobile.spec.ts` + `preparer-mobile.php` + `indexer-mobile.php` + `passer-ingestion-mobile.php` :
  parcours sur appareils ÉMULÉS (`devices['iPhone 13']`, `devices['Pixel 7']`) avec un compte RÉEL (créé comme à
  l'installation), sur son propre fonds synthétique `var/oracles/mobile/documents`, serveur à lui, base `gedv2_test`.
  Contrôle automatique sur chaque écran : largeur de page ≤ largeur d'écran, et cibles tactiles de commande ≥ 44 px.
  Écrit rouge (pas d'onglets bas), puis vert **2/2**.

**Fait, périmètre de l'étape, dans l'ordre**
1. **À traiter sur téléphone** (`templates/ecrans/a-traiter.twig`, `ged.css`, `ged.js`) : aperçu en haut, proposition
   dessous, file en dernier **repliée en compteur** (`<details>` ouvert sur ordinateur, replié par le JS au point de
   rupture) ; gros boutons **Valider / Corriger / Passer** dans un pied collant ; `Corriger` amène au champ Type ;
   balayage horizontal = pièce suivante (hors aperçu) ; pincement à deux doigts dans la visionneuse (`visionneuse.js`).
2. **Recherche et fiche** : la barre de recherche globale reste en haut ; les résultats sont des cartes sur téléphone ;
   la fiche (`document.twig`) devient une colonne unique — aperçu, métadonnées, **Télécharger** — sans les volets
   latéraux du bureau.
3. **Dépôt par photo** (`src/Ui/Depot.php`, `src/Ui/Module.php`, `templates/ecrans/deposer.twig`) : l'écran `/deposer`
   ouvre l'appareil photo (`accept="image/*,application/pdf"`, `capture="environment"`, `multiple`). Le fichier entre
   dans la **même chaîne d'ingestion** que le dépôt de bureau : `Ged\Jonctions\SourceApi` écrit la copie de travail
   dans la zone d'ingestion puis `Travaux::recevoir` enregistre un travail idempotent (ce que fait `POST /api/v1/depots`,
   destination `ingestion`) ; la porte est la session et la permission `documents:traiter`, pas un jeton. Le retour est
   un PRG nommé « reçu, en cours de lecture ». Le test « passage de la tâche » rejoue le VRAI Pilote (découpe simulée,
   classement, rangement) et la pièce arrive dans À traiter.
4. **Navigation** (`templates/layout.twig`, `ged.css`) : la barre latérale devient une barre d'onglets en bas
   (À traiter, Recherche, Déposer) ; un menu « Plus » garde Bibliothèque, Métadonnées, Workflows et Application
   accessibles, et un avis « écran conçu pour l'ordinateur » (`data-testid="ecran-ordinateur"`) les signale.

Exigences tenues : cibles ≥ 44 px (le contrôle de l'oracle les mesure), aucun défilement horizontal, champs ≥ 16 px,
clair et sombre (les jetons ne changent pas), aucune donnée réelle.

**Défaut réel trouvé en chemin (corrigé)**
- Après connexion, le premier formulaire de `/a-traiter` portait le **jeton CSRF de connexion périmé** : la réponse de
  connexion pose le cookie de session mais pas le cookie `gedv2_csrf`, et le formulaire lisait le cookie de la REQUÊTE
  (l'ancien jeton de connexion) alors que la garde avait déjà posé le jeton de la session. La première validation
  partait donc en 403, et il fallait un aller-retour de plus pour obtenir un formulaire valide. Corrigé **dans mon
  secteur** en lisant le jeton de la SESSION (source autoritaire posée par la garde), avec repli sur le cookie :
  `Ged\Ui\Ecrans::jetonCsrf()`, utilisé par `/a-traiter`, `/workflows` et `/deposer`.

**Preuves**
- `npx playwright test tests/e2e/mobile.spec.ts` : **2/2 verts** (iPhone 13 et Pixel 7, compte réel, valider au pouce,
  chercher/ouvrir une fiche, déposer une photo et la voir arriver après passage de la tâche).
- `npx playwright test tests/e2e/workflows.spec.ts` : **2/2 verts** (le préambule ne régresse pas le designer).
- `npx playwright test tests/e2e/maquettes.spec.ts` : 11/12 — seul rouge, `bibliotheque` qui dépasse 1,5 s : bruit connu
  des 10 000 documents laissés par le banc de charge dans `gedv2_test` (journal R1b), pas le lot.
- `php tools/banc/sonde-securite.php` : **VERT** (les deux nouvelles routes de dépôt refusent CSRF absent/rejoué).
- `php tools/banc/contrat.php` : **VERT** (toutes les routes déclarées couvertes, y compris `GET/POST /deposer`).
- `php vendor/bin/phpunit tests/php/EcransTest.php` : 10/10 verts.
- `npx playwright test tests/e2e/mobile.spec.ts tests/e2e/ecrans-reels.spec.ts tests/e2e/a-traiter.spec.ts` :
  **13/13 verts**. Les helpers mobiles ne passent plus par la file partagée `ingestion_travaux` : ils déroulent le
  VRAI Pilote (découpeur, classeur, dépôt) sur le lot et la pièce désignés par leur identifiant, donc aucun travail
  d'un autre fonds ne peut être pris (défaut observé sous charge parallèle, corrigé par `bc72348`).
- Harness `-SansBanc` (01-10, 13 min 15 s, commit `bc72348`) : **0-garde-fous VERT · 1-unitaire VERT**
(341 tests, 1783 assertions) **· 7-securite VERT · 2-contrat VERT · 4-écrans : 45 verts, 1 échec**. L'unique
échec d'écran est `maquettes.spec` « écran a-traiter » qui dépasse 1,5 s (3 971 ms) : bruit de charge connu de la
base partagée (les 10 000 documents laissés par le banc, journal R1b) ; le rouge classement `a-traiter.spec:126`
ne s'est **pas** reproduit. **Aucun rouge fonctionnel dû au lot.** `3-banc` est sautée (`-SansBanc`), `6-charge`
est ROUGE sur la lecture (p95 6 065 ms ≥ 1 500 ms ; l'ingestion finit) — perf mesurée sous charge, hors Interface ;
`5-personas` n'est pas encore écrite.

**Fichiers partagés — « À commiter par le juge »**
- `src/Securite/DroitsRoutes.php` : 2 lignes (`GET /deposer`, `POST /deposer` → `documents:traiter`).
- `tools/banc/sonde-securite.php` : 1 charge CSRF défensive pour `POST /deposer`.
- Aucune autre permission ni route touchée.

**Demandes**
- **Harness / bruit partagé** : l'oracle mobile s'isole entièrement (fonds dédié, serveur à lui, ligne d'index
  reposée juste avant la recherche, et découpe/rangement du SEUL lot visé sans passer par la file
  `ingestion_travaux`). Le bruit restant vient des parcours qui partagent encore `gedv2_test` (dernière tâche
  d'ingestion, index `recherche_textes` d'un autre fonds, base chargée à 10 000 documents) : mesuré hors lot
  (`maquettes a-traiter` > 1,5 s en porte 4). À traiter un jour par une base par parcours, hors de ce lot.
- **Socle** : `SourceApi` écrit bien `<fichier>.depot.json`, mais les métadonnées du dépôt interface ne sont pas encore
  rattachées au document par la chaîne d'ingestion (même constat que la demande V2-08a d'Ingestion).
- **Vue d'ensemble** : l'avis « écran conçu pour l'ordinateur » vit dans `layout.twig` (liste d'écrans) ; à déplacer
  dans un registre partagé si la liste doit être servie par le Kernel plutôt que par le gabarit.

**Commits**
`e78a4c3` oracle mobile · `30a15b0` dépôt par photo + routes/charge sonde (hors secteur) ·
`b58c74f` adaptation téléphone (gabarits, CSS, JS) · `9c6e55a` fiabilité de l'oracle et de la connexion ·
`bc72348` helpers mobiles déterministes · `f9d62c2` préambule designer V2-06b.

ETAT: fini

## Jugement V2-10a — mobile, étape 1 — 01-10-2026 (juge : Claude Code)

- Contrôles automatiques revérifiés en émulation iPhone 13 sur la recette réelle (compte réel, `9c6e55a`) : largeur 390/390, aucune cible < 44 px, aperçu rendu sur À traiter, dépôt par photo branché sur la chaîne existante. Routes `/deposer` et charge de sonde (`30a15b0`) relues : propres.
- Relecture à l'œil des écrans (ce que l'oracle ne voit pas) — cinq défauts :
  1. **À traiter** : le bilan s'affiche avant la pièce ; il faut défiler pour voir l'aperçu et le bouton « Valider », ce qui contredit « une pièce à la fois, au pouce ».
  2. **Recherche** : les boîtes de filtres se chevauchent sur 390 px ; les filtres doivent être repliés derrière un bouton « Filtres ».
  3. **Fiche document, Recherche, À traiter** : l'avertissement « écran conçu pour l'ordinateur » s'affiche alors que ces écrans sont justement adaptés au téléphone.
  4. **Déposer** : bouton natif anglais « Choose Files » ; il faut des boutons français « Prendre une photo » et « Choisir des fichiers ».
  5. **Barre du haut** : le raccourci « Ctrl K » reste visible sous 768 px, où il n'a pas de sens.
- Harness rejoué par l'agent sur `bc72348` : aucun rouge fonctionnel dû au lot (maquettes a-traiter 3,9 s et 6-charge lecture : bruit de charge connu, hors interface).
- Verdict : **V2-10a non franchi** (ergonomie). Les cinq défauts sont repris en **V2-10b**, avec un oracle visuel : pièce et « Valider » visibles sans défilement, aucun chevauchement de filtres, aucun texte anglais.

## Journal V2-10b — reprise mobile après jugement — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Les cinq défauts d'ergonomie relevés à l'œil en recette réelle
(compte réel, 48 documents d'un vrai scan, émulation iPhone 13). L'oracle automatique passait déjà
(largeur, cibles 44 px, aperçu rendu) mais l'écran ne faisait pas ce qu'Olivier demandait.

**Oracle d'abord (rouge, puis vert)**
- `tests/e2e/mobile.spec.ts` complété, sur iPhone 13 **et** Pixel 7, compte réel, document ingéré par le
  Pilote. Nouvelles exigences : (1) à l'ouverture d'À traiter, l'aperçu de la pièce ET le bouton
  « Valider » sont ENTIÈREMENT dans la fenêtre, sans défilement (`window.scrollY = 0`), et le bilan est
  la ligne compacte (les trois cartes sont masquées) ; (2) les filtres de Recherche, ouverts, ne se
  recouvrent pas (contrôle des boîtes englobantes, paires parent/enfant exclues) ; (3) aucun
  `ecran-ordinateur` sur À traiter, Recherche et la fiche ; (4) « Prendre une photo » / « Choisir des
  fichiers » visibles, la liste porte le nom du fichier choisi, aucun texte anglais ; (5) « Ctrl K »
  masqué sous 768 px.
- Écrit ROUGE (le premier contrôle, « Ctrl K », échoue ; puis les autres), puis **2/2 verts**.

**Fait — les cinq défauts**
1. **À traiter** (`templates/ecrans/a-traiter.twig`, `ged.css`, `ged.js`) : la pièce d'abord — aperçu en
   haut (pincer pour zoomer, déjà en place), proposition dessous ; le bilan devient une ligne compacte
   (`data-testid="bilan-ligne"`) placée APRÈS la pièce, les trois cartes restant à l'ordinateur ; le pied
   Valider/Corriger/Passer est **fixe** au-dessus des onglets, sur une seule ligne (les raccourcis
   clavier y sont masqués), donc toujours au pouce ; la file reste une liste repliable ; le geste de
   masse « Valider les suggestions sûres » ne s'affiche plus sur téléphone.
2. **Recherche** (`templates/ecrans/recherche.twig`, `ged.css`, `ged.js`) : les filtres sont repliés
   derrière un bouton « Filtres » ; ouverts, panneau pleine largeur, un champ par ligne. Sur ordinateur,
   le panneau est en `display: contents` : la rangée de puces est **inchangée** (capture relue à l'œil).
3. **Avis « écran conçu pour l'ordinateur »** (`templates/layout.twig`, `src/Ui/Ecrans.php`) : la fiche
   document s'en retire (`avis_ordinateur: false`) sans perdre son entrée de rail Bibliothèque ; seuls
   Bibliothèque, Métadonnées, Workflows et Application le gardent.
4. **Déposer** (`templates/ecrans/deposer.twig`, `templates/partiels/icones.twig`, `ged.css`, `ged.js`) :
   deux boutons français « Prendre une photo » (capture) et « Choisir des fichiers » pilotent le SEUL
   champ de fichier, masqué — le libellé natif anglais n'est jamais visible ; la liste des fichiers
   choisis montre nom, taille et vignette.
5. **Barre du haut** (`ged.css`) : « Ctrl K » masqué sous 768 px.

**Défaut réel trouvé en chemin (corrigé dans le lot)**
- La vignette du dépôt via `URL.createObjectURL` déclenchait une violation CSP (`default-src 'self'`,
  pas d'`img-src`) dans la console. Remplacée par un rendu `canvas` (`createImageBitmap`) : la vignette
  ne charge donc aucune URL.

**Preuves**
- `npx playwright test tests/e2e/mobile.spec.ts` : **2/2 verts** (iPhone 13, Pixel 7, compte réel,
  document du Pilote ; valider au pouce, filtres, fiche, dépôt par photo).
- Non-régression bureau : `npx playwright test --workers=2` → **43 verts, 1 non joué, 2 rouges connus**
  (`bibliotheque.spec` : 404 d'aperçu d'un document absent du fonds partagé ; `maquettes « écran
  bibliotheque »` : 1,8 s > 1,5 s). Les deux sont **prouvés pré-existants** par `git stash` de tous mes
  fichiers : ils échouent à l'identique sans le lot.
- `harness.ps1 -SansBanc` (01-10, 13 min 36 s, commit `ecd5b75`) : **0-garde-fous VERT · 1-unitaire VERT
  (341 tests, 1783 assertions) · 7-securite VERT · 2-contrat VERT** ; **4-écrans : 45 verts, 1 échec** —
  l'unique échec est `maquettes « écran bibliotheque »` (dépassement du seuil de 1,5 s sur la base
  partagée chargée par les 10 000 documents du banc), pas le lot. `3-banc` sautée (`-SansBanc`),
  `5-personas` « pas encore écrite », `6-charge` ROUGE sur la lecture (p95 4 621 ms ≥ 1 500 ms,
  ingestion finie) — perf mesurée hors Interface.

**Demandes**
- **Harness / bruit partagé** : sous la charge concurrente d'un autre agent, une même exécution des
  parcours est tombée à `6 échecs / 28 verts` (budgets dépassés faute de CPU, base verrouillée), puis est
  revenue à `1 échec / 45 verts` sur machine libre : le seul rouge restant est le seuil de 1,5 s de
  Bibliothèque sur les 10 000 documents du banc. Une base par parcours reste la seule façon de mesurer
  ces seuils sans le bruit de la base partagée (déjà demandé en V2-10a).
- **Socle** : le rendu `canvas` des vignettes n'utilise ni `blob:` ni `data:` ; si la CSP gagne un jour
  `img-src blob:`, on pourra revenir à un `<img>` plus simple.

**Commits**
`a07579c` oracle mobile V2-10b (`tests/e2e/mobile.spec.ts`) · `ecd5b75` adaptation téléphone
(gabarits, CSS, JS).

ETAT: fini

## Jugement V2-10b — mobile, reprise — 01-10-2026 (juge : Claude Code)

- Relecture à l'œil sur la recette réelle (compte réel, émulation iPhone 13, `ecd5b75`) des quatre écrans À traiter, Recherche, Fiche et Déposer : largeur 390/390, aucune cible < 44 px, aperçu rendu.
- Les cinq défauts de V2-10a sont corrigés :
  1. À traiter : la pièce est en haut ; « Valider », « Corriger » et « Passer » sont fixés au-dessus des onglets, sans défilement.
  2. Recherche : les filtres sont repliés derrière « Filtres » ; plus de chevauchement.
  3. L'avis « conçu pour l'ordinateur » est retiré de la fiche, de la recherche et d'À traiter.
  4. Déposer : « Prendre une photo » et « Choisir des fichiers » en français, vignettes sans violer la CSP.
  5. « Ctrl K » est masqué sous 768 px.
- Harness de l'agent (`-SansBanc`, `ecd5b75`) : aucun nouveau rouge dû au lot. Les deux rouges d'écran sont antérieurs (prouvé par `git stash`) : une 404 sur un document absent du fonds partagé, et le seuil de 1,5 s de la Bibliothèque sur les 10 000 documents du banc. La lenteur de la Bibliothèque sur un gros fonds est réelle (p95 5,7 s sous charge) ; elle fera l'objet d'un lot de performance distinct.
- Remarques pour l'étape mobile suivante (non bloquantes) :
  - la proposition de classement (type, émetteur, date) n'est pas visible au-dessus de « Valider » : il faut un résumé compact juste au-dessus des boutons, pour ne pas valider à l'aveugle ;
  - les extraits « Documents semblables » de la recherche affichent du texte OCR brut ;
  - le texte d'invite de la barre de recherche est tronqué sur 390 px.
- Verdict : **V2-10b franchi** (étape 1 mobile, « les mêmes écrans adaptés au smartphone »).

## Journal PERF-3 / V2-10c — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Deux lots dans l'ordre : **PERF-3** (Bibliothèque sur gros fonds)
puis **V2-10c** (les trois finitions du jugement V2-10b).

### PERF-3 — cause mesurée, avant/après

Cause trouvée en mesurant (fonds de 10 000 pièces, base `gedv2_test`, `Handler_read_rnd_next`, EXPLAIN,
temps Twig et requête HTTP) : `EcranBibliotheque::donnees()` lisait **tout** l'index — la colonne `meta`,
copie de chaque `.meta.json` (5,7 Mo) — puis bâtissait la liste ENTIÈRE et Twig rendait **10 000 lignes**
(3,36 Mo de HTML). Sur un serveur `php -S` mono-processus, plusieurs workers font la queue derrière ce
rendu : c'est la Bibliothèque SEULE qui dépassait 1,5 s, les autres écrans lisant déjà une file bornée.

| Mesure (10 000 documents) | Avant | Après |
|---|---|---|
| `donnees()` | 87 ms | **17 ms** |
| Lignes de la liste | 10 000 | **100** (1 page / 100) |
| Rendu Twig | 119 ms | **40 ms** |
| HTML de l'écran | 3 357 204 o (3,3 Mo) | **48 818 o** |
| `Handler_read_rnd_next` de `donnees()` | +10 001 | **+0** |
| Requête HTTP à froid | 289 ms (3 376 058 o) | **98 ms** (49 226 o) |
| Charge (banc, p95 lecture) | 412–5 700 ms selon la charge partagée | **370 ms** ; Bibliothèque p95 272/231 ms (3/10 utilisateurs) |

**Oracle d'abord (rouge, puis vert)** : `tests/php/Perf/BibliothequeBorneeTest.php` — 10 000 pièces
synthétiques insérées dans la base de TEST puis retirées ; exigences : liste ≤ 1 page, HTML borné, lecture
SQL bornée (`Handler_read_rnd_next`), écran < 1,5 s à froid. Écrit **ROUGE** (20 053 lignes lues),
puis **VERT** après correction. Complété par `tests/php/Perf/BibliothequeFiltresTest.php` : pour 13 jeux
de filtres (colonnes ET champs personnalisés JSON), le compte et la première page SQL sont identiques à
`Vues::filtrerDocs` (la sémantique de référence).

**Fait**
1. **Liste paginée CÔTÉ BASE** (`src/Socle/EcranBibliotheque.php`) : `LIMIT/OFFSET` de 100, plus récent
   d'abord (`ORDER BY id DESC`), et seulement les colonnes de l'écran — la colonne `meta` n'est plus lue
   pour une liste. Pager (`page`, `pages`, liens précédent/suivant) dans le gabarit ; toutes les pièces
   restent atteignables. Le contenu (colonnes, valeurs, droits) est inchangé ; seule la présentation passe
   par des pages (plan : « liste au centre, 100 lignes puis défilement »).
2. **Comptages bornés** : `COUNT(*)` indexé pour le fonds, une requête indexée par vue (les filtres
   compilés sont traduits en SQL, y compris `meta.champs` — dates, nombres, booléens, texte). Plus de
   « on filtre tout le fonds par vue ».
3. **Arbre d'un seul passage indexé** (`FORCE INDEX (k_vivant)` sur `chemin_relatif`), avec repli honnête
   si un chemin atteint le préfixe d'index.
4. **Aperçu par identifiant** : le document `?doc=` est résolu par une ligne (`WHERE id = ?`), plus par un
   balayage de la liste.
5. **Migration d'index** : `k_vivant (supprime_le, absent_depuis, chemin_relatif(700))` — *fichier partagé,
   à commiter par le juge* (voir ci-dessous).

**Preuves** — `php vendor/bin/phpunit tests/php/Perf/BibliothequeBorneeTest.php` : **4/4** ;
`BibliothequeParIndexTest` : **2/2** (mis à jour au contrat paginé) ; `BibliothequeFiltresTest` : **13/13**.
`npx playwright test maquettes.spec.ts` : **12/12** — dont « écran bibliothèque » (l'ancien rouge de
1,5 s a disparu). `bibliotheque.spec.ts` + `ecrans-reels.spec.ts` + `recherche.spec.ts` : **13/13**.

### V2-10c — les trois finitions du jugement V2-10b

**Oracle d'abord (rouge, puis vert)** — `tests/e2e/mobile.spec.ts` étendu (iPhone 13 + Pixel 7) :
(a) `resume-proposition` visible SANS défilement, **juste au-dessus** de « Valider », avec type et émetteur ;
(b) sur un document au texte OCR **bruité** (jeton unique), la carte garde titre/type/émetteur/date mais ne
porte **aucun** extrait ; (c) l'invite de recherche est entièrement visible sur 390 px (largeur du texte
mesurée au canvas vs place du champ). L'heuristique d'extraits est testée en unitaire
(`tests/php/ExtraitsLisiblesTest.php`, écrit ROUGE — classe absente — puis VERT).

**Fait**
1. **a) Résumé compact** (`templates/ecrans/a-traiter.twig`, `ged.css`) : `type · émetteur · date` et le
   motif (« à contrôler · … ») sur une ligne pleine largeur AU-DESSUS des trois gestes, dans le pied fixe
   (téléphone seulement). Le pied grandit : la réserve basse de page passe de 150 à 230 px pour que la
   file reste atteignable au-dessus de lui.
2. **b) Extraits lisibles** (`src/Ui/ExtraitsLisibles.php`, `src/Ui/Ecrans.php`,
   `templates/ecrans/recherche.twig`) : heuristique sobre sans dictionnaire — ≥ 60 % de lettres et ≥ 60 %
   des mots portant une voyelle, au moins 3 mots ; sinon l'extrait n'est pas rendu. Le titre, le type,
   l'émetteur et la date restent affichés.
3. **c) Invite de recherche** (`templates/layout.twig`, `ged.js`) : sous 480 px, l'invite courte
   « Rechercher » remplace la phrase longue (la version longue revient au-dessus).

**Preuves** — `npx playwright test tests/e2e/mobile.spec.ts` : **2/2 verts** (iPhone 13 + Pixel 7).
`php vendor/bin/phpunit tests/php/ExtraitsLisiblesTest.php` : 6/6 ; `tests/php/EcransTest.php` : 16/16.

**Harness `-SansBanc`** (01-10, 19:35–19:39, commit `b321e0a`, machine au repos) : **0-garde-fous VERT ·
1-unitaire VERT (365 tests, 1 864 assertions) · 4-écrans VERT (46/46) · 7-securite VERT · 2-contrat VERT ·
6-charge VERT** (p95 lecture 370 ms, p95 écriture 126 ms, 0 erreur, 0 collision, ingestion finie ; fonds de
10 000 pièces). Restent ROUGES par construction, hors lot : `3-banc` sautée (`-SansBanc`) et `5-personas`
« pas encore écrite ». **Aucun nouveau rouge dû au lot** ; le dernier rouge d'écran connu (seuil de 1,5 s
de la Bibliothèque) a disparu.

**À commiter par le juge (fichier partagé — je n'y commite pas)**
- `src/Socle/Schema.php` : index `k_vivant (supprime_le, absent_depuis, chemin_relatif(700))` dans
  `CREATE TABLE documents` + `assurerIndexVivant()` (idempotent, pour les bases créées avant lui). Sans
  lui, le comptage des vivants et l'arbre repassent par un parcours du fonds.

**Demandes**
- **Socle / Vue d'ensemble** : `Fonds::indexes()` n'est plus appelé par la Bibliothèque ; il reste le repli
  « base sans index » d'autres chemins. À nettoyer un jour si `parId` couvre tout.
- **Vue d'ensemble** : l'ordre par défaut de la Bibliothèque est désormais « plus récent d'abord »
  (`id DESC`) — l'ancien ordre par chemin n'était pas un contrat explicite ; à figer au contrat si besoin.

**Commits**
`f290f8f` oracle PERF-3 · `1ed2aa9` bibliothèque paginée (Socle + tests) · `1fee6c2` pager et repli
maquette · `81457da` oracle V2-10c extraits lisibles · `56778eb` extraits lisibles branchés · `b1bc706` mobile V2-10c.

ETAT: fini


## Jugement PERF-3 / V2-10c — 01-10-2026 (juge : Claude Code)

- J'ai relu et commité l'index partagé `k_vivant` de `src/Socle/Schema.php` (`0ee1c8f`). La migration est idempotente et ne fait qu'ajouter un index.
- Harness complet du juge sur `0ee1c8f` : garde-fous, unitaire (365), banc (découpe 53/53, classement, bout-en-bout), sécurité et contrat verts. Deux rouges de temps : maquettes « bibliothèque < 1,5 s » (4 606 ms sur 63 documents) et 6-charge (p95 lecture environ 2 s, uniforme sur TOUS les écrans).
- Rejoués seuls, machine calme : `charge.php` est **vert** (p95 lecture 722 ms ; Bibliothèque 492 ms à 3 utilisateurs et 156 ms à 10 utilisateurs, contre 5 677 ms avant PERF-3) ; `maquettes.spec` est **12/12 vert**. La recette à vide répond en 74 à 132 ms. Les rouges du harness venaient de la charge de la machine (autres sessions en cours), pas du code.
- **PERF-3 franchi.**
- V2-10c, relu à l'œil sur la recette réelle (iPhone 13) :
  - a) le résumé de la proposition au-dessus de « Valider » (type, émetteur, date, motif « à contrôler ») est en place : **OK** ;
  - c) l'invite de recherche est entière sur 390 px : **OK** ;
  - b) les extraits « Documents semblables » montrent **encore** du bruit OCR brut sur du vrai papier (fragments de 1 à 3 lettres et signes, suivis d'une adresse lisible). L'heuristique passe sur le synthétique mais reste trop permissive : **non franchi**.
- Verdict : **V2-10c non franchi sur le seul point b**, repris en **V2-10d** avec un corpus OCR synthétique réaliste.

## Journal V2-10d — extraits OCR lisibles (suite de V2-10c) — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Défaut du jugement V2-10c (point b) : sur du vrai papier scanné,
les extraits « Documents semblables » affichaient encore du bruit OCR — un amas de fragments de 1 à 3
lettres et de signes suivi d'une adresse lisible, ou un extrait fait presque uniquement de fragments à
voyelles que l'ancienne heuristique (60 % de lettres, 60 % de mots à voyelle) jugeait « lisible ».

**Oracle d'abord (rouge, puis vert)**
- `tests/php/corpus/ocr-extraits.php` : **25** sorties OCR synthétiques réalistes (en-têtes de facture
  FR/DE/IT, adresses, montants, tampons, logos, mélanges bruit + texte), chacune avec sa décision
  (propre / à rogner / masquée) et les fragments qui ne doivent jamais s'afficher. Aucune donnée réelle.
- `tests/php/ExtraitsLisiblesTest.php` étendu : le corpus doit rendre exactement l'extrait attendu, zéro
  fragment interdit affiché, les extraits propres conservés à ≥ 90 %. Écrit **ROUGE** (méthode `nettoyer`
  absente **et** l'ancienne heuristique renvoyait le texte bruité entier), puis **VERT**.
- Rouge « forme du juge » mesuré : l'ancienne heuristique jugeait `lisible = true` la fenêtre
  `… Ps TC iar ee SES a … Post CHAG` et l'extrait mixte `nn NE {EL EE PostC. Rue du Relais …` ; la
  nouvelle rend `false` pour la première et rogne la seconde en `du Relais …`.

**Fait**
1. **Critère durci** (`src/Ui/ExtraitsLisibles.php`) :
   - rejet d'un extrait dont plus de **40 %** des mots sont des fragments de ≤ 3 caractères hors liste de
     petits mots FR/DE/IT et abréviations administratives courantes (CHF, TTC, TVA, SA, CH…) ;
   - rejet dès qu'un signe parasite apparaît (`{ } [ ] | ~ # @ ^ \ < > _`…) ;
   - un vrai extrait doit porter au moins un mot de 5 lettres ;
   - voyelles et part de lettres conservées ; cette part passe de 60 % à 40 % pour ne pas condamner un
     montant ou une adresse riches en chiffres — la soupe de symboles reste bien en dessous.
2. **Rognage au premier segment propre** : un extrait qui mélange bruit et texte n'est plus montré entier
   ni masqué ; on cherche la première suite de mots plausibles (≥ 3 mots, ≥ 12 lettres, un mot ≥ 5
   lettres) et on affiche à partir de là, en gardant la ponctuation/chiffres collés (`2026.`) sans franchir
   un signe parasite. À défaut de segment propre, rien n'est affiché : titre, type, émetteur et date
   suffisent. Les segments et le surlignage du terme trouvé sont préservés (`retenir` rogne la liste).
3. `src/Ui/Ecrans.php` (commentaire de branchement) ; `templates/ecrans/recherche.twig` inchangé (même
   contrat de données : liste de segments surlignés).

**Preuves**
- `php vendor/bin/phpunit tests/php/ExtraitsLisiblesTest.php` : **11/11, 123 assertions** (12 extraits
  propres conservés = 100 %) ; `tests/php/Recherche` + `tests/php/EcransTest.php` : **29/29** (le `mark`
  sur « maintenance » du corpus d'aperçu reste affiché).
- `npx playwright test tests/e2e/mobile.spec.ts` : **2/2 verts** (iPhone 13, Pixel 7) — sur une pièce
  synthétique au texte bruité, aucun extrait ; sur une pièce mélangée tampon + adresse, l'extrait affiché
  porte l'adresse et aucun fragment (`nn`, `PostC`, `{`).
- `npx playwright test` : **46/46 verts** au rejeu isolé.
- `powershell -File tools\harness.ps1 -SansBanc` (01-10-2026, 3 min 57 s, commit `8aeecc2`) :
  **0-garde-fous VERT · 7-securite VERT · 2-contrat VERT · 6-charge VERT** (p95 lecture 642 ms, p95
  écriture 140 ms, 0 erreur, 0 collision, ingestion finie). `4-ecrans` : 1 échec / 45 verts pendant le
  harness, **46/46 au rejeu immédiat** — bruit de charge sur la base partagée, aucun test du lot en échec.
  `1-unitaire` ROUGE : 16 échecs, **tous dans `tests/php/Classement/`** (chantier Classement en cours,
  fichiers non commités par l'autre agent) ; `3-banc` sautée (`-SansBanc`) et `5-personas` pas encore
  écrite — rouges par construction. **Aucun nouveau rouge dû au lot.**

**Demandes**
- **Classement** : le lot V2-10d n'y touche pas ; les 16 rouges unitaires du harness viennent des
  modifications en cours de `src/Classement/Regles.php` et `src/Classement/ClasseurGed.php` (non commitées).

**Commits**
`8dca37d` oracle V2-10d (corpus + test) · `a536e56` critère durci et rognage (extraits + branchement) ·
`8aeecc2` oracle mobile (pièces bruitée et mélangée).

ETAT: fini

## Jugement V2-10d — 01-10-2026 (juge : Claude Code)

- Relu sur la recette réelle (`653e569`, iPhone 13), trois recherches (« Visana », « facture », « assurance »), soit 25 extraits « Documents semblables ».
- Net progrès : environ deux tiers des extraits sont propres (factures d'énergie, d'entretien et d'expertise, polices, rappels). La tête des extraits est rognée.
- Restent bruités environ 7 extraits sur 25, de trois formes (paraphrasées) :
  - **un fragment parasite en tête ou en queue d'un extrait sinon lisible** (« PostC. " », « ieee Post CHAG », « ... la », « Va ») ;
  - **extrait court presque entièrement fait de bruit** (« i feve 2 29.90/22 Qvec (a Vi 58 Facture ; i », « ayable par ï 4 dedues Ont Postion! Rares a ») ;
  - **ponctuation parasite** au milieu d'un texte lisible (« contac; = »).
- Verdict : **V2-10d non franchi** (le critère « aucun bruit affiché » n'est pas tenu sur le vrai papier). Repris en **V2-10e** : rogner aussi la queue, refuser les extraits courts majoritairement bruités, centrer l'extrait sur le terme cherché.

## Journal V2-10e — finition des extraits de recherche — 01-10-2026

Agent : DeepCode · Juge : Claude Code. Défaut du jugement V2-10d : environ 7 extraits sur 25 encore bruités sur
le vrai papier, en trois formes. Les cinq points du lot sont traités dans l'heuristique d'affichage (secteur
Interface), sans toucher à `src/Classement` ni à `src/Ingestion`.

**Oracle d'abord (rouge, puis vert)**
- `tests/php/corpus/ocr-extraits.php` : six formes ajoutées, chacune avec sa décision — queue de fragments après
  une phrase (« ieee Post CHAG »), tête de fragments, fragment entre guillemets en queue (« PostC. \" »),
  ponctuation parasite isolée (« ; = ») et multiple (« . , ; »), extrait court de majuscules criardes. Textes
  inventés, aucune donnée réelle.
- `tests/php/ExtraitsLisiblesTest.php` : cinq tests ajoutés (queue rognée, tête rognée, court majoritairement
  bruité masqué, ponctuation parasite qui coupe, fenêtre autour du terme). Écrit **ROUGE** (7 échecs : queue non
  rognée, `ALPHA BETA GAMMA DELTA` affiché, `; =` conservé, extrait resté au début du texte), puis **VERT**
  (16/16, 162 assertions).

**Fait — les cinq points du jugement**
1. **Rogner la queue aussi** (`src/Ui/ExtraitsLisibles.php`) : les jetons de bord manifestement bruités sont
   élagués en tête ET en queue (fragment, tout en majuscules non usuel, majuscule interne, amas de voyelles,
   mot sans voyelle), et une fois un bruit de bord trouvé, les petits mots non usuels qui l'accompagnent le
   sont aussi (« ieee Post CHAG » part en entier). Un seul fragment isolé en bord reste toléré quand le texte
   entier est propre (« Rue » d'une adresse, « Mai » d'une date).
2. **Extrait court majoritairement bruité** : sous huit mots, la moitié au moins doit voter « plausible »
   (petit mot ou mot usuel de la GED, nombre/montant, ou nom propre à initiale majuscule) ; sinon rien n'est
   affiché. Une courte liste de mots usuels (facture, contrat, montant, échéance…) protège les en-têtes en
   capitales.
3. **Ponctuation parasite** : une séquence de signes isolés au milieu (« ; = », « ' . , », apostrophe ou
   guillemet collé en fin de mot) coupe l'extrait au premier signe. Les points de suspension et les signes
   typographiques seuls (— : ° % « ») restent légitimes.
4. **Fenêtre autour du terme** (`retenir`) : l'offset du premier terme trouvé est passé à `extraitPropre`, qui
   retient le passage propre qui le CONTIENT — plus le début du texte. Si l'occurrence est elle-même bruitée
   (jeton coupé par un chiffre), on retombe sur le segment propre de tête plutôt que de tout masquer.
5. **Rattachement visuel** (`templates/ecrans/recherche.twig`, `public/assets/css/ged.css`) : l'extrait suit
   immédiatement le titre du document (filet à gauche, `data-testid="extraits-<id>"`) ; le lien « Documents
   semblables » (`data-testid="semblables-<id>"`) est descendu dans un pied de carte APRÈS l'extrait — il
   désigne une action, plus l'intitulé de l'extrait.

**Preuves**
- `php vendor/bin/phpunit` : **390 tests, 2033 assertions, OK** (dont `ExtraitsLisiblesTest` 16/16 ;
  `tests/php/Recherche` + `EcransTest` 29/29, le `mark` sur « maintenance » reste affiché).
- `npx playwright test tests/e2e/mobile.spec.ts` : **2/2 verts** (iPhone 13, Pixel 7) — document dont le
  préambule est lisible mais ne porte pas le terme : l'extrait montré contient le terme cherché, ne contient
  pas « préambule », et le lien « Documents semblables » est SOUS l'extrait (contrôle des boîtes englobantes) ;
  le document mélangé garde son extrait rogné (adresse, aucun fragment).
- `npx playwright test` (suite entière rejouée seule) : **46/46 verts**.
- `powershell -File tools\harness.ps1 -SansBanc` (01-10-2026, 3 min 58 s, commit `e79604b`) :
  **0-garde-fous VERT · 1-unitaire VERT (390 tests, 2033 assertions) · 7-securite VERT · 2-contrat VERT ·
  6-charge VERT** (p95 lecture 709 ms, p95 écriture 159 ms, 0 erreur, 0 collision, ingestion finie).
  `4-ecrans` : **1 échec / 44 verts** pendant le harness, **46/46 au rejeu immédiat de toute la couche** —
  bruit de la base partagée (déjà observé sur `maquettes` recherche sous charge parallèle), aucun test du lot
  en échec. `3-banc` sautée (`-SansBanc`) et `5-personas` « pas encore écrite » — rouges par construction.
  **Aucun nouveau rouge dû au lot.**

**Demandes**
- **Harness / bruit partagé** : la couche 4 reste sensible aux autres sessions qui partagent `gedv2_test` ;
  une base par parcours reste la seule façon de mesurer ces seuils sans bruit (déjà demandé en V2-10a/b).
- **Classement / Ingestion** : non touchés, conformément au lot.

**Commits**
`7fd1624` oracle V2-10e (corpus + test) · `f8332a6` heuristique (extraits + branchement) ·
`044ce40` rattachement visuel (gabarit + CSS) · `e79604b` oracle mobile (fenêtre et lien) ·
`94f3934` repli sur le segment propre quand l'occurrence du terme est bruitée (extraits).

ETAT: fini

## Jugement V2-10e — 01-10-2026 (juge : Claude Code)

- Relu sur la recette réelle (`a2f97de`) avec les mêmes trois recherches.
- Progrès : l'extrait est rattaché au document, au-dessus du lien « Documents semblables », et la fenêtre est centrée sur le terme. La plupart des extraits sont propres.
- Défauts restants (formes paraphrasées) :
  1. **Extrait court presque tout en bruit, toujours affiché**, du type « i xxxx 2 29.90/22 Xxxx (a Vi 58 Facture ; i ». C'est exactement la forme visée par le point 2 de V2-10e ; le terme cherché, présent dans le bruit, semble le sauver.
  2. **Coupe au milieu d'un mot** en tête (« xécution… », « y, le 16 octobre ») ou en queue (« … Grande-Rue 5 ce », « … er », « … la »).
  3. **Fragment parasite résiduel en tête** (« Post CHAG <nom> … ») et bruit au milieu d'un extrait long (« tins … er EN … », « peu or0T … situatioy' »).
- Verdict : **V2-10e non franchi.** Dernière reprise ciblée **V2-10f** : la présence du terme ne doit pas sauver un extrait bruité ; coupe aux frontières de mots ; rognage des fragments de 1 ou 2 lettres en tête et en queue.

## Journal V2-10f — dernière reprise des extraits — 02-10-2026

Agent : Codex (remplacement exceptionnel de DeepCode) · Juge : Claude Code. Reprise strictement limitée
au secteur Interface, après le jugement V2-10e : un terme surligné sauvait encore une fenêtre OCR courte
et bruitée ; des fenêtres pouvaient commencer ou finir dans un mot, et des groupes OCR résiduels restaient
visibles.

**Oracle d'abord (rouge, puis vert)**
- `tests/php/corpus/ocr-extraits.php` reçoit sept formes synthétiques supplémentaires : fenêtre courte
  bruitée malgré « Facture », deux têtes coupées, queue après numéro, groupe « Post CHAG », groupe
  « tins er EN » et mot mêlé de chiffres terminé par apostrophe. Aucune donnée réelle.
- `tests/php/ExtraitsLisiblesTest.php` vérifie aussi directement que le surlignage de « Facture » ne
  conserve aucun segment. Avant la correction, le corpus rendait les sept formes et `retenir()` gardait
  cette fenêtre ; après, les décisions et les interdits du corpus sont tous exacts.

**Fait**
1. **Terme insuffisant** (`src/Ui/ExtraitsLisibles.php`) : les fenêtres jusqu'à onze unités requièrent une
   majorité stricte de votes plausibles ; une couture numérique avec `/`, un fragment de bord et un jeton
   OCR (« xx… », « qx… », mélange lettre-chiffre-lettre) ne votent jamais favorablement. Le `hit` n'est
   volontairement pas pris en compte.
2. **Frontières** : les amorces et queues de 1–2 lettres non usuelles sont retirées jusqu'à stabilité ;
   la fin après un numéro élimine aussi le fragment tronqué. Un mot composé légitime (`Grande-Rue`) et les
   petits mots courants restent préservés.
3. **Groupes et bruit interne** : les capitales non siglées, les fragments sans voyelle, les mots mêlés
   chiffres/lettres et une apostrophe finale anormale séparent les passages propres. On n'assemble plus
   deux passages de part et d'autre du bruit ; la première phrase propre est choisie.

**Preuves**
- contrôle direct du corpus : **38/38** sorties exactement attendues ; le terme « Facture » surligné
  retourne une liste vide ;
- `php vendor/bin/phpunit tests/php/ExtraitsLisiblesTest.php --no-coverage` : **17/17 verts,
  193 assertions** ;
- `php vendor/bin/phpunit --no-coverage` : **408/408 verts, 2 310 assertions** ;
- `npx playwright test tests/e2e/mobile.spec.ts` : **2/2 verts** (iPhone 13 et Pixel 7) ; le terme
  composé de l'oracle V2-10e reste bien dans sa fenêtre propre après le bruit ;
- `powershell -File tools\harness.ps1 -SansBanc` : **0-garde-fous VERT · 1-unitaire VERT ·
  4-écrans 46/46 VERT · 7-securite VERT · 2-contrat VERT · 6-charge VERT** (p95 lecture 956 ms,
  écriture 289 ms, 0 erreur, 0 collision). Les seules lignes rouges, `3-banc` sautée par
  `-SansBanc` et `5-personas` non écrite, sont préexistantes/par construction : aucun nouveau rouge.

**Commits**
`cf05104` oracle (`ExtraitsLisiblesTest.php`, `ocr-extraits.php`) · `bb6a3d8` heuristique
(`ExtraitsLisibles.php`) · `90e6cd0` préservation des termes composés (`ExtraitsLisibles.php`).

ETAT: fini
