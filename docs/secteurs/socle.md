# Secteur SOCLE — dossier, métadonnées, versions, index, installateur

Agent : GLM · Juge : Codex · Lot en cours : V2-01

## Mission
Le dossier fait foi. Chaque document = un fichier + `<fichier>.meta.json` à côté ; versions dans `.versions/` à côté du
fichier ; la base MariaDB est un index que l'on peut **supprimer puis reconstruire à l'identique** depuis le dossier.

## Chemins (seuls modifiables par ce secteur)
`src/Socle/`, `database/`, `tools/installer.php`, `tests/php/Socle/`, `tests/oracles/socle/`

## Lot V2-01 — porte (rejouée par le juge)
1. Installateur : `php tools/installer.php gedv2_test --admin=admin` pose un schéma neuf (≤ 20 tables au départ), refuse
   une base peuplée sans `--remplacer` (et fait alors un dump d'abord).
2. `Socle\Depot::deposer(fichier, métadonnées)` range le fichier, écrit le `.meta.json`, indexe.
3. Modifier le fichier hors application → au passage suivant de l'indexeur, v2 archivée dans `.versions/`, v1 intacte.
4. Déplacer/renommer un dossier hors application → l'index suit (la base suit le dossier, jamais l'inverse).
5. `php tools/reconstruire-index.php` : base vidée puis reconstruite depuis le dossier = même contenu (hash de l'export).
6. Aucun `DELETE` sur une donnée utilisateur : corbeille = dossier `.corbeille/` + drapeau, restauration testée.

## Reprendre de GEDv1 (audit 29-09, `GEDv1/var/audit-v2/deepcode-stockage.md`)
- `app/Services/FilesystemIndexer.php` : indexation, dédoublonnage par hash, `.versions/` (fonctionne, prouvé) — porter,
  en corrigeant la liste d'extensions (xls, xlsx, odt, rtf manquaient).
- `app/Services/StoragePathGenerator.php` + `ConsumeFolderService::createAndMoveToPath()` : rangement année/type/émetteur,
  assainissement des noms Windows.
- `app/Services/Storage/StorageRootResolver.php` : racines multiples par préfixe le plus long.
- `app/Services/DocumentMovementService.php` + `tests/integration/test_tracabilite_deplacement.php` : trace des déplacements.
- `tools/schema/*` : l'idée (schéma de référence + vérification colonne par colonne), pas les 126 tables.
- NE PAS reprendre : le second moteur d'indexation, l'API de versions concurrente (`nom_vN.ext` à la racine), les deux
  corbeilles, `restoreSnapshot()` (mort).

## Demandes aux autres secteurs
(vide)
