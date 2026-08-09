---
name: corbeille-retention
description: "Corbeille durable, retention, sauvegardes et interdiction de DELETE. Invoquer pour TrashService, BackupService, soft delete, purge, cleanup_trash ou NoHardDeleteTest."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Corbeille, retention, sauvegarde — zero suppression dure

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

INVARIANT ABSOLU pose par la direction le 2026-08-07 : aucune ligne n est jamais supprimee d une table par le produit. La corbeille est un etat durable, pas une antichambre de la destruction. Reconstruire une base pour les tests reste legitime mais n appartient PAS a l application : outil externe, precede d un dump.

Cet invariant interdit toute suppression de ligne par le produit, sans exception de chemin. La corbeille est durable ; seule la reconstruction externe d'une base de test, précédée d'un dump, est légitime.

## Scope

**Fichiers** : `app/Services/TrashService.php`, `app/Services/BackupService.php`, `app/Exceptions/HardDeleteForbiddenException.php`

**Tables** : `documents`

**Routes** : `/trash`, `/admin/backups`

**Dépend de** : rien

## Oracles

- `no-hard-delete` : VERT au dernier harness.
- `soft-delete` : VERT au dernier harness.
- `trash-retention` : VERT au dernier harness.

## État connu

Le secteur est VERT depuis le 2026-08-07.

Trois chemins de destruction sont neutralisés. La tâche `cleanup_trash` est passée à `is_active=0` : 156 documents étaient à une exécution de la destruction.

Le cliquet `governance/budgets.json` fixe le plafond `documents` à zéro, total 73.

Il reste une sauvegarde quotidienne avec rotation : `BackupService` existe, `storage/backups` est vide et aucune sauvegarde n'a été produite. Il reste aussi une chaîne de hachage contre la modification silencieuse.

## Ce qu'il faut faire ensuite

1. Produire une sauvegarde quotidienne avec rotation.
2. Établir une chaîne de hachage contre la modification silencieuse.
3. Maintenir `cleanup_trash` inactif et la corbeille comme état durable.
4. Préserver le cliquet : aucune ligne ne doit être supprimée par le produit.

## Pièges de ce secteur

Zéro suppression est une règle absolue : jamais `DELETE`, marquage `deleted_at` uniquement. Le cliquet ne remonte jamais.
