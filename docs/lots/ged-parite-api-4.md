# Lot ged-parite-api-4 — rattraper les 79 écarts `api` restants de la parité Paperless-ngx 2.20.5

Base : `v2` = `56121b7` (parité stricte 85,64 %, 668/780 ; catégorie `api` **218/318** couverts,
79 absents, 21 exclus). Liste exacte : `C:\ged\lots\api-4-elements.txt`, copiée dans
`docs/lots/ged-parite-api-4-elements.txt` (79 lignes). Ces 79 = les **41 exclusions requalifiées par le
juge le 12.10.2026** (fonctions utilisables exclues à tort) + les absents restants d'`api-3`.

Méthode : identique aux lots `ged-parite-api-2` / `api-3` — vecteurs de comportement extraits en
LECTURE SEULE des tests de Paperless-ngx (`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) dans
`tests/parite/vecteurs/api-4-*.json`, rejoués par PHPUnit contre la VRAIE couche HTTP de GEDv2
(`gedv2_test`), rouge puis code puis vert. Aucune ligne de code Paperless (GPL-3) reprise : seuls des
couples `{entrée, sortie}` (méthode, chemin, corps éventuel, statut attendu). Les vecteurs sont gelés au
head de famille.

## Fait (commits)

- `à venir` — note de lot + liste des 79 éléments.

## En cours

- Familles en cours de traitement (voir le plan tenu à jour ci-dessous).

## Reste

- Tout le lot ; voir « Restants » en fin de note.

## Mesures exactes

| Mesure | Avant | Après |
| --- | ---: | ---: |
| Éléments couverts | 668 / 780 | — |
| Parité stricte | 85,64 % | — |
| Parité hors exclusions | 88,36 % | — |
| `api` couverts | 218 / 318 | — |
| `api` absents | 79 | — |
| `api` exclus | 21 | 21 (aucune exclusion ajoutée) |

## Porte qualité

(à compléter avant `ETAT: fini`)

## Familles : écrans impactés et flux F à retester

(à compléter par famille)

ETAT: en cours
