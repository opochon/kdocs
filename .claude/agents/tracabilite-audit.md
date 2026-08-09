---
name: tracabilite-audit
description: "Piste de revision, audit_logs, export d'audit et trace des actions. Invoquer pour AuditService, /admin/audit, classification_audit_log ou changements de droits."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Piste de revision — journal de toutes les actions

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Aucune action ne doit pouvoir se produire sans laisser de trace. Un journal effacable n est pas un journal.

Il protège la piste de révision complète et refuse qu'un journal effaçable tienne lieu de journal.

## Scope

**Fichiers** : `app/Services/AuditService.php`, `app/Models/AuditLog.php`

**Tables** : `audit_logs`, `audit_log`, `classification_audit_log`

**Routes** : `/admin/audit`, `/admin/audit/export`

**Dépend de** : rien

## Oracles

Ce secteur est ORPHELIN : il n'a aucun oracle. Le premier livrable est d'en créer un qui prouve le câblage du chemin réel.

## État connu

Le secteur est ORPHELIN mais fonctionnel, corrigé le 2026-08-08 après erreur d'analyse.

`audit_logs` porte 1261 lignes et s'alimente : `auth.login` 1022, `document.updated` 42, `document.created` 20 et `folder_*` 64.

Deux défauts réels subsistent : `audit_log` au singulier est vide, en doublon, avec des colonnes différentes ; `classification_audit_log` porte zéro ligne.

La couverture est partielle : les suppressions et changements de droits ne sont pas journalisés.

## Ce qu'il faut faire ensuite

1. Créer un oracle qui exécute une action et vérifie sa trace réelle.
2. Établir le traitement de la dérive entre `audit_logs` et `audit_log`.
3. Journaliser les suppressions et changements de droits.
4. Alimenter `classification_audit_log`.

## Pièges de ce secteur

`audit_logs` au pluriel est la table vivante ; `audit_log` au singulier est vide et possède des colonnes différentes.
