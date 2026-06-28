# Agent B — Cœur produit : Documents

**Priorité** : P1 · **Dépend de** : A · **Statut** : `À FAIRE`

> Lire `CONVENTIONS.md`. Page la plus vue du produit + le plus gros volume de gris en dur.

## Goal

Migrer toute la zone `documents/` vers tokens/classes, clair + sombre, sans changer le
comportement (modale fiche, grille, arborescence, upload).

## Portée (fichiers)

- `templates/documents/index.php` — **monolithe ~2800 l.** (grille, modale preview, arborescence).
  ⚠ Gros morceau : procéder par sections (toolbar, colonnes filtres, grille, modale, badges).
  Préserver les `id` (`#preview-tabs`, `#preview-tab-versions`, `#preview-versions-content`…),
  utilisés par le harness Playwright et le JS.
- `templates/documents/upload.php`
- `templates/documents/edit.php`
- `templates/documents/list.php`
- `templates/documents/advanced_search.php`
- `templates/documents/bulk_action_modals.php`
- `templates/documents/notes.php`, `history.php`, `history_partial.php`
- `templates/documents/onlyoffice-preview.php`, `onlyoffice-editor.php`
- `templates/documents/index_clean.php` — **auditer d'abord** : doublon legacy probable de `index.php`.
  Si non routé/mort → proposer suppression au superviseur (ne pas migrer pour rien).

## Definition of Done

- [ ] Gris/blancs en dur → tokens/classes sur toute la portée.
- [ ] Modale fiche : onglets/sections en `.ds-*` ; badges « À classer » via tokens d'état.
- [ ] Miniatures « papier » : l'aperçu document reste clair (image) — ne pas forcer en sombre.
- [ ] `id`/hooks JS intacts ; **Playwright 7/7** (dont SMQ Versions).
- [ ] Clair + sombre vérifiés (grille, modale ouverte via `?open=<id>`). Gates verts.

## Journal

- _(vide)_
