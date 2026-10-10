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
- (à compléter au fil des commits)

### En cours
- Reconnaissance (contrats, secteurs, routes, harness) terminée ; note de lot ouverte.
- **Oracle rouge puis vert** : `tests/php/Socle/ModificationMasseTest.php` (4 tests, 86 assertions) —
  refus d'un document en lecture seule (bilan nommé), mêmes effets que l'édition unitaire (trace
  `document_deplacements`, `.meta.json`), 500 documents par paquets de 50 **avec reprise** (curseur
  dans `.ged/`, service neuf à chaque tranche), corbeille = mise en corbeille (fichier sous
  `.corbeille/`, ligne drapeautée).
- **Service** `src/Socle/ModificationMasse.php` : actions `type`, `emetteur`, `etiquettes_ajouter`,
  `etiquettes_retirer`, `dossier`, `corbeille` ; chaque document passe par
  `DepotFichiers::mettreAJour`/`miseALaCorbeille` ; ACL `modifier` vérifiée par document ; journal
  d'audit `modification_masse` en ajout seul ; `lancer`/`avancer`/`etat`, curseur reprenable.

### Reste
- UI Bibliothèque (cases, page/résultat filtré, barre en masse, confirmation, bilan), routes + permissions.
- Menu/découvrabilité (Administration par droits, Partagés avec moi, catalogue, droits d'un dossier).
- E2E (parcours en masse + 4 écrans via le menu), harness, porte qualité.

## Porte qualité (ECC)

- (à compléter)

ETAT: en cours
