---
name: socle-mesure
description: "Mesurabilite : harness, checklist, registres, cliquets et reservations. Invoquer pour run-harness, status-secteurs, checklist, claim, preflight, rapports ou .gitignore."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Mesurabilite : harness, checklist, registres, cliquets, reservations

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Ce qui n est pas mesure n existe pas. Le harness nomme ce qui tombe. Un cliquet ne remonte jamais.

Il protège une mesure nommée des échecs et interdit qu'un cliquet remonte.

## Scope

**Fichiers** : `tools/run-harness.mjs`, `tools/checklist.mjs`, `tools/claim.mjs`, `tools/preflight.php`, `tools/status-secteurs.mjs`, `governance/`

**Tables** : aucune

**Routes** : aucune

**Dépend de** : rien

## Oracles

- `specs-registre` : VERT au dernier harness.
- `migration-smoke` : VERT au dernier harness.
- `phpunit-all` : VERT au dernier harness.
- `eval-full` : VERT au dernier harness.

## État connu

Le secteur est VERT. Trente-sept suites nommées sont produites dans `tests/reports/harness-latest.json`.

Avant le 2026-08-06, le harness sortait 0 ou 1 sans dire quoi : tout le backlog était bloqué à 0 % testé.

La dette est que `.gitignore` masque des fichiers qui comptent : huit rapports à la racine et `tests/integration/` entier, dont une sonde exécutée par le harness.

## Ce qu'il faut faire ensuite

1. Établir le traitement des huit rapports racine masqués par `.gitignore`.
2. Établir le traitement de `tests/integration/`, dont une sonde est exécutée par le harness.
3. Continuer à produire les suites nommées dans `tests/reports/harness-latest.json`.
4. Préserver les cliquets existants sans les remonter.

## Pièges de ce secteur

`.gitignore` masque `test_*.php`, `RAPPORT_*.md` et `tests/integration/`. Vérifier `git check-ignore -v <fichier>` avant de conclure qu'un fichier n'existe pas ; ne jamais utiliser `git add -A`.
