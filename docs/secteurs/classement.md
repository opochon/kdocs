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
