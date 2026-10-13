# Lot ged-mfiles-oracle-1 — amorcer la parité M-Files / Red'X

Agent : DeepCode · Juge : Claude sur le poste principal · Base de test : `gedv2_test` (127.0.0.1:3308).
Branche : `ged/ged-mfiles-oracle-1` · point de départ : `3625f88` (v2).

## Objectif

1. Construire l'inventaire des fonctions **M-Files** depuis la **documentation publique** (aucune
   ligne de code M-Files lue), l'ajouter à la parité (`mfiles:*`) et outiller le juge.
2. Porter la **première famille** : ARCHIVAGE / VERSIONS de `docs/ARCHIVAGE-FILESERVER.md` (le dossier
   fait foi), sans transformer la GED en coffre substitut du système de fichiers.

## État d'avancement

### Fait (commité)

- `5bb9619` — **oracle M-Files** : `tools/parite/extraire-mfiles.py` (capture + inventaire),
  `docs/parite/mfiles-rest-api.capture.json` (89 ressources publiques), `docs/parite/inventaire-mfiles.json`
  (**112 opérations**), catégorie `mfiles:*` dans `docs/parite/correspondance.json` (103 `absent`,
  9 `couvert`), `tools/parite/juge-mfiles.php`, `tools/parite/juge.php` (juge Paperless inchangé).
- `0a98c03` — **famille archivage / versions** (`src/DepotFileserver/`) : scan initial avec hash de
  tous les fichiers, veille (modification / déplacement / suppression hors GED + journal), versions
  dans `.<fichier>` + **dépôt séparé** adressé par contenu, export d'arborescence classique, écran,
  droits, 20 tests unitaires verts.
- `979b00c` — **preuves** : oracle `porte.php` (6 clauses), vecteurs `mfiles-*.json` rejoués par
  PHPUnit, parcours Playwright 4/4, couche `9-parite-mfiles` du harness.

### En cours

- Harness complet `-SansBanc` de fin de lot et note de mesure ci-dessous.

### Reste

- Décision du juge sur les 103 `mfiles:*` `absent` et sur la réconciliation
  `.versions/` (Socle) ↔ `.<fichier>` (ce secteur).

## Mesures exactes

### Oracle M-Files

| Mesure | Valeur |
| --- | --- |
| Ressources publiques capturées | 89 |
| Opérations inventoriées (`mfiles:*`) | 112 (GET 69, POST 20, PUT 15, DELETE 8) |
| Statuts | `couvert` 9, `absent` 103, `exclu` 0 (aucune exclusion posée par l'agent) |
| Parité M-Files mesurée | 8,04 % (9/112) — résultat, pas critère |
| Inventaire rejoué à l'identique | oui (`juge-mfiles.php` VERT) |
| Vecteurs de comportement rejoués | 7/7 (mfiles-versions 4/4, mfiles-garantie 3/3) |

### Famille archivage / versions

| Mesure | Valeur |
| --- | --- |
| Tests unitaires `tests/php/DepotFileserver` | 20 tests, 76 assertions, vert |
| Tests de parité `tests/php/Parite/Mfiles*` | 7 tests, 1590 assertions, vert |
| Oracle `tests/oracles/depot-fileserver/porte.php` | 6/6 clauses |
| Parcours E2E `depot-fileserver.spec.ts` | 4/4 (bureau, écart hors GED, refus 403, iPhone 13) |
| PHPStan niveau 6 | 0 erreur hors baseline |

## Porte qualité (ECC)

- **Analyse statique** : `php vendor/bin/phpstan analyse --memory-limit=2G` → 0 erreur, baseline
  inchangée (vide).
- **Revue `php-reviewer`** : `declare(strict_types=1)` partout ; méthodes courtes ; `match` pour les
  scénarios de vecteurs ; écritures atomiques pour le manifeste (`rename`) ; aucune concaténation SQL
  (ce secteur n'écrit pas en base) ; `array_merge` pour l'état `absent` (piège du `+` sur clé
  existante corrigé pendant le lot).
- **Revue `security-reviewer`** : les trois mutations exigent une **confirmation explicite** (une
  charge malveillante ne déclenche ni scan ni export) et passent par la garde CSRF centrale ;
  `attrib +H` est composé via `escapeshellarg` et gardé `PHP_OS_FAMILY === 'Windows'` ; aucune donnée
  réelle, aucun nom de client ; le fonds et les dépôts de test vivent sous `var/`.
- **Revue `silent-failure-hunter`** : toute erreur est nommée (écran `depot-fileserver-erreur`,
  exceptions `RuntimeException` avec chemin) ; l'extracteur M-Files échoue en code 2 avec la cause
  si la capture manque ; le juge M-Files rend ROUGE si l'interpréteur Python ou une preuve manque.
- **TDD** : oracle `porte.php` et tests écrits avant/pendant le code, vus rouges (2 tests en échec
  sur l'état `absent`, corrigés), puis verts.

## Décisions

- La catégorie M-Files vit dans `inventaire-mfiles.json` + `correspondance.json` ; le juge Paperless
  **filtre ses propres ids** et ne mélange pas les deux mesures (Paperless inchangé).
- L'oracle M-Files rejoue hors ligne depuis la **capture versionnée** ; `--capturer` refait la capture
  depuis `developer.m-files.com` (geste humain ponctuel).
- Ce secteur ajoute `.<fichier>` à côté de `.versions/<fichier>/` du Socle : réconciliation proposée
  au juge (demande dans `docs/secteurs/depot-fileserver.md`).

## Chiffres du harness

_(à compléter après le passage complet `-SansBanc`.)_

ETAT: en cours
