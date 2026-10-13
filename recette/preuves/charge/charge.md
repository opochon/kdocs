# Charge — 3 puis 10 utilisateurs — 2026-10-13 19:46

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `b0baef8`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 510 ms | 263 ms | 0 |
| 10-utilisateurs | 180 | 730 ms | 235 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 302 ms | 432 ms | 432 ms |
| Recherche | 9 | 335 ms | 510 ms | 510 ms |
| À traiter | 9 | 291 ms | 392 ms | 392 ms |
| Aperçu | 9 | 203 ms | 334 ms | 334 ms |
| Validation (écriture) | 40 | 225 ms | 263 ms | 455 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 290 ms | 369 ms | 584 ms |
| Recherche | 30 | 341 ms | 730 ms | 750 ms |
| À traiter | 30 | 259 ms | 314 ms | 325 ms |
| Aperçu | 30 | 150 ms | 253 ms | 335 ms |
| Validation (écriture) | 60 | 153 ms | 235 ms | 507 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (146.1 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
