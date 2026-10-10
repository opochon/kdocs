# Lot ged-fuite-bibliotheque — F10 : 0 fuite sur liste, recherche, aperçu, API

Correctif de sécurité prioritaire. Juge : Claude (poste principal). Agent : DeepCode.

## Contexte (constat du juge, harness du 10.10, commit 16482d5)

`tests/e2e/droits-b6.spec.ts:34` échoue : un compte UTILISATEUR sans droit sur le dossier B voit la
ligne du document de B dans la Bibliothèque (`ligne-<id>` : attendu 0, reçu 1). La recherche, l'aperçu
et le fichier refusent bien ; la Bibliothèque non. Le filtrage des droits de dossier (V2-13, point B3 :
dans la requête SQL, jamais après coup) n'a pas été appliqué à la Bibliothèque.

## Méthode

1. Oracle rouge PHPUnit (compte réel, deux dossiers A/B, une règle de lecture sur A) : la liste de la
   Bibliothèque ne contient que les documents de A.
2. Correction à la source : prédicat de droits réutilisable (`DroitsDossiers` : héritage du parent,
   refus explicite gagnant, administrateur voit tout) introduit DANS les requêtes.
3. Même preuve pour toutes les autres listes : vues, À traiter, partages avec moi, compteurs du rail
   et des vues, navigation par dossier, suggestions (types de recherche), API, export.

## Fait

- (en cours) oracle rouge Bibliothèque.

## En cours

- Oracle rouge PHPUnit Bibliothèque.

## Reste

- Prédicat `DroitsDossiers` + filtrage SQL de la Bibliothèque (liste, compteurs, vues, arborescence, aperçu).
- Autres listes : À traiter, partages avec moi, compteur du rail, suggestions/types de recherche, API, export.
- `tests/e2e/droits-b6.spec.ts` vert 3× seul, puis `harness.ps1 -SansBanc`.
- Porte qualité ECC (PHPStan, revue du diff, TDD) + journal.

## Mesures exactes

- (en cours)
