---
name: securite-acl
description: "Authentification, ACL de dossiers, CSRF et cloisonnement serveur. Invoquer pour FolderPermissionService, droits, document interdit, /login ou /api."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Authentification, permissions de dossiers, CSRF, cloisonnement

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Les droits se verifient cote serveur, jamais a l affichage. Un document interdit ne doit pas etre servi.

Il protège le document interdit en imposant une vérification serveur, indépendante de l'affichage.

## Scope

**Fichiers** : `app/Core/Auth.php`, `app/Services/FolderPermissionService.php`, `app/Services/TenantScopeService.php`, `app/Middleware/`

**Tables** : `users`, `groups`, `folder_permissions`, `sessions`

**Routes** : `/login`, `/api/*`

**Dépend de** : rien

## Oracles

- `folder-permissions` — décision du service : dix tests unitaires sur `can()`.
- `folder-permissions-serverside` — **câblage** : que le garde est consulté par chaque méthode du contrôleur qui sert ou modifie un document, et qu'il refuse réellement.

Les deux sont nécessaires. Le premier seul a laissé le secteur fantôme pendant des mois.

## État connu

**Ce secteur est le cas fondateur de la règle du câblage.** Jusqu'au 2026-08-09, `FolderPermissionService` était écrit, correct et couvert par dix tests verts — et appelé par aucune ligne de code applicatif. Les permissions de dossiers n'existaient pas en service pendant que tous les voyants étaient au vert.

**Câblé depuis.** `DocumentsApiController` consulte le garde via `peutAccederAuDocument()` sur `show`, `content`, `download` (lecture), `update` (écriture) et `delete` (suppression). Le refus est rendu en **404 et non 403** — même raison que l'isolation multi-mandant : ne pas révéler l'existence d'une pièce qu'on n'a pas le droit de voir.

Sans effet de bord : le service est ouvert par défaut — sans règle sur la chaîne des dossiers, il autorise — et `folder_permissions` est vide.

## Ce qu'il faut faire ensuite

1. **`folder_permissions` porte toujours zéro ligne.** Aucune règle n'est configurée, et aucun écran ne permet d'en poser. Le garde est en place mais ne garde rien.
2. Étendre le garde aux méthodes non couvertes qui touchent un document : `updateType`, `updateCorrespondent`, `updateFields`, `addTags`, `removeTag`, `triggerOcr`.
3. Garder aussi `index` — la liste ne doit pas exposer les titres de documents interdits.

## Pièges de ce secteur

Un oracle ne prouve le câblage que s'il exécute le chemin réel ; `hasMethod()` ou l'existence d'une route ne suffisent pas.
