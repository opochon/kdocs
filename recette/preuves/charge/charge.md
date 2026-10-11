# Charge — 3 puis 10 utilisateurs — 2026-10-11 05:52

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `554cbfa`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 653 ms | 262 ms | 0 |
| 10-utilisateurs | 180 | 718 ms | 244 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 294 ms | 516 ms | 516 ms |
| Recherche | 9 | 312 ms | 653 ms | 653 ms |
| À traiter | 9 | 253 ms | 512 ms | 512 ms |
| Aperçu | 9 | 212 ms | 273 ms | 273 ms |
| Validation (écriture) | 40 | 235 ms | 262 ms | 328 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 333 ms | 618 ms | 618 ms |
| Recherche | 30 | 358 ms | 718 ms | 763 ms |
| À traiter | 30 | 298 ms | 352 ms | 360 ms |
| Aperçu | 30 | 188 ms | 266 ms | 325 ms |
| Validation (écriture) | 60 | 184 ms | 244 ms | 521 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (128.7 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
