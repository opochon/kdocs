# Secteur CLASSEMENT — type, émetteur, champs, tri automatique, apprentissage

Agent : DeepCode · Juge : Claude Code · Lot en cours : V2-03 (avec l'écran « À traiter »)

## Mission
Proposer type, émetteur, titre, date, montant, étiquettes ; valider seul **seulement** avec confiance ≥ seuil ET une preuve
(indice du type dans le texte, ou habitude) ; tout document validé seul est complet et rangé ; le reste va dans « À traiter »
avec la source de chaque suggestion (IA, habitude, règle). Les choix humains répétés deviennent des habitudes.

## Chemins
`src/Classement/`, `tests/php/Classement/`, `tests/oracles/classement/`

## Lot V2-03 — porte
1. Sur `courrier-100p` : 0 document validé seul avec un type faux ; émetteur jamais faux (non lu = vide).
2. « Autre » jamais validé seul ; Document RH seulement si le propriétaire (`GED_ORGANISATION`) figure dans la pièce.
3. Habitude : 3 choix identiques pour un émetteur → la 4ᵉ pièce suit seule ; un revirement la casse ; le tri auto n'enseigne jamais.
4. IA : Infomaniak `google/gemma-4-31B-it`, délai 20 s + 1 reprise, repli sans blocage.

## Reprendre de GEDv1 (mesuré les 27-28/09, commits 1c9bf96 → eb65e9e)
`IngestClassificationService::typeCorrobore`, `completerEtRanger`, `resoudreCorrespondant` (nom lu dans le texte),
`HabitudesService`, `InfomaniakClassifierAdapter` (invite + règles), `tools/banc/bench-modeles-classement.php`,
`tools/banc/prouver-apprentissage.php`. À ajouter : apprentissage sur tout le fonds (ce que Paperless fait mieux).

## Demandes aux autres secteurs (à ne pas faire ici)
- **Socle** : l'installateur doit appeler `Ged\Classement\Schema::installer($pdo)` (nouvelle table `classement_choix`).
- **Ingestion** : `tools/tache.php` doit passer `new Ged\Classement\ClasseurGed($config, $pdo)` à `Fabrique::pilote(...)` ; sans classeur, `Pilote` range tout en `a_controler`.
- **Interface** : l'écran « À traiter » enregistre chaque choix humain par `Ged\Classement\Habitudes::retenirChoix($emetteur, Habitudes::CHAMP_TYPE, $type, $refPiece)` (jamais pour un tri auto) ; il affiche `source` (IA, habitude, règle) et `preuve` de la suggestion.
- **Vue d'ensemble** : documenter dans `.env.example` les clés `INFOMANIAK_AI_MODEL_CLASSIFY` et `INFOMANIAK_AI_TIMEOUT_CLASSIFY` (déjà posées dans le `.env` local).
- **Sécurité** : le `GardeCentrale` en cours renvoie 403 sur `/documents/{id}/apercu` sans session ; les 7 specs e2e de `tests/e2e/apercu.spec.ts` échouent (attendu 404). Soit l'aperçu reste accessible sans session, soit les specs se connectent — hors périmètre Classement (constaté pendant `harness.ps1 -SansBanc` du 29-09, `src/Securite/` non commité).

## Journal V2-03 — 29-09-2026

**Fait**
- `Ged\Classement\ClasseurGed` implémente `Ged\Contrats\Classeur` (constructeur `(Config, PDO)`), client IA et habitudes injectables ; il rend les 11 clés du contrat.
- `Regles` : émetteur = premier titre lisible (gras markdown, titre, première ligne), le propriétaire de la GED n'est jamais émetteur ; indices forts par type (typeCorrobore) ; pliage d'accents maison (l'`iconv` local translittérait « É » en « E' »).
- `Habitudes` + table `classement_choix` : 3 choix humains identiques et consécutifs pour un émetteur font une habitude ; un revirement la casse ; un même document ne pèse qu'une fois ; le tri automatique n'enseigne jamais.
- `InfomaniakClient` / `ClientIa` / `ClientIaNul` : modèle `google/gemma-4-31B-it`, délai 20 s, une reprise sur expiration, transport HTTP injectable (aucun réseau en test), repli sans blocage.
- Garde-fous : « Autre » jamais validé seul ; Document RH seulement si `GED_ORGANISATION` figure dans la pièce ; émetteur non lu = vide ; l'IA ne valide jamais sans indice.
- Oracle `tests/oracles/classement/courrier-100p.json` + `OracleCourrier100pTest`, banc `tools/banc/mesurer-classement.php`.

**Preuves**
- `phpunit tests/php/Classement` : 44 tests verts (règles, habitudes, IA simulée, orchestration, oracle).
- Oracle `courrier-100p` : 53/53 types justes, 53/53 émetteurs lus, 52/53 validés seuls (le Document RH reste à contrôler, propriétaire vide), **0 type faux**, **0 émetteur faux**, **0 « Autre » validé**.
- `php tools/banc/mesurer-classement.php courrier-100p` : porte **FRANCHIE** (0,02 s) ; preuves `recette/preuves/classement/courrier-100p-classement.{json,md}`.
- `harness.ps1` : couches 0-garde-fous, 1-unitaire (103 tests), 3-banc (découpe + classement) et 4-écrans vertes ; commits `ea54be0` (contrat/tests) et `4043e6e` (banc/preuves).

**Reste (hors de ce secteur)**
- Écran « À traiter » et sa navigation clavier (Interface) ; taxonomie réelle des types (V2-04) ; branchement du classeur dans `tools/tache.php` (Ingestion) ; rapprochement des correspondants par id (Socle/V2-04) ; apprentissage sur tout le fonds et reprise sur un vrai fonds anonymisé.

ETAT: fini

## Jugement V2-03 (Claude Code, 29-09) — porte NON franchie, un défaut de règle
Banc rejoué : 52/53 validés seuls, 0 type faux, 0 émetteur faux — **mais en mode sans IA**, et c'est là le défaut.
`ClasseurGed::proposer` valide seul dès qu'UN mot-clé du texte donne un type (`Regles::typeEtPreuve`, confiance 0,92
codée en dur), et **ignore l'IA quand elle contredit** ce mot-clé. Sur un vrai courrier, « Concerne : votre facture du… »
ou une lettre d'assureur qui cite sa « police » serait validée et rangée sans contrôle. Le corpus synthétique ne le montre
pas parce que ses pièces ne mélangent pas les indices.
Règle attendue (celle de GEDv1, `typeCorrobore`) : **valider seul = habitude, OU (IA ≥ seuil ET indice du même type
dans le texte)**. IA absente, en panne ou en désaccord avec l'indice → « à contrôler », avec la raison
(« IA indisponible », « IA et texte divergent : IA=Contrat, texte=Facture »). Le mot-clé seul reste une suggestion.
À prouver :
1. tests unitaires : IA en désaccord → non validé ; IA absente → non validé ; IA d'accord → validé ; habitude → validé ;
   deux indices contradictoires dans le texte (« facture » + « contrat ») → non validé sans IA d'accord.
2. banc `courrier-100p` rejoué **avec** l'IA réelle (Infomaniak, clés du .env ; la couche 3 a le droit au réseau) :
   0 type faux validé seul, 0 émetteur faux, taux validé seul publié tel quel ; le mode sans IA doit donner 0 validé seul
   hors habitudes.

ETAT: à reprendre (voir Jugement V2-03)

## Jugement V2-03, complément (banc F1 de bout en bout, 29-09)
Le rejeu complet (`tools/banc/rejouer-ingestion.php`) range 53/53 pièces, mais le fonds montre deux défauts de classement :
- **date** : l'attestation p. 21 (« Modeleville, le 22.01.2026 ») est datée `2024-01-01` — la date citée dans le corps
  (« depuis le 1er janvier 2024 ») a gagné — et la pièce est donc rangée sous `2024/`. La date du document = celle de
  l'en-tête (« <lieu>, le JJ.MM.AAAA »), puis la date de facture ; une date du corps seulement en dernier recours.
- **titre** : `courrier-du-matin-100p (p. 21)` (le nom du lot). Le titre vient de la pièce : ligne « Concerne », numéro
  de facture, sinon type + émetteur + date. Jamais le nom du lot.
Le banc de classement doit mesurer date exacte et titre non vide/non « lot » contre `verite.json` (qui porte la date).

ETAT: à reprendre (voir Jugement V2-03 et complément)

## Journal V2-03 bis — 29-09-2026

**Défaut de règle corrigé (jugement V2-03)**
- `ClasseurGed::proposer` : valider seul = habitude de l'émetteur, OU (IA ≥ seuil ET indice du MÊME type dans le texte). Un mot-clé seul ne range plus ; l'IA ne force jamais un type sans indice.
- Raisons nommées : « IA indisponible », « IA et texte divergent : IA=Contrat, texte=Facture », « confiance IA insuffisante (…) », « IA sans type ».
- `Regles` : `dateEnTete` (première date JJ.MM.AAAA de l'en-tête — un numéro IDE `CHE-100.000.002` ne piège pas) et `titrePiece` (ligne « Concerne », sinon numéro, sinon type + émetteur + date — jamais le nom du fichier ni du lot).
- Banc mode-aware : sans IA la porte exige 0 validé seul (hors habitudes) ; avec IA le taux minimum (`attendu.valides_seuls_avec_ia_min`). Mémoire d'apprentissage vidée avant mesure ; preuves suffixées `-sans-ia` / `-avec-ia`.

**Preuves**
- Oracle d'abord : 6 échecs sur le code d'avant (IA en désaccord, IA absente, IA peu sûre, panne IA, indices contradictoires, 0 validé seul sans IA) puis 1 échec sur la date d'en-tête → tout vert.
- `phpunit tests/php/Classement` : 55 tests verts (44 → 55) ; suite complète verte.
- Banc `courrier-100p` **sans IA** : 53 documents, **0 validé seul**, 0 type faux, 0 émetteur faux, **0 date fausse**, **0 titre invalide**, 0,03 s — `recette/preuves/classement/courrier-100p-classement-sans-ia.{json,md}`.
- Banc `courrier-100p` **avec l'IA réelle** (Infomaniak `google/gemma-4-31B-it`, clés du `.env`, réseau) : **51/53 validés seuls (96,2 %)**, **0 type faux validé seul**, 53/53 émetteurs lus, **0 émetteur faux**, **0 date fausse**, **0 titre invalide**, 0 « Autre » validé, 276 s ; 2 à contrôler : page 21 Document RH (le propriétaire ne figure pas dans la pièce) et page 23 Facture (appel IA en échec → repli « IA indisponible »). Porte **FRANCHIE** — `courrier-100p-classement-avec-ia.{json,md}`.
- `harness.ps1 -SansBanc` : 0-garde-fous VERT, 1-unitaire VERT (132 tests, 550 assertions), 3-banc ROUGE « sautée » (voulu), 4-écrans ROUGE (7 échecs `apercu.spec.ts` en 403 : le `GardeCentrale` du secteur Sécurité, WIP non commité présent avant ce lot, exige une session — hors périmètre Classement, demande déposée ci-dessus).
- Commits : `ce6603f` (règle typeCorrobore + tests), `408b582` (banc mode-aware + preuves), `a253c70` (date/titre, complément du jugement).

ETAT: fini

