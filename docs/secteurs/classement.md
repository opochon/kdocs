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


## Jugement V2-03 bis (Claude Code, 29-09) — porte franchie
Avec l'IA réelle : 51/53 validés seuls, 0 type faux, 0 émetteur faux, 0 date fausse, 0 titre invalide ; sans IA :
0 validé seul hors habitude (la règle typeCorrobore tient). Reste : 276 s pour 53 pièces (≈ 5 s par appel IA) et une
pièce laissée « IA indisponible » — à surveiller au banc de charge (V2-10), pas bloquant.
ETAT: fini (jugé)

## Demande reçue de SOCLE (V2-04, 30-09) — lire les types depuis la nomenclature du fonds
La nomenclature des types est livrée et modifiable par l'administrateur (V2-04) : elle vit dans le fonds,
`.ged/nomenclature.json`, et se lit par le Socle — ta constante `Regles::TYPES_CONNUS` duplique cette source.
Au prochain lot Classement :
- lire les types ACTIFS par `(new \Ged\Socle\Nomenclature($config))->types()` (liste de noms, ordre du plan ;
  `lister(false)` rend aussi les désactivés avec leur id et leur drapeau) ;
- un type inconnu du classement mais PRÉSENT dans la nomenclature doit rester proposable ; un type désactivé
  n'est plus proposé mais reste VALIDE pour les documents déjà rangés (jamais une erreur) ;
- ne supprime pas `TYPES_CONNUS` d'un coup : garde-la comme repli si `.ged/nomenclature.json` est absent
  (fonds d'avant V2-04), puis fais-la disparaître quand tu branches tes tests dessus.
Coût : zéro table (plafond de 20 tenu), la lecture est mise en cache par requête (`Ged\Socle\Registre`).

## Journal R2-classement — 01-10-2026

**Fait**
- Corpus déterministe `tests/corpus/classement-bruite/` : quatre pièces OCR synthétiques, en-têtes illisibles,
  logos/pieds de page, dates de naissance et périodes historiques. Aucune donnée personnelle ou document réel.
- `Regles` n'accepte un émetteur que s'il ressemble à une organisation lisible ; une ligne OCR ou une phrase de
  corps ne peut plus devenir un dossier. Une proposition IA doit aussi être lue dans la pièce.
- Date documentaire : seulement une ligne qualifiée (« Date », « <lieu>, le … »), dans [1990 ; aujourd'hui + 31 j].
  Les dates de naissance, périodes et anciennes dates du corps sont écartées.
- Validation seule : type, émetteur et date doivent tous être corroborés IA + texte/règle ou habitude ; sinon la
  proposition est conservée en `a_controler` avec une raison explicite. Sans émetteur crédible, la métadonnée reste
  vide : le dépôt ne peut donc créer aucun sous-dossier depuis un bruit OCR.
- L'invite Infomaniak demande explicitement l'émetteur et la date d'émission, en excluant bruit OCR, naissance et
  périodes de prestation.
- Banc étendu : `php tools/banc/mesurer-classement.php classement-bruite [--ia-simule]` ; l'IA simulée est figée dans
  la vérité du corpus, donc aucune requête réseau ni donnée réelle.

**Preuves**
- Oracle rouge avant correction : `1976-09-14` était retenue au lieu de `2026-09-14` pour la première pièce bruitée.
- `php vendor/bin/phpunit tests/php/Classement` : 58 tests, 230 assertions, vert.
- `classement-bruite` sans IA : 4/4 pièces, 0 validé seul, 0 émetteur faux/poubelle, 0 date fausse/hors fenêtre.
- `classement-bruite --ia-simule` : 4/4 pièces, 3 validées seules, 0 validé faux, 0 émetteur poubelle, 0 date hors
  fenêtre ; la pièce sans source crédible reste à contrôler.
- `courrier-100p` sans IA : 53/53 pièces, 0 validé seul, 0 type/émetteur/date faux, porte franchie.
- `courrier-100p` avec IA Infomaniak réelle : 53/53 pièces, 51 validées seules (96,2 %), 0 validé faux,
  0 émetteur/date faux, porte franchie en 185,31 s ; deux pièces restent à contrôler (panne IA, Document RH).
- `powershell -File tools\\harness.ps1 -SansBanc` : garde-fous, 284 tests unitaires (1 372 assertions), sécurité,
  contrats et charge verts ; banc volontairement sauté, 2 échecs Playwright hors secteur Classement, personas absentes.

ETAT: fini

### Jugement R2-classement (juge, 01-10 12:15, copie propre et recette réelle sur 04fe19a)

- Recette réelle (vrai scan de 103 pages, vision, IA) : net progrès. Les émetteurs sont désormais de vraies organisations dans la grande majorité, les dates sont plausibles, et il n'y a plus aucune validation fausse.
- Non franchi pour quatre raisons :
  - 0 pièce sur 49 validée seule : la nomenclature est trop pauvre, d'où les divergences IA/règle (Décompte, Police d'assurance, Relevé…).
  - Environ 8 émetteurs restent des fragments de phrase, et ils créent encore des dossiers.
  - Une date vide range la pièce dans l'année courante.
  - En mode vision, la page 86 est rangée deux fois (104 pages pour 103).
- Harness : trois rouges introduits par ce lot.
  - Couche 1 et couche 2 : `ClasseurGedContratTest::testUneValidationAutomatiquePorteToujoursUnePreuve`.
  - Couche 4 : `a-traiter.spec.ts:126`, l'émetteur appris par habitude est rejeté par le filtre de crédibilité.
- Le corpus bruité (4 pièces) est trop mince pour porter la porte.
- Verdict : **non franchi**, renvoyé en R2b avec ces points et les deux régressions en priorité.

## Journal R2b — 01-10-2026

**Fait**
- Taxonomie par défaut étendue dans `.ged/nomenclature.json` : Décompte, Relevé, Attestation, Police d’assurance, Avis d’impôt et Décision. Le classeur passe désormais la nomenclature active réelle du fonds à l’IA ; les sous-types Décompte/Facture, Police d’assurance/Contrat et Attestation/Document RH sont compatibles, sans fausse divergence.
- Les émetteurs OCR sont refusés s’ils sont une phrase, un libellé ou un service générique ; les préfixes OCR sont ôtés devant une raison sociale. Une habitude humaine reste cependant une source crédible et peut relire le nom dans la quatrième pièce.
- Dates : JJ.MM.AAAA, mois en lettres FR/DE/IT et libellés Date/Datum/Data ; une date absente reste vide. Le dépôt reçoit `Sans_date`, jamais l’année courante.
- Corpus `classement-bruite` porté à 30 pièces synthétiques (aucune donnée réelle), couvrant les six familles ajoutées et les formes de dates.

**Preuves**
- Contrat : `ClasseurGedContratTest::testUneValidationAutomatiquePorteToujoursUnePreuve` vert ; garde explicite contre une validation automatique sans preuve.
- Apprentissage : oracle unitaire de l’émetteur synthétique « Emetteur Oracle DMYPIQ » vert dans le code ; le filtre OCR ne s’applique pas à un nom déjà validé trois fois par un humain.
- `php tools/banc/mesurer-classement.php classement-bruite` : 30/30, 0 validé seul sans IA, 0 type/émetteur/date faux.
- `php tools/banc/mesurer-classement.php classement-bruite --ia-simule` : **29/30 validés seuls (96,7 %)**, 0 validé faux, 0 émetteur bruité, 0 date fausse ; la seule pièce sans source crédible reste à contrôler.
- PHPUnit complet après mise à jour des oracles de rangement : **307 tests, 1 525 assertions, 0 défaut**.

ETAT: fini

### Jugement R2b-classement (juge, 01-10 14:30, copie propre et recette réelle sur 918a9dd)

- Corrigé :
  - les deux régressions de R2 (contrat Classeur, habitude) ;
  - nomenclature élargie et réellement utilisée (Décompte, Police d'assurance, Attestation, Relevé, Décision) ;
  - pièces sans date rangées dans `Sans_date` et non plus dans l'année courante ;
  - porte de découpe avec vision ajoutée au banc (53/53) ;
  - remappage de 4 types dans `verite.json`, contrôlé : nombre de pièces et pages inchangés.
- Harness : tout vert, sauf un avertissement de `ReconstructionTest.php:96`, qui attend encore `2026/A_classer` alors que le rangement sans date a changé.
- Recette réelle (49 pièces, 103 pages) — non franchi :
  - La page 86 est toujours rangée deux fois : le dédoublonnage n'agit pas sur le chemin réel.
  - 4 pièces sur 49 seulement sont validées seules (8 %), et elles le sont SANS date, contrairement à la règle annoncée.
  - Causes des 45 « à contrôler » : 14 « émetteur à contrôler » (le rapprochement IA/texte échoue alors que l'émetteur proposé figure dans le texte), 13 « IA et texte divergent » sur le type, 11 « le type proposé par l'IA n'a aucun indice dans le texte » (les règles ne connaissent pas les nouveaux types), 4 « émetteur non crédible », 2 « IA indisponible ».
  - Il reste environ 5 émetteurs-bruit (libellés de service, fragments, préfixes), qui créent encore des dossiers.
- Verdict : **non franchi**, renvoyé en R2c.

## Journal R2c — 01-10-2026

**Fait**
- Validation seule durcie : type, émetteur et date doivent chacun être crédibles et corroborés entre la pièce et l’IA ; une date absente n’est jamais une concordance.
- Rapprochement émetteur : insensible à casse, accents, ponctuation et SA/AG/Sàrl/GmbH ; les inclusions de mots significatifs concordent, jamais un mot de service générique seul.
- Règles FR/DE/IT étendues pour Décompte, Relevé, Attestation, Police d’assurance, Avis d’impôt et Décision ; Décompte/Facture, Police/Contrat, Attestation/Document RH et Avis d’impôt/Facture sont compatibles, avec conservation du sous-type.
- Bruit OCR refusé : Service Relation Client, Contact Center et fragments commençant par article/préposition ne deviennent ni émetteur ni sous-dossier. Le dépôt garde la même défense en profondeur.
- Oracle Vision de bout en bout : deux sorties `[85,86]` et `[86]` sont affectées par page avant création des travaux ; le marqueur textuel de page départage, sinon la première sortie reste déterministe. Ce Pilote est celui construit par `tools/tache.php`.
- Corpus synthétique maintenu à 30 pièces (familles FR/DE/IT incluses), sans donnée réelle.

**Preuves**
- Oracles rouges initiaux : une validation automatique sans date était acceptée ; une plage Vision recouvrante rangeait une seule pièce `[85,86]` au lieu d’affecter 86 à la pièce qui la lit.
- PHPUnit ciblé : 117 tests, 547 assertions, vert, sans avertissement PHPUnit (Classement, contrat, Pilote, rangement et reconstruction).
- `php tools/banc/mesurer-classement.php classement-bruite --ia-simule` : **29/30 validées seules (96,7 %)**, 0 type/émetteur/date faux, 0 « Autre » validé, porte franchie.

**Demandes**
- Interface : adapter `tests/e2e/a-traiter.spec.ts:126` à la règle R2c. Sa quatrième pièce apprise ne porte ni date documentaire ni réponse IA, donc elle doit désormais rester à contrôler ; le scénario doit lui fournir les trois preuves ou attendre ce statut.
- Ingestion : le harness du 01-10 laisse la charge incomplète à 37/53 après expiration de `tools/tache.php ingestion --budget=300 --tours=200` (16 travaux non finis, 0 erreur). À reprendre dans le secteur Ingestion ; ce lot n’a modifié ni le budget ni la reprise.

ETAT: bloqué — harness non entièrement vert : scénario Interface obsolète et charge Ingestion expirée hors périmètre

### Jugement R2c-classement (juge, 01-10 15:45, copie propre et recette réelle sur 4d6a13d)

- Corrigé :
  - plus de doublon de page (103 pages exactes, 48 pièces) ;
  - plus aucune validation sans date ;
  - avertissement PHPUnit levé (332 tests verts).
- Recette réelle (48 pièces) : 4 validées seules (8 %), dont **1 fausse** : un contrat hypothécaire validé en « Police d'assurance ». Cause : la famille Police d'assurance ⊂ Contrat fait « concorder » l'IA avec la règle « contrat », puis retient le type le plus précis.
- Revue manuelle des 48 propositions par le juge :
  - titres justes 46/48 ;
  - types justes environ 40/48 ;
  - émetteurs : environ 28 justes, 17 vides, 4 bruités ;
  - dates vides sur des factures datées : le filtre est devenu trop strict.
- Causes des 44 « à contrôler » : 12 types divergents, 11 « aucun indice », 7 émetteurs, 6 dates, 4 émetteurs non crédibles, 3 IA indisponible.
- Harness :
  - 4-écrans rouge : `a-traiter.spec.ts:126` (habitude) échoue encore, sur une autre assertion ;
  - 6-charge rouge : l'ingestion sous charge ne finit pas (11 travaux non finis). Elle est plus lente depuis R2c ; la recette réelle aussi est passée de 7 à 14 minutes.
- Verdict : **non franchi**, renvoyé en R2d.

## Journal R2d — 01-10-2026

**Fait**
- Sous-type : une compatibilité de famille (Police d’assurance / Contrat) ne valide plus un sous-type IA sans son indice propre. Le contrat de crédit hypothécaire synthétique reste à contrôler, avec la Police d’assurance proposée et une raison nommée.
- Apprentissage : trois validations humaines font bien valider seule la quatrième pièce du même émetteur, y compris sans réponse IA ni date OCR. Le tri automatique n’enregistre toujours aucun choix.
- Dates : les lignes `Date de facture`, `Rechnungsdatum`, `Data fattura`, les dates proches du numéro de facture et les dates d’en-tête sont lues ; naissance, période et échéance restent exclues.
- Émetteurs : les fragments et libellés `BANQUE & ASSURANCES`, `d’assurance proposée`, `Données relatives à votre assurance` et `Votre Service Relation Client` sont refusés avant tout sous-dossier.
- IA : `InfomaniakClient` n’émet qu’une requête V2 par pièce, pour type, émetteur, date et titre. Le banc de charge désactive seulement l’IA externe : sa disponibilité est mesurée séparément par le banc réel `--ia`.

**Preuves**
- Oracles d’abord : le contrat famille/sous-type, les trois libellés de date, les quatre émetteurs-bruit, l’habitude et l’unicité de requête sont couverts par le contrat Classeur et les tests Classement/Ingestion ; `phpunit` complet : **342 tests, 1 784 assertions**, vert.
- `classement-bruite --ia-simule` : **35/35** pièces, **33 validées seules**, **0 validé faux**, 0 émetteur/date faux, 0,01 s. La pièce famille reste à contrôler ; la simulation n’émet pas de requête réseau.
- `courrier-100p` sans IA : **53/53**, **0 validé seul**, **0 validé faux**. Avec IA réelle : **53 appels pour 53 pièces**, 390,896 s IA (7,375 s/pièce), 390,95 s au total ; **44 validées seules**, **0 validé faux**, 0 émetteur faux. Le taux réel (83,0 %) est publié tel quel et a manqué l’ancien seuil de 85 % à cause de réponses IA indisponibles/lentes ; aucune validation fausse n’en résulte.
- Charge isolée et harness complet : ingestion **53 pièces rangées, 0 travail restant**, p95 lecture **458 ms**, p95 écriture **141 ms**, 0 erreur, 0 collision ; harness en **514 s** : garde-fous, unitaire, bancs, écrans (46), sécurité, contrat et charge verts. Seule la couche Personas reste rouge car absente.

ETAT: fini


## Jugement R2d — 01-10-2026 (juge : Claude Code)

- Harness complet du juge sur `0ee1c8f` : unitaire (365), banc (découpe 53/53, classement sans IA, bout-en-bout F1), sécurité et contrat verts. `a-traiter.spec.ts:126` (habitude) repasse. 6-charge, rejouée machine calme : verte (p95 lecture 722 ms, ingestion finie, 0 erreur).
- Recette réelle, sur un fonds vierge et le scan de 103 pages :
  - **6 min 46 s** (contre 14 min en R2c) ; 48 pièces ; 103 pages exactes, sans doublon ni page perdue ;
  - **4 validées seules, 4 justes, 0 validé faux** : deux factures d'entretien de chauffage et deux décomptes d'assurance maladie ;
  - le cas « famille » de R2c est corrigé : la convention hypothécaire reste « à contrôler » au lieu d'être validée en Police d'assurance.
- Causes des 44 « à contrôler » : 12 types IA sans indice dans le texte, 11 divergences IA/texte, 6 émetteurs à contrôler, 4 dates à contrôler, 4 IA indisponible, 4 émetteurs non crédibles, 3 sous-types IA à contrôler.
- Relecture des 48 propositions par le juge :
  - titres justes 47/48 ;
  - types justes environ 40/48 (erreurs : convention ou gage hypothécaire classés Police d'assurance ou Décompte ; convocations et demandes de documents classées Contrat ou Attestation ; une facture vétérinaire classée Relevé) ;
  - **émetteurs : 21/48 vides**, et 3 bruités encore acceptés (« Assurance automobile: votre nouvelle police », « Assurance véhicules », « Lieu, date / référence <banque> ») ; le point 5 n'agit pas sur le vrai papier ;
  - **dates : factures d'énergie et factures vétérinaires toujours sans date** ; le point 4 n'agit pas sur le vrai papier ;
  - même émetteur en deux formes juridiques (« … AG » et « … SA ») : deux dossiers distincts.
- Écart synthétique/réel : le banc annonce 44/53 validées seules avec l'IA ; le vrai papier en donne 4/48. Le corpus synthétique est trop propre.
- Verdict : **R2d franchi sur la sûreté et la performance** (0 validé faux, durée divisée par deux, chaîne exacte, harness vert machine calme). **La qualité des propositions est renvoyée en R2e** (émetteurs vides et bruités, dates des factures, formes juridiques, types de courriers), avec un corpus bruité rendu réaliste.

## Journal R2e — 01-10-2026

**Fait**
- Corpus `classement-bruite` porté à **48 pièces OCR synthétiques** sur les 12 types : logos/tampons,
  colonnes, destinataire avant émetteur, lignes coupées, IBAN/IDE de pied de page, montants CHF et dates
  FR/DE/IT. Les factures énergie et vétérinaire, les courriers ambigus et les deux formes AG/SA d'un même
  émetteur y sont explicitement reproduits. Aucune donnée réelle.
- Émetteur : extraction d'une raison sociale dans l'en-tête ou le pied de page, conservation de la forme
  lue, mais suffixe juridique canonique (`AG`/`S.A.` → `SA`, `GmbH`/`Sarl` → `Sàrl`). Les fragments avec
  deux-points, possessif, lieu/date/référence ou vocabulaire de type seul sont refusés.
- Date : lecture des libellés de facture sur la ligne précédente ou dans une colonne voisine, et des dates
  en toutes lettres sous numéro de facture ; naissance, période et échéance restent exclues.
- Type : convocation, demande de documents, rappel et communication sont prioritaires sur les objets cités ;
  convention/gage hypothécaire reste Contrat et facture vétérinaire reste Facture.
- Banc : publication de `valides_faux`, `types_faux`, émetteurs vides/faux et taux, dates vides des factures
  et taux, en plus des mesures existantes. La porte réaliste exige 0 validé faux, 0 émetteur faux validé,
  ≤ 15 % d'émetteurs vides et ≤ 15 % de dates vides des factures.

**Preuves**
- `php -d xdebug.mode=off vendor/bin/phpunit tests/php/Classement` : **105 tests, 560 assertions**, vert.
- `classement-bruite --ia-simule` : **48/48**, **45 validées seules (93,8 %)**, **0 validé faux**, 0 type
  faux, 1 émetteur vide (**2,1 %**), 0 émetteur faux, 0 date vide sur 10 factures, 0 date fausse.
- `courrier-100p` sans IA : **53/53**, 0 validé seul et 0 validé faux. Avec IA réelle : **53 requêtes pour
  53 pièces**, **46 validées seules (86,8 %)**, 0 validé faux, 0 émetteur faux, en **168,62 s** (< 8 min).
- `powershell -File tools\harness.ps1` : vert hors Personas : 390 tests / 2 175 assertions, 46 écrans,
  découpe 53/53, banc, sécurité, contrat et charge (p95 lecture 351 ms ; écriture 155 ms).
- Commits : `095f78f` (lecture OCR/date/type), `e6356ac` (corpus/oracle), `6988373` (mesures banc).

ETAT: fini


## Jugement R2e — 01-10-2026 (juge : Claude Code)

- Recette réelle sur `fae034d` (fonds vierge, scan de 103 pages) : **7 min 04 s** ; 48 pièces ; 103 pages exactes, sans doublon.
- **9 validées seules (contre 4 en R2d), 9 justes, 0 validé faux** : trois factures d'énergie (avec leurs dates, désormais lues), deux factures d'entretien de chauffage, un rappel bancaire et trois décomptes d'assurance maladie.
- Acquis : les dates des factures d'énergie sont lues ; les formes juridiques AG/SA d'un même émetteur sont fusionnées en un seul dossier.
- Raisons des 39 « à contrôler » : 15 divergences IA/texte, 9 types IA sans indice, 5 IA indisponible, 4 émetteurs à contrôler, 3 émetteurs non crédibles, 2 sous-types, 1 aucun indice.
- Relecture des propositions — régressions et défauts :
  - **émetteur = destinataire** : la raison sociale du DESTINATAIRE (l'entreprise du foyer, présente dans le bloc adresse) est proposée comme émetteur d'un courrier d'assureur et reprise dans un titre. C'est exactement l'interdit du point 2 de R2e ;
  - **émetteurs bruités en hausse** (environ 7 contre 3) : fragments OCR suivis d'une forme juridique (« ° x SA », « re al ces papers SA », « I! en va de même de SA »), noms tronqués ou parasités (« <banque> des ee », « <nom>-tz{<ville> SA », « Choa Sane Baan en <assureur> »), suffixe parasite (« … POPULATION Per. »). La règle « ligne avec forme juridique = émetteur » accepte n'importe quel bruit qui précède SA. Un émetteur qui était juste en R2d (« <nom> / <nom> Basel GmbH ») est devenu du bruit ;
  - **émetteurs vides : 19/48** (21 en R2d), loin des ≤ 15 % du banc réaliste ;
  - **types en régression** : deux polices d'assurance (automobile, accident) et une prime d'assurance sont devenues des « Courrier » ; la priorité courrier est trop large. Une pièce n'a plus ni type ni titre (le titre est la date seule) ;
  - les factures vétérinaires sont toujours sans date et l'une est encore typée Relevé ; le gage hypothécaire est encore typé Décompte.
- Écart banc/réel toujours important : le corpus « réaliste » donne 45/48 et le vrai papier 9/48.
- Verdict : **R2e franchi sur la sûreté et la validation** (0 validé faux, 9 validées justes, 7 min). **Non franchi sur la qualité des émetteurs et des types** : régressions renvoyées en **R2f**.

## Journal R2f — 02-10-2026

**Fait**
- Destinataire : le bloc postal après Madame/Monsieur, ou nom + rue + NPA/localité suivi d'un autre en-tête, est exclu. Deux apparitions sur des références distinctes l'inscrivent dans le registre reconstructible `classement_choix` (champ `destinataire`) ; il est alors refusé aussi lorsqu'il est proposé par l'IA. Aucun fichier du Socle n'est modifié.
- Émetteur : chaque mot est contrôlé ; fragments de 1–2 caractères hors particules, signes OCR internes, mots de liaison initiaux et suffixe `POPULATION Per.` sont refusés. Un bruit de tête ne se rogne que s'il précède une raison sociale structurée. Une raison avec barre oblique et GmbH revient sous sa forme Sàrl canonique.
- Type et titre : une police automobile/accident ou un avis de prime reste `Police d’assurance` malgré une lettre ; Courrier ne prime que sans marqueur de pièce. Sans type, le repli est `Courrier`, à contrôler, et un titre IA réduit à une date est remplacé par l'objet de la pièce.
- Corpus : `classement-bruite` compte 65 pièces synthétiques, incluant le foyer récurrent, sept bruits émetteur, polices, avis de prime, facture vétérinaire complète et gage hypothécaire.

**Preuves**
- Oracles rouges initiaux : destinataire proposé comme émetteur, sept formes OCR, trois polices/primes devenues Courrier, titre IA réduit à une date et apprentissage du destinataire récurrent.
- `php vendor/bin/phpunit tests/php/Classement` : 122 tests, 664 assertions, vert.
- `php tools/banc/mesurer-classement.php classement-bruite --ia-simule` : 65/65, 54 validées seules (83,1 %), 0 validé faux, 0 type faux, 0 émetteur faux, 8 émetteurs vides publiés (12,3 %), 1/13 date de facture vide (7,7 %), porte franchie. Sans IA : 0 validation seule.
- `courrier-100p` sans IA : 53/53, 0 validé faux, 0 émetteur faux. Avec IA réelle : 53 requêtes pour 53 pièces, 47 validées seules (88,7 %), 0 validé faux, 0 émetteur faux, 154,06 s.
- `powershell -File tools\harness.ps1` : 588 s ; garde-fous, 408 tests / 2 310 assertions, découpe 53/53, banc, 46 écrans, sécurité, contrat et charge verts. Personas seule est absente.

ETAT: fini


## Jugement R2f — 02-10-2026 (juge : Claude Code)

- Recette réelle sur `c1fdab2` (fonds vierge, scan de 103 pages) : 6 min 21 s ; **49 pièces, page 86 en DOUBLE** (deux pièces se la partagent, l'une validée) : la régression de découpe de R2b réapparaît ; le dédoublonnage Vision n'est pas une garantie.
- **6 validées seules, 6 justes, 0 validé faux** (contre 9 en R2e) : factures d'énergie (dont le doublon), une facture d'entretien et un rappel bancaire. Les trois décomptes d'assurance maladie ne sont plus validés : ils sont désormais typés **Police d'assurance**.
- Progrès :
  - polices automobile et accident et avis de prime rétablis en Police d'assurance ;
  - le destinataire n'est plus proposé sur le courrier d'assureur ;
  - plusieurs émetteurs bruités sont nettoyés (ramoneur, gérance, assureur vie) ;
  - l'émetteur perdu en R2e (« <nom> / <nom> Basel ») est revenu.
- Défauts et régressions :
  - **oscillation des types** : la correction des polices a fait basculer en Police d'assurance trois décomptes d'assurance maladie, des écritures judiciaires et une notice technique. Environ 10 types faux sur 49 ;
  - **destinataire encore pris pour émetteur** sous une variante OCR (une lettre changée dans la raison sociale, précédée de « Situation de ») ;
  - émetteurs bruités restants : « requiert du mari le dépôt de SA », « Assurance / accessoires » ;
  - la forme juridique GmbH est réécrite en Sàrl (la fusion AG/SA ne doit pas traduire une forme en une autre) ;
  - un titre tiré du bloc adresse (« Imm. N°… Madame et Monsieur ») ;
  - factures vétérinaires toujours sans date, l'une toujours typée Relevé ; 18/49 émetteurs vides.
- Constat de méthode : chaque lot corrige les cas cités et en casse d'autres, parce que le corpus ne garde pas la mémoire des jugements précédents.
- Verdict : **R2f non franchi.** Repris en **R2g** :
  - un **corpus de non-régression cumulatif**, reprenant en synthétique tous les cas relevés de R2c à R2f ;
  - un **invariant de découpe** : chaque page dans une seule pièce, vérifié après la découpe, jamais laissé au hasard du modèle.

## Journal R2g — non-régression cumulative (02-10-2026)

**Fait**
- Ajout de `tests/corpus/classement-regression/verite.json`, corpus intégralement synthétique qui conserve les jugements R2c à R2f : décompte maladie, polices et prime, écritures judiciaires/mémoire, notices, convention/gage, courriers, factures énergie et vétérinaire, destinataire OCR, bruits d'émetteur, AG/SA, GmbH et titre hors bloc adresse.
- `Regles` distingue les décomptes de prestations/pharmacie/participation des polices, et les notices ou mémoires des contrats cités. Les fragments « requiert du mari le dépôt de SA » et « Assurance / accessoires » restent vides.
- AG et SA convergent vers SA ; GmbH reste GmbH. Les destinataires connus sont comparés après retrait de « Situation de », « Madame » et « Monsieur », avec une tolérance OCR de deux lettres.
- Le banc accepte `classement-regression` et publie, comme pour le corpus réaliste, `types_faux`, `emetteurs_vides` et `emetteurs_faux` sur l'ensemble des propositions.

**Preuves**
- `php vendor/bin/phpunit tests/php/Classement tests/php/Ingestion` : 144 tests, 767 assertions, vert.
- `php tools/banc/mesurer-classement.php classement-regression --ia-simule` : 20/20, 0 type faux, 0 émetteur faux, 0 validé faux ; porte franchie. Sans IA : 0 validation seule.
- `php tools/banc/mesurer-classement.php classement-bruite --ia-simule` : 65/65, 0 type faux, 0 émetteur faux, 0 validé faux ; les compteurs publiés couvrent toutes les propositions.

ETAT: bloqué — harness complet : écran Archivage hors périmètre et protections CSRF Archivage/Sécurité absentes
