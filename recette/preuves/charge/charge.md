# Charge — 3 puis 10 utilisateurs — 2026-10-14 09:15

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `1ddde88`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 398 ms | 251 ms | 0 |
| 10-utilisateurs | 180 | 620 ms | 289 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 290 ms | 315 ms | 315 ms |
| Recherche | 9 | 308 ms | 398 ms | 398 ms |
| À traiter | 9 | 270 ms | 357 ms | 357 ms |
| Aperçu | 9 | 193 ms | 214 ms | 214 ms |
| Validation (écriture) | 40 | 228 ms | 251 ms | 346 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 290 ms | 313 ms | 541 ms |
| Recherche | 30 | 320 ms | 620 ms | 669 ms |
| À traiter | 30 | 248 ms | 282 ms | 286 ms |
| Aperçu | 30 | 154 ms | 225 ms | 284 ms |
| Validation (écriture) | 60 | 159 ms | 289 ms | 769 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : finie — 53 pièces rangées, 0 travail restant (142.3 s, 53 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **FRANCHIE**
