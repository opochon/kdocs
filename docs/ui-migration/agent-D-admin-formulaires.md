# Agent D — Admin : paramètres & formulaires

**Priorité** : P2 · **Dépend de** : A · **Statut** : `À FAIRE`

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

- [ ] Champs/labels/boutons via classes tokenisées (pas de `bg-gray-*`/`text-gray-*` neutres).
- [ ] Action primaire de chaque form = `--primary` (anthracite), une seule par contexte.
- [ ] Sections/encarts → tokens. Clair + sombre vérifiés. Gates verts. IDs/JS préservés.

## Journal

- _(vide)_
