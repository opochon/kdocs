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
