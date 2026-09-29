# Charge — 3 puis 10 utilisateurs — 2026-09-29 21:24

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `8fe7c3f`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 4371 ms | 2863 ms | 0 |
| 10-utilisateurs | 180 | 5518 ms | 2592 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 156 ms | 662 ms | 662 ms |
| Recherche | 9 | 1552 ms | 4371 ms | 4371 ms |
| À traiter | 9 | 2923 ms | 3408 ms | 3408 ms |
| Aperçu | 9 | 1445 ms | 1777 ms | 1777 ms |
| Validation (écriture) | 40 | 2473 ms | 2863 ms | 3116 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 172 ms | 627 ms | 627 ms |
| Recherche | 30 | 1936 ms | 2940 ms | 3129 ms |
| À traiter | 30 | 5195 ms | 5518 ms | 5585 ms |
| Aperçu | 30 | 1833 ms | 2058 ms | 2078 ms |
| Validation (écriture) | 60 | 988 ms | 2592 ms | 3457 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (137.1 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **NON FRANCHIE**
- Defaut : 3-utilisateurs : p95 lecture 4371 ms ≥ 1500 ms
- Defaut : 10-utilisateurs : p95 lecture 5518 ms ≥ 1500 ms
