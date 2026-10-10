# Lot ged-droits-deplacement — F10 : un changement de métadonnées n'ouvre jamais un dossier sans droit de dépôt

Secteur porteur : Droits & Sécurité. Juge : Claude (poste principal). Agent : DeepCode.

## Mission rappelée

Un changement de métadonnées (`type`, `émetteur`, `date`, `dossier` explicite) recalculait le rangement
et DÉPLAÇAIT le fichier. Les droits étaient vérifiés sur le dossier de DÉPART (`modifier`), jamais sur le
dossier d'ARRIVÉE : un manager avec `modifier` sur A pouvait faire entrer un document dans B (par la
fiche/service, « À traiter », la modification en masse, un workflow) sans y avoir aucun droit.

Règle appliquée : toute écriture qui change le CHEMIN exige le niveau `deposer` sur le dossier de droits
d'ARRIVÉE (héritage du parent compris, refus par défaut sans règle) ; administrateur toujours permis. Le
contrôle vit à UN seul endroit, dans le service, AVANT le premier octet déplacé ; le compte de l'appelant
est passé explicitement. Un refus est nommé (dossier visé, niveau manquant) et ne bouge rien.

## Fait (commits)

- (en cours) Oracle d'abord :
  `tests/php/Securite/DeplacementDroitsTest.php` écrit ROUGE (5 refus attendus non produits, 3 acceptations
  déjà vertes par absence de contrôle), puis implémentation, puis **VERT (9 tests, 82 assertions)**.

## En cours

- Suite PHPUnit complète + PHPStan + E2E ; journal et commits d'étape.

## Reste

- E2E Playwright : la modification en masse affiche le refus d'arrivée pour le document concerné.
- Porte qualité (PHPStan, revue ECC) et `harness.ps1 -SansBanc` vert sur les couches touchées.
- Commits par étape verte, jamais `git add -A`.

## Mesures exactes (au fil de l'eau)

- PHPStan niveau 6 : 0 erreur (baseline vide).
- `DeplacementDroitsTest` : 9 tests, 82 assertions, vert.
- `EditionPiecesTest` (comportement réordonné) : 1 test, 19 assertions, vert.
- `ModificationMasseTest` (setup adapté au contrôle d'arrivée) : 4 tests, 90 assertions, vert.
