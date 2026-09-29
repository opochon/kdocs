# Charge — 3 puis 10 utilisateurs — 2026-09-29 22:41

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `e7ee3c7`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 470 ms | 139 ms | 0 |
| 10-utilisateurs | 180 | 762 ms | 113 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 151 ms | 325 ms | 325 ms |
| Recherche | 9 | 314 ms | 470 ms | 470 ms |
| À traiter | 9 | 170 ms | 338 ms | 338 ms |
| Aperçu | 9 | 114 ms | 243 ms | 243 ms |
| Validation (écriture) | 40 | 109 ms | 139 ms | 164 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 266 ms | 490 ms | 490 ms |
| Recherche | 30 | 376 ms | 762 ms | 797 ms |
| À traiter | 30 | 228 ms | 370 ms | 462 ms |
| Aperçu | 30 | 151 ms | 310 ms | 315 ms |
| Validation (écriture) | 60 | 74 ms | 113 ms | 192 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (99.4 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
