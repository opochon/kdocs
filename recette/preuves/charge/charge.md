# Charge — 3 puis 10 utilisateurs — 2026-10-12 21:11

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `c7b5380`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 545 ms | 242 ms | 0 |
| 10-utilisateurs | 180 | 673 ms | 209 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 210 ms | 317 ms | 317 ms |
| Recherche | 9 | 311 ms | 545 ms | 545 ms |
| À traiter | 9 | 260 ms | 367 ms | 367 ms |
| Aperçu | 9 | 183 ms | 240 ms | 240 ms |
| Validation (écriture) | 40 | 220 ms | 242 ms | 247 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 277 ms | 377 ms | 547 ms |
| Recherche | 30 | 308 ms | 673 ms | 703 ms |
| À traiter | 30 | 240 ms | 295 ms | 304 ms |
| Aperçu | 30 | 150 ms | 259 ms | 297 ms |
| Validation (écriture) | 60 | 139 ms | 209 ms | 214 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (144.5 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
