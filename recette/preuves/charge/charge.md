# Charge — 3 puis 10 utilisateurs — 2026-10-12 11:05

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `6d3aae4`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 655 ms | 238 ms | 0 |
| 10-utilisateurs | 180 | 784 ms | 201 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 358 ms | 445 ms | 445 ms |
| Recherche | 9 | 332 ms | 655 ms | 655 ms |
| À traiter | 9 | 236 ms | 351 ms | 351 ms |
| Aperçu | 9 | 211 ms | 342 ms | 342 ms |
| Validation (écriture) | 40 | 215 ms | 238 ms | 345 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 310 ms | 359 ms | 653 ms |
| Recherche | 30 | 359 ms | 784 ms | 785 ms |
| À traiter | 30 | 263 ms | 335 ms | 422 ms |
| Aperçu | 30 | 160 ms | 226 ms | 339 ms |
| Validation (écriture) | 60 | 153 ms | 201 ms | 287 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (163.8 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
