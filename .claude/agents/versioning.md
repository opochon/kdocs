---
name: versioning
description: "Versions de documents : snapshots, document_versions et sous-dossier .versions. Invoquer pour version, historique, SnapshotService ou /documents/{id}/versions."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Versions de documents — stockage en sous-dossier cache aupres du fichier

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

La version courante reste le fichier nu, ouvrable directement. Les anterieures vivent dans un sous-dossier cache voisin (modele .versions/, inspire de la convention .DS_Store), jamais en base.

Il protège l'ouverture directe de la version courante et place les versions antérieures à côté du fichier, jamais en base.

## Scope

**Fichiers** : `app/Services/SnapshotService.php`

**Tables** : `document_versions`, `snapshots`

**Routes** : `/documents/{id}/versions`, `/documents/{id}/versions/*`

**Dépend de** : `stockage`

## Oracles

Ce secteur est ORPHELIN : il n'a aucun oracle. Le premier livrable est d'en créer un qui prouve le câblage du chemin réel.

## État connu

`document_versions` porte 0 ligne pour 279 documents.

La table est déployée, mais la fonction n'est pas en service. Le design doit être posé avec le dirigeant avant code.

## Ce qu'il faut faire ensuite

1. Poser le design avec le dirigeant avant tout code.
2. Mettre les versions antérieures dans le sous-dossier caché voisin `.versions/`.
3. Maintenir la version courante comme fichier nu, ouvrable directement.
4. Créer un oracle exécutant le chemin réel des versions.

## Pièges de ce secteur

Voir AGENTS.md.
