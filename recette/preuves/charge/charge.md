# Charge — 3 puis 10 utilisateurs — 2026-10-15 18:23

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `7f5620b`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 7159 ms | 3389 ms | 0 |
| 10-utilisateurs | 180 | 7617 ms | 1128 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 4576 ms | 5607 ms | 5607 ms |
| Recherche | 9 | 4102 ms | 4798 ms | 4798 ms |
| À traiter | 9 | 4640 ms | 7159 ms | 7159 ms |
| Aperçu | 9 | 2547 ms | 4171 ms | 4171 ms |
| Validation (écriture) | 40 | 2405 ms | 3389 ms | 3744 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 4160 ms | 7617 ms | 8165 ms |
| Recherche | 30 | 2608 ms | 5725 ms | 6006 ms |
| À traiter | 30 | 2433 ms | 4995 ms | 5233 ms |
| Aperçu | 30 | 1932 ms | 3002 ms | 3024 ms |
| Validation (écriture) | 60 | 633 ms | 1128 ms | 1426 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (376.1 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **NON FRANCHIE**
- Defaut : 3-utilisateurs : p95 lecture 7159 ms ≥ 1500 ms
- Defaut : 3-utilisateurs : p95 écriture 3389 ms ≥ 3000 ms
- Defaut : 10-utilisateurs : p95 lecture 7617 ms ≥ 1500 ms
