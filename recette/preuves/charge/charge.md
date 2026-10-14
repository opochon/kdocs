# Charge — 3 puis 10 utilisateurs — 2026-10-14 19:03

Fonds de **10 000 documents** (var/charge), base `gedv2_test`, commit `0b2f917`. Ingestion `courrier-100p` en parallèle.

| Phase | Requêtes | p95 lecture | p95 écriture | Erreurs |
|---|---|---|---|---|
| 3-utilisateurs | 76 | 433 ms | 212 ms | 0 |
| 10-utilisateurs | 180 | 652 ms | 180 ms | 0 |

## 3-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 9 | 231 ms | 433 ms | 433 ms |
| Recherche | 9 | 287 ms | 412 ms | 412 ms |
| À traiter | 9 | 241 ms | 322 ms | 322 ms |
| Aperçu | 9 | 146 ms | 242 ms | 242 ms |
| Validation (écriture) | 40 | 121 ms | 212 ms | 214 ms |

## 10-utilisateurs

| Écran | n | p50 | p95 | max |
|---|---|---|---|---|
| Bibliothèque | 30 | 337 ms | 610 ms | 832 ms |
| Recherche | 30 | 326 ms | 529 ms | 559 ms |
| À traiter | 30 | 316 ms | 652 ms | 765 ms |
| Aperçu | 30 | 227 ms | 492 ms | 498 ms |
| Validation (écriture) | 60 | 109 ms | 180 ms | 226 ms |

## Collisions et ingestion

- Validations attendues : 100 ; validees (fichier + index) : 100 ; perdues : 0 ; doublees : 0 ; verrous morts : 0.
- Ingestion : INCOMPLETE — code 0, lot 1, 0 travail(x) non fini(s), 1 erreur(s), 0 pièce(s) rangée(s) — Failed loading C:/wamp64/bin/php/php8.4.0/zend_ext/php_xdebug-3.4.0beta1-8.4-x86_64.dll
PHP Warning:  proc_open(): CreateProcess failed: La syntaxe du nom de fichier, de répertoire ou de volume est in (25.5 s, 0 documents ajoutes).

## Porte

- Seuils : p95 lecture < 1500 ms, p95 ecriture < 3000 ms, 0 erreur, 0 collision, ingestion finie.
- Verdict : **NON FRANCHIE**
- Defaut : ingestion : code 0, lot 1, 0 travail(x) non fini(s), 1 erreur(s), 0 pièce(s) rangée(s) — Failed loading C:/wamp64/bin/php/php8.4.0/zend_ext/php_xdebug-3.4.0beta1-8.4-x86_64.dll
PHP Warning:  proc_open(): CreateProcess failed: La syntaxe du nom de fichier, de répertoire ou de volume est in
