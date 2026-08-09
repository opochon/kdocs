---
name: conformite-archivage
description: "Archivage legal suisse : scelle WORM, retention et horodatage qualifie. Invoquer pour legal_sealed, retention_until, TSA, signature ou /api/documents/{id}/legal-seal."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Archivage legal suisse — scelle WORM, retention, horodatage qualifie

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Un document scelle ne peut etre ni modifie ni detruit, par AUCUN chemin. Retention 10 ans (CO 958f, GeBuV / Olico).

Il protège le document scellé sur tous les chemins et porte une rétention de dix ans.

## Scope

**Fichiers** : `app/Services/Compliance/`

**Tables** : `documents`

**Routes** : `/api/documents/{id}/legal-seal`, `/api/documents/{id}/sign`

**Dépend de** : `corbeille-retention`, `tracabilite-audit`

## Oracles

- `legal-seal` : VERT au dernier harness.

## État connu

Le secteur est PARTIEL.

Les colonnes `legal_sealed`, `retention_until` et `tsa_token` sont déployées ; dix documents sont scellés sur 279.

Le scellé ne couvrait que les routes API : la purge le contournait entièrement jusqu'au 2026-08-07.

`TSA_URL` est absent : aucun horodatage qualifié réel n'a jamais été produit.

## Ce qu'il faut faire ensuite

1. Garantir qu'aucun chemin, y compris la purge, ne modifie ou ne détruit un document scellé.
2. Établir un horodatage qualifié réel ; `TSA_URL` est absent.
3. Conserver les colonnes de scellé et de rétention sur les documents.

## Pièges de ce secteur

Le scellé a déjà été contourné par la purge ; l'invariant s'applique par AUCUN chemin.
