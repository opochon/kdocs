---
name: recherche-transverse
description: "Vues dynamiques, dossiers logiques, tags, champs personnalises et recherches sauvegardees. Invoquer pour Factures, logical_folders, tags ou /folders/logical."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Vues dynamiques : dossiers filtres, tags, types, champs personnalises

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

L equivalent des vues M-Files sur un stockage disque. Un dossier Factures rassemble toutes les factures ou qu elles soient, sans deplacer un fichier.

Il protège l'organisation par métadonnées sans déplacer les fichiers du stockage disque.

## Scope

**Fichiers** : `app/Models/LogicalFolder.php`, `app/Models/Tag.php`, `app/Models/ClassificationField.php`

**Tables** : `logical_folders`, `saved_searches`, `tags`, `document_tags`, `custom_fields`, `document_custom_fields`

**Routes** : `/folders/logical/*`, `/tags`, `/tags/*`

**Dépend de** : `recherche`, `classification-ia`

## Oracles

Ce secteur est ORPHELIN : il n'a aucun oracle. Le premier livrable est d'en créer un qui prouve le câblage du chemin réel.

## État connu

Le secteur est ORPHELIN et a été sous-estimé par erreur dans `EQUIVALENCE-M-FILES.md`.

L'infrastructure existe : `logical_folders` porte `filter_type` et `filter_config`, avec quatre dossiers système, dont Factures sur `{document_type_code: facture}`.

Le manque est l'usage : une affectation de tag pour 279 documents, zéro champ personnalisé et zéro recherche sauvegardée.

## Ce qu'il faut faire ensuite

1. Créer un oracle prouvant une vue dynamique sur le chemin réel.
2. Faire fonctionner le dossier Factures sans déplacer le fichier.
3. Établir l'usage des tags, champs personnalisés et recherches sauvegardées.

## Pièges de ce secteur

Voir AGENTS.md.
