# Charge — 3 puis 10 utilisateurs — 2026-10-11 01:03

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `b470f1e`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 597 ms | 246 ms | 0 |
| 10-utilisateurs | 180 | 743 ms | 226 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 243 ms | 328 ms | 328 ms |
| Recherche | 9 | 309 ms | 597 ms | 597 ms |
| À traiter | 9 | 237 ms | 320 ms | 320 ms |
| Aperçu | 9 | 218 ms | 328 ms | 328 ms |
| Validation (écriture) | 40 | 228 ms | 246 ms | 257 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 340 ms | 396 ms | 437 ms |
| Recherche | 30 | 378 ms | 743 ms | 797 ms |
| À traiter | 30 | 280 ms | 341 ms | 371 ms |
| Aperçu | 30 | 160 ms | 308 ms | 323 ms |
| Validation (écriture) | 60 | 139 ms | 226 ms | 232 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (140.1 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
