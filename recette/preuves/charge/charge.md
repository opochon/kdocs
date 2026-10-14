# Charge — 3 puis 10 utilisateurs — 2026-10-14 19:39

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `a3df142`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 505 ms | 254 ms | 0 |
| 10-utilisateurs | 180 | 563 ms | 259 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 217 ms | 372 ms | 372 ms |
| Recherche | 9 | 303 ms | 505 ms | 505 ms |
| À traiter | 9 | 273 ms | 347 ms | 347 ms |
| Aperçu | 9 | 219 ms | 287 ms | 287 ms |
| Validation (écriture) | 40 | 236 ms | 254 ms | 259 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 253 ms | 336 ms | 503 ms |
| Recherche | 30 | 288 ms | 563 ms | 577 ms |
| À traiter | 30 | 217 ms | 282 ms | 290 ms |
| Aperçu | 30 | 136 ms | 227 ms | 261 ms |
| Validation (écriture) | 60 | 171 ms | 259 ms | 407 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : INCOMPLETE — code 0, lot 1, 0 travail(x) non fini(s), 1 erreur(s), 0 pièce(s) rangée(s) — Failed loading C:/wamp64/bin/php/php8.4.0/zend_ext/php_xdebug-3.4.0beta1-8.4-x86_64.dll
PHP Warning:  proc_open(): CreateProcess failed: La syntaxe du nom de fichier, de répertoire ou de volume est in (28.5 s, 0 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **NON FRANCHIE**
- Defaut : ingestion : code 0, lot 1, 0 travail(x) non fini(s), 1 erreur(s), 0 pièce(s) rangée(s) — Failed loading C:/wamp64/bin/php/php8.4.0/zend_ext/php_xdebug-3.4.0beta1-8.4-x86_64.dll
PHP Warning:  proc_open(): CreateProcess failed: La syntaxe du nom de fichier, de répertoire ou de volume est in
