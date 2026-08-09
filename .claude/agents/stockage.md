---
name: stockage
description: "Stockage filesystem-first : indexation disque, dossiers, documents et deplacement. Invoquer pour /documents, /indexing, document_folders, storage_paths ou lib-operations."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Stockage filesystem-first : le fichier sur disque est la source, la base porte metadonnees et index

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Le document reste lisible sans l application. Aucun blob en base, aucun nom de fichier aberrant. La GED se pose sur un stockage existant sans tout importer.

Cet invariant maintient le fichier sur disque comme source et permet de poser la GED sur un stockage existant.

## Scope

**Fichiers** : `app/Services/FilesystemIndexer.php`, `app/Services/FolderIndexService.php`, `app/Services/ConsumeFolderService.php`, `app/Services/IndexingService.php`, `app/Controllers/IndexingController.php`, `app/Repositories/DocumentRepository.php`

**Tables** : `documents`, `document_folders`, `storage_paths`

**Routes** : `/documents`, `/documents/*`, `/indexing/*`

**Dépend de** : rien

## Oracles

- `audit-coherence` : VERT au dernier harness.
- `lib-operations` : ROUGE au dernier harness ; timeout de 60 s lors d'un déplacement de dossier.

## État connu

`document_folders` porte 36 dossiers scannés du disque : le modèle fonctionne.

`lib-operations` est instable : le timeout sur déplacement de dossier varie d'un run à l'autre. C'est une instabilité, plus qu'un échec franc.

## Ce qu'il faut faire ensuite

1. Établir la cause du timeout de 60 s de `lib-operations` lors du déplacement de dossier.
2. Rendre ce déplacement stable d'un run à l'autre.
3. Rejouer l'oracle `lib-operations` sur le chemin réel.

## Pièges de ce secteur

Le fichier sur disque est la source ; aucun blob en base et aucune importation totale du stockage existant.
