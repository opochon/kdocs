---
name: workflow-validation
description: "Workflows visuels, approbations et validation par roles. Invoquer pour WorkflowEngine, ValidationService, /workflows, /workflow/approve ou /api/validation."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Workflows visuels et validation par roles

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Une validation engage une personne : elle est tracee, avec son montant et son perimetre.

Il protège la responsabilité de la personne qui valide, avec son montant et son périmètre.

## Scope

**Fichiers** : `app/Services/WorkflowEngine.php`, `app/Services/ValidationService.php`

**Tables** : `workflows`, `workflow_nodes`, `role_types`, `user_roles`

**Routes** : `/workflows`, `/workflow/approve/{token}`, `/api/validation/*`

**Dépend de** : `securite-acl`

## Oracles

- `workflow-doc-identification` : VERT au dernier harness, avec un seul cas.

## État connu

Un seul oracle est vert, très mince, avec un cas.

La couverture réelle du moteur de workflow n'est pas établie.

## Ce qu'il faut faire ensuite

1. Établir la couverture réelle du moteur de workflow.
2. Créer des oracles qui exécutent les parcours réels de validation.
3. Prouver qu'une validation est tracée avec la personne, le montant et le périmètre.
4. Tenir compte de la dépendance `securite-acl`, actuellement FANTOME.

## Pièges de ce secteur

La dépendance `securite-acl` est FANTOME : ses permissions de dossiers ne sont pas en service.
