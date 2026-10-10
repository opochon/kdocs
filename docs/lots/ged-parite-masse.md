# Lot ged-parite-masse — parité Paperless : modification en masse et écrans jamais parcourus

Agent : DeepCode · Juge : Claude (poste principal) · Base : `gedv2_test` · Fonds des oracles :
`var/oracles/parite-masse/documents`.

## Mission

1. Lire la doc Paperless-ngx (bulk edit, rubrique Usage) et adapter ses opérations au modèle GEDv2
   (le dossier fait foi, `.meta.json` à côté, zéro suppression : la suppression est une corbeille).
2. Bibliothèque : cases à cocher sur les lignes, tout sélectionner (page ET résultat filtré), barre
   d'actions en masse à gauche sous le titre (type, étiquettes +/−, émetteur, dossier, corbeille).
   Confirmation qui annonce le nombre de documents. Chaque document passe par le MÊME service que
   l'édition unitaire (`DepotFichiers::mettreAJour`/`miseALaCorbeille` : même `.meta.json`, mêmes
   versions, même trace de déplacement).
3. Droits : un document que le compte ne peut pas modifier n'est jamais modifié ; le bilan dit
   combien sont traités et combien refusés, et pourquoi. Oracle PHPUnit avec un compte manager et
   deux dossiers (un en modification, un en lecture seule).
4. Traitement long : par paquets courts avec reprise (règle I/O batch), jamais un appel PHP de
   plusieurs minutes ; 500 documents doivent passer.
5. Découvrabilité (constat du lot ged-personas, note `docs/lots/ged-personas.md`, §2) : `/metadonnees`,
   `/administration/comptes`, `/administration/groupes`, `/administration/roles` et
   `/dossiers/{id}/droits` n'ont aucun lien visible. Les ajouter à la source unique des entrées de
   menu (section Administration, visible seulement aux rôles qui y ont droit ; droits d'un dossier
   depuis la Bibliothèque), sans dupliquer de menu.
6. E2E par l'écran (cocher, choisir l'action, confirmer, vérifier la liste et la fiche) ; plus un
   parcours E2E pour chacun des 4 écrans jamais parcourus (`/administration/groupes`,
   `/administration/roles`, `/partages-avec-moi`, `/workflows/catalogue`), en y arrivant par le menu
   comme un utilisateur.

## Contexte (mesure du juge, 10.10, v2 37c7916)

GEDv2 couvre ~80 % des fonctions de Paperless-ngx 2.20 ; la modification en masse manque entièrement
et 4 écrans n'ont aucun test E2E. Cible de la ligne de matrice : « Modification en masse (type,
étiquettes, émetteur, dossier, corbeille) — niveau 9, droits respectés ».

## Avancement

### Fait (commits)
- `8a24ef3` — service `ModificationMasse` + oracle PHPUnit (refus, effets unitaires, 500 docs par
  paquets avec reprise, corbeille).
- `e4c7f00` — une seule source de menu (`Ged\Ui\Rail`) : « Partagés avec moi », section Administration
  (visible seulement à `application:administrer` ou `*`), catalogue des workflows servi en page lisible
  à un navigateur (JSON conservé pour le designer/l'API), oracle `RailMenuTest` + test du catalogue HTML.
- `b1bc516` — écran Bibliothèque : cases à cocher (`coche-doc-<id>`), « toute la page » et « tout le
  résultat filtré », barre d'actions en masse à gauche sous le titre, confirmation qui annonce le nombre,
  bilan des traités/refusés avec raisons ; routes `POST /bibliotheque/masse` et
  `POST /bibliotheque/masse/{id}/tranche` ; permissions `documents:modifier` ; lien « droits du dossier »
  depuis le dossier actif ; charges CSRF de la sonde (`tools/banc/sonde-securite.php`, fichier partagé
  signalé) ; filtres de la sélection résolus par la MÊME méthode que la liste paginée
  (`EcranBibliotheque::resoudre`/`idsFiltres`).
- (commit suivant) — E2E `tests/e2e/parite-masse.spec.ts` (5 parcours) + préparation
  `tests/e2e/preparer-parite-masse.php` : en masse par l'écran (cocher, choisir, confirmer, bilan,
  liste, fiche), « tout le résultat filtré » au-delà de la page, et les 4 écrans jamais parcourus via
  le menu (groupes, rôles, partagés avec moi, catalogue).

### En cours
- Harness `-SansBanc` complet + porte qualité ECC.

### Reste
- Rien d'autre que la porte qualité et le rejeu du harness.

## Porte qualité (ECC)

- (à compléter)

ETAT: en cours
