---
name: classification-ia
description: "Classement automatise, taxonomie ECM, suggestions et cascade IA. Invoquer pour classification, AIClassifierService, confidence badge ou /api/ai."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Classement automatise — cascade IA, taxonomie ECM, suggestions

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Une suggestion n est jamais appliquee seule. Toute modification de classification est tracable.

Il protège l'adoption humaine des suggestions et exige une trace de chaque modification de classification.

## Scope

**Fichiers** : `app/Services/AIClassifierService.php`, `app/Services/AIProviderService.php`, `app/Adapters/HtmleditorTaxonomyAdapter.php`

**Tables** : `classification_suggestions`, `classification_training_data`, `classification_audit_log`, `document_types`

**Routes** : `/api/classification/*`, `/api/ai/*`

**Dépend de** : `ingestion-ocr`

## Oracles

- `classifier-taxonomie` : VERT au dernier harness.
- `ai-confidence-badge` : VERT au dernier harness.
- `ai-assistant` : VERT au dernier harness.

## État connu

Le secteur est VERT avec 15 cas.

La cascade est `training -> claude -> ollama -> rules`.

`classification_audit_log` porte zéro ligne : l'audit de classification n'est pas alimenté.

## Ce qu'il faut faire ensuite

1. Alimenter `classification_audit_log` lors des modifications de classification.
2. Conserver l'adoption humaine : une suggestion ne doit pas être appliquée seule.
3. Prouver la traçabilité des modifications de classification.

## Pièges de ce secteur

L'invariant exige une trace ; `classification_audit_log` porte actuellement zéro ligne.
