---
name: recherche
description: "Recherche plein texte : FULLTEXT MySQL, LIKE, recherche semantique et erreurs advancedSearch. Invoquer pour /search, /api/search, SearchService ou SearchQuery."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Recherche plein texte — FULLTEXT MySQL, repli LIKE, semantique optionnelle

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Une recherche qui echoue doit se voir. Aujourd hui advancedSearch avale les erreurs SQL et rend zero resultat : indiscernable d une recherche sans reponse.

Il protège la distinction entre une recherche sans résultat et une recherche cassée.

## Scope

**Fichiers** : `app/Services/SearchService.php`, `app/Search/SearchQuery.php`, `app/Search/SearchResult.php`

**Tables** : `documents`, `saved_searches`

**Routes** : `/search`, `/api/search/*`, `/api/semantic-search/*`

**Dépend de** : `stockage`

## Oracles

- `search-fulltext` : VERT au dernier harness.
- `search-tasks` : VERT au dernier harness.

## État connu

Le secteur est VERT depuis le 2026-08-07, avec 17 cas.

Les bugs de la sonde `AGAINST('*')` et des opérateurs produisant `+""* -> 1064` sont corrigés.

La dette ouverte demeure : les erreurs SQL sont avalées.

## Ce qu'il faut faire ensuite

1. Faire apparaître les erreurs SQL d'`advancedSearch` au lieu de rendre zéro résultat.
2. Tester `$result->error`, et non seulement l'absence d'exception.
3. Préserver le comportement corrigé pour `AGAINST('*')` et les opérateurs.

## Pièges de ce secteur

`SearchService::advancedSearch()` attrape les erreurs SQL et les range dans `$result->error` ; une recherche cassée peut donc sembler vide.
