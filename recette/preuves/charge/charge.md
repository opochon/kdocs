# Charge — 3 puis 10 utilisateurs — 2026-10-11 12:03

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `8a032c5`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 897 ms | 384 ms | 0 |
| 10-utilisateurs | 180 | 1102 ms | 359 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 370 ms | 478 ms | 478 ms |
| Recherche | 9 | 379 ms | 897 ms | 897 ms |
| À traiter | 9 | 423 ms | 523 ms | 523 ms |
| Aperçu | 9 | 292 ms | 351 ms | 351 ms |
| Validation (écriture) | 40 | 342 ms | 384 ms | 385 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 488 ms | 645 ms | 655 ms |
| Recherche | 30 | 567 ms | 1102 ms | 1547 ms |
| À traiter | 30 | 408 ms | 482 ms | 509 ms |
| Aperçu | 30 | 274 ms | 330 ms | 373 ms |
| Validation (écriture) | 60 | 235 ms | 359 ms | 392 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (201.6 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
