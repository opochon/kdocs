# Lot ged-oracle-paperless-1 — oracle et juge de parité Paperless-ngx 2.20.5

Mesure de parité Paperless-ngx / GEDv2, calculée **depuis les sources** de Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit d1aa76e, lecture seule). Ce lot mesure ;
il n'implémente aucune fonction manquante : les écarts sont le carnet de commandes.

## Fait (commits)

- `e1e25c5` — inventaire Paperless extrait des sources (780 éléments) + juge de couverture (TDD).
  - `tools/parite/extraire-paperless.py` : extraction mécanique `ast`, déterministe (deux passages identiques).
  - `docs/parite/inventaire-paperless.json` : 780 éléments (api 318, modèle 313, chaîne 60, écran 30,
    masse 17, commande 17, correspondance 13, permissions 12) + comptage indépendant.
  - `src/Parite/Juge.php` + `tests/php/Parite/JugeTest.php` : 7 cas d'oracle (sans statut, id inconnu,
    preuve inexistante / en échec, équivalent introuvable, exclusion, pourcentages exacts).
- `ca4a9e4` — correspondance GEDv2 et juge CLI (couche 9).
  - `tools/parite/corriger-correspondance.php` : 182 couverts, 48 exclus (infra Django 41, Celery/Redis 7),
    550 absents (statut le plus bas par défaut).
  - `src/Parite/InterpreteurPython.php` + `tools/parite/juge.php`.
- `87a7e60` — vecteurs de comportement (phase 2), test d'inventaire, couche 9 du harness.
  - 25 vecteurs de dates, 137 de correspondance, extraits des tests Paperless.
  - `tests/php/Parite/ComportementTest.php`, `tests/php/Parite/InventaireTest.php`.
- à venir — note de lot (ce fichier).

## Mesures exactes (dernier rejeu)

Phase 1 (existence, `recette/parite-paperless.json`) :

| Total | Couverts | Absents | Non prouvés | Exclus | Parité stricte | Parité hors exclusions |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 780 | 182 | 550 | 0 | 48 | 23,33 % | 24,86 % |

Par catégorie : api 50/318 (15,72 %), modèle 76/313 (24,28 %), chaîne 14/60 (23,33 %),
écran 10/30 (33,33 %), masse 14/17 (82,35 %), commande 9/17 (52,94 %),
correspondance 2/13 (15,38 %), permissions 7/12 (58,33 %).

Phase 2 (effet, `var/parite/comportement.json`) : dates 10/25 (40,00 %),
correspondance 22/137 (16,06 % ; MATCH_NONE 2/2, MATCH_LITERAL 18/29, MATCH_FUZZY 2/5,
MATCH_ALL 0/40, MATCH_ANY 0/42, MATCH_REGEX 0/19). Écarts mesurés, jamais corrigés ici.

`tests/php/Parite/InventaireTest.php` : 11 tests verts, 6 285 assertions (complétude,
unicité, comptage indépendant recalculé en PHP depuis les sources).

## En cours

- Rejeu complet du harness (`-SansBanc`) pour figer la couche 9 verte et les chiffres.

## Reste

- Phase 2 : gabarits de nom de fichier, opérations en masse, déclencheurs de workflow
  (extraction de vecteurs et équivalent GEDv2 à brancher).
- Carnet de commandes : lots à créer pour les 550 absents et les écarts de comportement.

## Porte qualité

- PHPStan niveau 6 : 0 erreur hors baseline (baseline vide, inchangée).
- TDD : oracle du juge écrit rouge avant `Juge.php` (7 cas) ; vecteurs phase 2 extraits avant rejeu.
- Revue du diff : pas de `catch` silencieux, pas de SQL construit par concaténation d'entrée,
  `declare(strict_types=1)` partout, code court et typé.
- Aucune donnée réelle, aucun nom de client : seuls des identifiants et références de fichiers Paperless.

ETAT: en cours
