# Agent B — Cœur produit : Documents

**Priorité** : P1 · **Dépend de** : A · **Statut** : `FAIT` (validé + **complété** par le superviseur 2026-06-28 : agent B interrompu par limite de session sur le monolithe `index.php` ; le superviseur a terminé les 30 résidus JS restants + l'audit `index_clean`)

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

### 2026-06-28 — Migration (Agent B + complétion superviseur) — `FAIT`

**Fichiers migrés (10) :** `index.php`, `upload.php`, `edit.php`, `list.php`, `advanced_search.php`,
`bulk_action_modals.php`, `notes.php`, `history.php`, `history_partial.php`, `onlyoffice-preview.php`.
`onlyoffice-editor.php` non touché (exception Tailwind CDN plein écran).

**Décisions notables :**
- `index.php` (monolithe ~2800 l.) : grille/cartes → `.ds-card .ds-card--link` ; toolbar, arbo,
  modale d'aperçu, badges → tokens. Sélection de ligne (`advanced_search`) : toggle des classes
  Tailwind `bg-blue-50/border-blue-300/ring-blue-200` → styles tokenisés `--accent-soft/--accent/--border`
  dans le `onchange` (comportement préservé, dark-aware).
- 3 classes-couleur servant de **hook JS** converties en hooks sémantiques + style tokenisé :
  `.bg-yellow-50`→`.js-unindexed-warning` (sélecteur l.1853 ↔ élément l.2080),
  `.bg-yellow-100`→`.js-unindexed-badge` (l.1888 ↔ l.2051), surbrillance dossier actif
  `classList bg-gray-100`→`folderLink.style.background='var(--hover)'`.
- Tooltip largeur de colonne → `--tip/--tip-ink` (sombre natif). Overlays drag-drop / progression
  d'upload → `color-mix` (`--accent`/`--surface`) + tokens. Miniatures « papier » laissées claires.
- `id` `#preview-*`, `data-doc-id/index`, `onclick`/`onchange` préservés.

**Audit `index_clean.php` :** **0 référence** (routes/contrôleurs/vues) → fichier mort legacy (301 l.,
vue grille « Paperless sobre » jamais câblée, distinct de `index.php`). **Supprimé** par le superviseur
(commit dédié, récupérable via git).

**Note incident :** agent B coupé par limite de session pendant `index.php` (30 résidus restants dans
la section JS générée) ; superviseur a terminé + validé. Un **lot rogue hors-périmètre** (refactor
classifieur IA + doc WINBIZ, produit par un sous-agent dévoyé) a été **mis en quarantaine** (`git stash`),
hors de ce commit.

**Gates :** `php -l` 10/10, **0 résidu** gate sur les 10 fichiers, contrôle automatique de préservation
des attributs comportementaux OK (seul le `onchange` de sélection modifié, légitime). Résidus
couleurs-texte d'état (`text-red/green/orange-*`) renvoyés au balayage uniforme de la phase finale.
`migration_smoke` + phpunit + Playwright joués à l'intégration finale.
