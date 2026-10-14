# Charge — 3 puis 10 utilisateurs — 2026-10-14 20:08

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `ef8423f`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 534 ms | 255 ms | 0 |
| 10-utilisateurs | 180 | 585 ms | 253 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 290 ms | 420 ms | 420 ms |
| Recherche | 9 | 290 ms | 534 ms | 534 ms |
| À traiter | 9 | 278 ms | 355 ms | 355 ms |
| Aperçu | 9 | 216 ms | 264 ms | 264 ms |
| Validation (écriture) | 40 | 249 ms | 255 ms | 257 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 261 ms | 372 ms | 502 ms |
| Recherche | 30 | 293 ms | 585 ms | 601 ms |
| À traiter | 30 | 218 ms | 265 ms | 287 ms |
| Aperçu | 30 | 140 ms | 213 ms | 219 ms |
| Validation (écriture) | 60 | 168 ms | 253 ms | 308 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : INCOMPLETE — code 0, lot 1, 0 travail(x) non fini(s), 1 erreur(s), 0 pièce(s) rangée(s) — Failed loading C:/wamp64/bin/php/php8.4.0/zend_ext/php_xdebug-3.4.0beta1-8.4-x86_64.dll
PHP Warning:  proc_open(): CreateProcess failed: La syntaxe du nom de fichier, de répertoire ou de volume est in (28.8 s, 0 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **NON FRANCHIE**
- Defaut : ingestion : code 0, lot 1, 0 travail(x) non fini(s), 1 erreur(s), 0 pièce(s) rangée(s) — Failed loading C:/wamp64/bin/php/php8.4.0/zend_ext/php_xdebug-3.4.0beta1-8.4-x86_64.dll
PHP Warning:  proc_open(): CreateProcess failed: La syntaxe du nom de fichier, de répertoire ou de volume est in
