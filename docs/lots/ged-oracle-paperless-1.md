# Lot ged-oracle-paperless-1 — oracle et juge de parité Paperless-ngx 2.20.5

Mesure de parité Paperless-ngx / GEDv2, calculée **depuis les sources** de Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit d1aa76e, lecture seule). Ce lot mesure ;
il n'implémente aucune fonction manquante : les écarts sont le carnet de commandes.

## Fait (commits)

- `e1e25c5` — inventaire Paperless extrait des sources (780 éléments) + juge de couverture (TDD).
  - `tools/parite/extraire-paperless.py` : extraction mécanique `ast`, déterministe (deux passages
    identiques). Choix documenté : Python 3 + `ast` (doc officielle `docs.python.org/3/library/ast.html`) ;
    sur ce poste l'interpréteur n'est pas sur le PATH (talon Microsoft Store), d'où
    `src/Parite/InterpreteurPython.php` qui essaie `GED_PYTHON`, `python3`/`python`, puis les
    emplacements usuels, et **refuse** un candidat qui ne répond pas `3`.
  - `docs/parite/inventaire-paperless.json` : 780 éléments (api 318, modèle 313, chaîne 60, écran 30,
    masse 17, commande 17, correspondance 13, permissions 12) + comptage indépendant.
  - `src/Parite/Juge.php` + `tests/php/Parite/JugeTest.php` : 7 cas d'oracle (sans statut, id inconnu,
    preuve inexistante / en échec, équivalent introuvable, exclusion, pourcentages exacts).
- `ca4a9e4` — correspondance GEDv2 et juge CLI (couche 9).
  - `tools/parite/corriger-correspondance.php` : table explicite des équivalences, lue dans le code
    et les tests de GEDv2 ; tout élément non déclaré est « absent » (statut le plus bas par défaut).
  - `src/Parite/InterpreteurPython.php` + `tools/parite/juge.php` : régénère l'inventaire et le compare
    au versionné, vérifie équivalent GEDv2 + preuve passée (`var/phpunit.xml`, rapport Playwright),
    écrit `recette/parite-paperless.json` et `docs/parite/PARITE-PAPERLESS.md`.
- `87a7e60` — vecteurs de comportement (phase 2), test d'inventaire, couche 9 du harness.
  - `tools/parite/extraire-vecteurs.py` : extraction mécanique de couples `{entree, sortie}` des tests
    Paperless (jamais de code).
  - `tests/parite/vecteurs/*.json` : 25 vecteurs de dates, 137 de correspondance.
  - `tests/php/Parite/ComportementTest.php` : rejoue chaque vecteur via l'équivalent GEDv2 et écrit
    `var/parite/comportement.json`.
  - `tests/php/Parite/InventaireTest.php` : complétude, unicité, comptage indépendant recalculé en PHP.
  - `tools/harness.ps1` : couche 9 (parité) après la couche 5.
- `5baf44a` — durcissement de trois équivalences douteuses (bulk_download, workflow-actions,
  ImapSecurity.STARTTLS) et note de lot.
- `0dfe9cb` — liste fermée des exclusions et refus du hors-liste (TDD).

## Mesures exactes (dernier rejeu)

Phase 1 (existence, `recette/parite-paperless.json`) :

| Total | Couverts | Absents | Non prouvés | Exclus | Parité stricte | Parité hors exclusions |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 780 | 180 | 552 | 0 | 48 | 23,08 % | 24,59 % |

Par catégorie : api 50/318 (15,72 %), modèle 76/313 (24,28 %), chaîne 14/60 (23,33 %),
écran 10/30 (33,33 %), masse 14/17 (82,35 %), commande 9/17 (52,94 %),
correspondance 2/13 (15,38 %), permissions 7/12 (58,33 %).
Exclusions : 41 `infrastructure-django`, 7 `infrastructure-celery-redis` (liste fermée).
**0 « non prouvé »** : chaque couvert cite un équivalent GEDv2 réel ET une preuve passée.

Phase 2 (effet, `var/parite/comportement.json`) : dates 10/25 (40,00 %),
correspondance 22/137 (16,06 % ; MATCH_NONE 2/2, MATCH_LITERAL 18/29, MATCH_FUZZY 2/5,
MATCH_ALL 0/40, MATCH_ANY 0/42, MATCH_REGEX 0/19). Écarts mesurés, jamais corrigés ici.

`tests/php/Parite/InventaireTest.php` : 11 tests verts (complétude, unicité, comptage indépendant).

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 618 tests, 9 759 assertions, 1 sauté (dont les 12 tests Parite) |
| 3-banc | ROUGE | sautée par `-SansBanc` (comportement attendu) |
| 4-écrans | VERT | 80 passés (4,1 m) |
| 7-sécurité | VERT | aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture 772 ms, p95 écriture 273 ms, 0 erreur |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur hors baseline ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 344–700 ms) |
| 9-parité | VERT | 780 éléments, 180 couverts, strict 23,08 %, hors exclusions 24,59 %, 0 non prouvé |

Note d'environnement : `tools/composer.phar` (gitignoré) manquait dans ce worktree et faisait
échouer l'audit de la couche 8 ; il a été restauré depuis `C:\ged\GEDv2\tools\composer.phar`.
La couche 9 régénère `PARITE-PAPERLESS.md` à l'identique (rapport déterministe, sans horodatage).

## Reste (carnet de commandes, lots suivants)

- Phase 2 : gabarits de nom de fichier, opérations en masse, déclencheurs de workflow — les tests
  Paperless correspondants ne fournissent pas de couples `{entree, sortie}` purement littéraux
  extractibles mécaniquement ; à instrumenter dans un lot dédié.
- 552 absents et 60 écarts de comportement : à répartir en lots (le détail par référence Paperless
  est dans `docs/parite/PARITE-PAPERLESS.md`, section « Carnet de commandes »).

## Porte qualité

- PHPStan niveau 6 : 0 erreur hors baseline (baseline vide, inchangée).
- TDD : oracle du juge écrit rouge avant `Juge.php` (7 cas puis 1 cas liste fermée) ; vecteurs
  phase 2 extraits avant rejeu.
- Revue du diff (grilles php-reviewer / security-reviewer / silent-failure-hunter) : pas de `catch`
  silencieux, pas de SQL construit par concaténation d'entrée, `declare(strict_types=1)` partout,
  code court et typé ; les rares `@` (`proc_open`, `simplexml_load_file`) ont leur échec vérifié et
  nommé, jamais avalé.
- Aucune donnée réelle, aucun nom de client : seuls des identifiants et références de fichiers Paperless.

ETAT: fini
