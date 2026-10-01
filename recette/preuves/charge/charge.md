# Charge — 3 puis 10 utilisateurs — 2026-10-01 17:19

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `fd58681`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 411 ms | 141 ms | 0 |
| 10-utilisateurs | 180 | 458 ms | 126 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 314 ms | 411 ms | 411 ms |
| Recherche | 9 | 203 ms | 260 ms | 260 ms |
| À traiter | 9 | 183 ms | 278 ms | 278 ms |
| Aperçu | 9 | 117 ms | 153 ms | 153 ms |
| Validation (écriture) | 40 | 123 ms | 141 ms | 150 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 397 ms | 458 ms | 587 ms |
| Recherche | 30 | 175 ms | 335 ms | 365 ms |
| À traiter | 30 | 123 ms | 159 ms | 186 ms |
| Aperçu | 30 | 89 ms | 149 ms | 206 ms |
| Validation (écriture) | 60 | 83 ms | 126 ms | 280 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (88.4 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
