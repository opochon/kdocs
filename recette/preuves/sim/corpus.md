# Espace de simulation — classement et recherche — 2026-10-13 14:49

Porte : **FRANCHIE** — corpus synthétique régénérable (graine 20261013), fonds déposé par `Ged\Socle\DepotFichiers`,
classé par `Ged\Classement\ClasseurGed` (règles, sans IA) et interrogé par `Ged\Recherche\Service`.

| Mesure | Attendu | Obtenu |
|---|---|---|
| Documents | 150–300 | 237 |
| Pages | — | 1240 |
| Gros PDF (≥ 50 pages) | ≥ 4 | 6 |
| Documents indexés | 237 | 237 |
| Durée d’ingestion | — | 38.97 s |

## Classement automatique (règles seules)

| Mesure | Seuil | Obtenu |
|---|---|---|
| Types justes | ≥ 90 % | 237/237 (100 %) |
| Émetteurs justes | — | 237/237 |
| Émetteurs vides | ≤ 15 % | 0 (0 %) |
| Dates justes | — | 237/237 |
| Validés seuls | — | 0 |
| Validés faux | 0 | 0 |
| Durée | — | 0.34 s |

## Recherche

| Mesure | Seuil | Obtenu |
|---|---|---|
| Précision moyenne | ≥ 90 % | 100 % |
| Rappel moyen | ≥ 90 % | 100 % |
| Temps max par requête | < 1000 ms | 6.2 ms |

| Requête | Mode | Attendus | Obtenus | Inter | Précision | Rappel | ms |
|---|---|---|---|---|---|---|---|
| `mot-ramonage` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 6 |
| `mot-elagage` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 6 |
| `mot-desinsectisation` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 5 |
| `mot-debouchage` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 5 |
| `mot-etancheite` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 5 |
| `mot-carrelage` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 5 |
| `mot-vitrerie` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 5 |
| `mot-serrurerie` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 6 |
| `filtre-type-contrat` | liste | 27 | 27 | 27 | 1.00 | 1.00 | 2 |
| `filtre-emetteur-energie` | liste | 19 | 19 | 19 | 1.00 | 1.00 | 2 |
| `filtre-dates-factures-2026` | liste | 15 | 15 | 15 | 1.00 | 1.00 | 3 |
| `fragment-like` | fulltext | 10 | 10 | 10 | 1.00 | 1.00 | 6 |
