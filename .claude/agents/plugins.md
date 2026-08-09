---
name: plugins
description: "Registre de plugins et applications satellites : contrats, RH, mail, portail, factures et drapeaux .env. Invoquer pour PluginRegistry, apps, modules desactives ou admin-hub."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Registre de plugins et applications satellites

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Un module declare est soit livre et atteignable, soit retire de l interface. Jamais un menu vers un 404.

Il protège l'atteignabilité d'un module déclaré et empêche les menus menant vers un 404.

## Scope

**Fichiers** : `app/Core/PluginRegistry.php`, `apps/`

**Tables** : `contracts`, `hr_employees`, `mail_accounts`, `mail_sync_log`

**Routes** : `/contracts`, `/rh/*`, `/mail/*`, `/portal/*`, `/invoices`

**Dépend de** : `interface`

## Oracles

- `smq-versions` : VERT au dernier harness, mais le câblage n'est pas prouvé.
- `admin-hub` : VERT au dernier harness, mais le câblage n'est pas prouvé.

## État connu

**AVERTISSEMENT — SECTEUR FANTOME.** Les tests sont verts, mais les modules ne sont pas déployés.

Les sept drapeaux `CONTRACTS_APP_ENABLED`, `RH_APP_ENABLED`, `MAIL_APP_ENABLED`, `PORTAL_APP_ENABLED`, `MULTI_TENANT_ENABLED`, `CLAMAV_ENABLED` et `TSA_URL` sont absents du `.env`.

Toutes les tables associées portent zéro ligne. Les sept modules livrés et testés ne sont donc pas activés dans l'installation.

## Ce qu'il faut faire ensuite

1. Établir l'activation des sept modules livrés dans l'installation.
2. Prouver qu'un module activé est atteignable depuis l'interface.
3. Retirer de l'interface tout module qui reste désactivé.
4. Créer ou compléter un oracle qui exécute le chemin réel, et non le seul registre.

## Pièges de ce secteur

Les drapeaux sont absents du `.env` et les tables associées portent zéro ligne ; des tests verts ne prouvent pas le déploiement.
