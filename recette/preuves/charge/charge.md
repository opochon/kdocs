# Charge — 3 puis 10 utilisateurs — 2026-10-10 17:27

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `b8b768d`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 506 ms | 254 ms | 0 |
| 10-utilisateurs | 180 | 644 ms | 232 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 199 ms | 420 ms | 420 ms |
| Recherche | 9 | 413 ms | 506 ms | 506 ms |
| À traiter | 9 | 254 ms | 381 ms | 381 ms |
| Aperçu | 9 | 179 ms | 247 ms | 247 ms |
| Validation (écriture) | 40 | 231 ms | 254 ms | 298 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 275 ms | 351 ms | 581 ms |
| Recherche | 30 | 321 ms | 644 ms | 687 ms |
| À traiter | 30 | 260 ms | 307 ms | 312 ms |
| Aperçu | 30 | 160 ms | 209 ms | 258 ms |
| Validation (écriture) | 60 | 150 ms | 232 ms | 511 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (110.3 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
