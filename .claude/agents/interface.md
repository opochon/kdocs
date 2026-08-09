---
name: interface
description: "Interface, navigation, sidebar, chrome et accessibilite. Invoquer pour templates, public/assets, menus, 404 ou audit UI-UX."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Interface, chrome, navigation, accessibilite

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

Une fonction sans entree de menu est une fonction perdue. Un module desactive ne doit pas rester visible et produire des 404.

Il protège l'atteignabilité des fonctions et évite qu'un module désactivé envoie l'utilisateur vers un 404.

## Scope

**Fichiers** : `templates/`, `public/assets/`

**Tables** : aucune

**Routes** : `/`, `/documents`, `/admin/*`

**Dépend de** : rien

## Oracles

- `ui-chrome`, `chrome-coherence`, `shell`, `a11y`, `fiche-document` : VERTS au dernier harness.
- `bugs`, `bugs-click`, `bugs-misc` : VERTS au dernier harness.
- `persona`, `persona-preview`, `persona-redx-expert` : VERTS au dernier harness.

## État connu

Le secteur est majoritairement VERT.

L'audit UI-UX est à 3,5/10 : sidebar mélangée, emojis et compteurs incohérents.

Références signalées : `docs/AUDIT-UI-UX.md` et `docs/DETTE-UI-ORPHELINS.md`.

## Ce qu'il faut faire ensuite

1. Traiter la sidebar mélangée signalée par l'audit UI-UX.
2. Traiter les emojis et les compteurs incohérents signalés par l'audit.
3. Garder hors interface tout module désactivé qui mènerait à un 404.

## Pièges de ce secteur

Une fonction sans entrée de menu est perdue ; un module désactivé ne doit pas rester visible.
