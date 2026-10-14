# Charge — 3 puis 10 utilisateurs — 2026-10-14 10:14

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `e3a65c7`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 407 ms | 255 ms | 0 |
| 10-utilisateurs | 180 | 607 ms | 230 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 226 ms | 293 ms | 293 ms |
| Recherche | 9 | 289 ms | 407 ms | 407 ms |
| À traiter | 9 | 289 ms | 353 ms | 353 ms |
| Aperçu | 9 | 218 ms | 306 ms | 306 ms |
| Validation (écriture) | 40 | 240 ms | 255 ms | 256 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 269 ms | 338 ms | 339 ms |
| Recherche | 30 | 294 ms | 607 ms | 646 ms |
| À traiter | 30 | 237 ms | 272 ms | 323 ms |
| Aperçu | 30 | 145 ms | 217 ms | 236 ms |
| Validation (écriture) | 60 | 154 ms | 230 ms | 322 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (138.8 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
