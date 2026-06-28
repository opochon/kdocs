# Agent D — Admin : paramètres & formulaires

**Priorité** : P2 · **Dépend de** : A · **Statut** : `EN REVUE`

> Lire `CONVENTIONS.md`. Beaucoup de champs → s'appuyer sur `.form-input/select/textarea`,
> `.btn/.btn-primary`, `.form-label` (theme.css, déjà tokenisés).

## Goal

Migrer le formulaire de paramètres et tous les formulaires admin, clair + sombre.

## Portée (fichiers)

- `templates/admin/settings.php` (form monolithe ~1100 l. — procéder par sections)
- `templates/admin/workflow_action_form.php`, `workflow_form.php`
- `templates/admin/mail_account_form.php`
- `templates/admin/document_type_form.php`
- `templates/admin/webhook_form.php`
- `templates/admin/correspondent_form.php`
- `templates/admin/user_form.php`
- `templates/admin/tag_form.php`
- `templates/admin/custom_field_form.php`, `classification_field_form.php`
- `templates/admin/storage_path_form.php`
- `templates/admin/role_assign.php`
- `templates/admin/user-groups/form.php`
- `templates/admin/attribution-rules/editor.php`

## Definition of Done

- [x] Champs/labels/boutons via classes tokenisées (pas de `bg-gray-*`/`text-gray-*` neutres).
- [x] Action primaire de chaque form = `--primary` (anthracite), une seule par contexte.
- [x] Sections/encarts → tokens. Clair + sombre vérifiés. Gates verts. IDs/JS préservés.

## Journal

### 2026-06-28 — Migration en cours

**Méthode** : house style aligné sur la réf déjà migrée `templates/components/note_form.php` (Agent A) :
- champs → `.form-input` / `.form-select` / `.form-textarea` (drop `w-full px-3 py-2 border border-gray-300 rounded-* focus:ring-blue-*`).
- labels bloc → on garde `block text-sm font-medium mb-1`, on ajoute `style="color:var(--ink-soft)"`.
- cases à cocher → garder layout + `style="accent-color:var(--accent)"`.
- bouton primaire (1 seul/form) → `.btn-primary` + layout ; secondaire/annuler → `.btn-secondary border` + layout.
- surfaces `bg-white [dark:bg-gray-800]` → `style="background:var(--surface)"` (collapse des îlots `dark:`).
- texte/bordures neutres → tokens inline ; chips d'état → `.ds-chip--green/amber/red/accent/neutral` ; états texte → `var(--green/amber/red)`.

**Découpage** (15 fichiers, ~1170 utilitaires couleur en dur) traités en parallèle, chacun avec `php -l` + re-grep par fichier :
- workflow_action_form, workflow_form
- mail_account_form, document_type_form, webhook_form
- correspondent_form, user_form, tag_form, custom_field_form, classification_field_form, storage_path_form
- role_assign, user-groups/form, attribution-rules/editor
- settings.php (monolithe ~1113 l., section par section)

**Demande de classe CSS pressentie** (non couverte, récurrente dans settings.php) : panneau d'alerte « doux » à fond teinté d'état — p.ex. `.ds-alert` + `.ds-alert--green/amber/red/info` (bg `color-mix(... 12%)`, bordure et texte de l'état). Stopgap appliqué : `style` inline avec `color-mix`. À confirmer par le superviseur.

**Résultats (gates verts) — 15/15 fichiers** :
- `php -l` : **15/15 sans erreur**.
- Re-grep gate (`bg-gray|text-gray|border-gray|bg-white|hover:bg-gray|bg-blue|bg-purple|bg-yellow|bg-indigo|bg-orange|bg-green|bg-red|divide-gray|text-blue`) : **0** sur les 15.
- Action primaire unique : **1 `.btn-primary` par formulaire** (vérifié). Exception attendue : `workflow_action_form.php` = **partial inclus** (rendu via include + template AJAX `action-form-template`) sans bouton submit propre → 0 primaire, le submit appartient au parent `workflow_form.php`.
- Dark-safe des champs JS bruts de `editor.php` : confirmé par la règle globale `app.css` `input[...]/select/textarea { background:var(--bg-primary); border:var(--border-color) }` (tokens qui basculent en sombre) — mes overrides inline `border-color:var(--border)` s'y alignent.
- Intégrité : aucun double `style=`/`class=` sur un même tag ; ids/`name`/`data-*`/`onclick`/routes préservés (diff HEAD : jeux `name=`/`id=` **identiques** sur `settings.php`) (vérifié, notamment `editor.php` : hooks JS `.condition-row/.condition-value/.action-type`, `data-index`, `row.className`+`row.style` split conservés ; `tag_form.php` : swatches hex data-driven `<?= $hex ?>` intactes).
- Hard-colors **hors gate** également tokenisés (asterisques requis, textes d'état vert/rouge/ambre, fragments innerHTML JS de `settings.php`/`editor.php`).

**Résidu accepté (1)** : `attribution-rules/editor.php:18` → `focus:border-blue-500` sur l'input-titre (indicateur de focus = accent ; non inlinable sans classe). À convertir si une classe focus tokenisée est créée.

**Dark-safe confirmé** : règle globale `app.css` `input[...] , select, textarea { background:var(--bg-primary); color:var(--text-primary); border:var(--border-color) }` ⇒ même les champs bruts (templates JS de `editor.php`) basculent clair/sombre. Plus aucune dépendance au shim §5 sur ces pages.

**Décisions clair/sombre à confirmer par le superviseur** :
1. Action primaire de chaque form = `.btn-primary` (anthracite). Boutons de diagnostic auxiliaires de `settings.php` (Tester connectivité/cascade, Voir logs) → `.btn-secondary` ; « Effacer logs » (destructif) → `.btn-danger`.
2. Bandeaux/encarts d'état (flash, statut outils, callouts info) → fond `color-mix(var(--green/amber/red/accent) 10-12%)` + bordure + texte de l'état (cf. demande `.ds-alert--*`).
3. Décoratif **violet** (gradient cascade, panneau « Recherche sémantique ») → neutralisé en `--accent`/`--rail` (le violet n'est pas un token Karbonic).
4. Bloc logs OnlyOffice (îlot volontairement sombre) → `style="background:var(--tip);color:var(--green)"` (sombre dans les deux modes, terminal-like).
5. Liens « ← Retour » / « Annuler » → `.btn-secondary border` (bordure tokenisée + hover natif, plutôt qu'inline sans hover).

**Demande de classe CSS (récurrente, design-system.css — non édité)** : `.ds-alert` + variantes `.ds-alert--green/amber/red/accent` (fond teinté `color-mix … 12%`, `border-color` + `color` de l'état) pour banniers/encarts d'état. Répété ~10× inline dans `settings.php`, présent aussi ailleurs. Stopgap : `style` inline `color-mix`. Secondaire : un `.ds-badge-solid` (badge plein d'état, `bg:var(--green/amber); color:#fff`) remplacerait les `style="background:var(--…);color:#fff"` répétés.

**Blocages** : aucun.
