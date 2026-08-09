---
name: ingestion-ocr
description: "Ingestion, OCR, extraction, upload, consommation et taches planifiees. Invoquer pour /consume, upload, DocumentProcessor, TaskService ou task_worker."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Ingestion, OCR, extraction de contenu

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

L OCR et la classification sortent de la requete HTTP. Une ingestion ne bloque pas l utilisateur.

Il protège l'utilisateur d'une ingestion bloquante en sortant OCR et classification de la requête HTTP.

## Scope

**Fichiers** : `app/Services/DocumentProcessor.php`, `app/Services/TaskService.php`, `app/workers/task_worker.php`

**Tables** : `documents`, `tasks`, `scheduled_tasks`

**Routes** : `/consume`, `/api/consumption/*`, `/api/documents/upload`

**Dépend de** : `stockage`

## Oracles

- `persona-parcours-ecm` : ROUGE au dernier harness ; attente de réponse supérieure à 180 s.
- `pipeline-ui` : ROUGE au dernier harness ; `#preview-type-select` n'est jamais trouvé.

## État connu

Le secteur est ROUGE sur ses deux oracles.

Les tâches planifiées affichent `dernier_run=JAMAIS` : aucun ordonnanceur ne tourne.

## Ce qu'il faut faire ensuite

1. Rétablir une réponse au parcours `persona-parcours-ecm` sans attente de plus de 180 s.
2. Rendre `#preview-type-select` atteignable pour `pipeline-ui`.
3. Faire tourner l'ordonnanceur des tâches planifiées.
4. Prouver que l'OCR et la classification sortent de la requête HTTP.

## Pièges de ce secteur

Aucun ordonnanceur ne tourne ; `scheduled_tasks` affiche `dernier_run=JAMAIS`.
