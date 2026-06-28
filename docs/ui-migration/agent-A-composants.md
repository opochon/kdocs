# Agent A — Composants & partials transverses

**Priorité** : P1 ⭐ (transverse, à faire en 1er) · **Dépend de** : — · **Statut** : `À FAIRE`

> Lire d'abord `CONVENTIONS.md`. Levier maximal : ces fichiers sont inclus par beaucoup de pages.

## Goal

Tokeniser les composants et partials réutilisables pour qu'ils soient natifs clair/sombre,
ce qui corrige mécaniquement une grande partie des pages B/C/D/E.

## Portée (fichiers)

- `templates/components/ui/button.php` (primary `bg-gray-900` → `--primary` ; variantes → tokens)
- `templates/components/ui/badge.php` (variantes → tokens d'état `--green/--amber/--red/--accent`)
- `templates/components/ui/card.php` (`bg-white border-gray-200` → tokens / `.ds-card` à créer)
- `templates/components/task_card.php`
- `templates/components/validation_badge.php`, `validation_actions.php`
- `templates/components/note_thread.php`, `note_form.php`
- `templates/components/extracted_data.php`, `document_thumbnail.php`
- `templates/partials/notifications_dropdown.php` (déjà partiellement `dark:` — uniformiser)
- `templates/partials/search_chat.php`
- `templates/partials/classification_suggestions.php`, `classification_history.php`, `invoice_line_items.php`

## Definition of Done

- [ ] Tous les gris/blancs en dur de ces fichiers → tokens / classes.
- [ ] Bouton/badge/carte : variantes cohérentes avec `DESIGN-SYSTEM-KARBONIC.md` §5.
- [ ] Si besoin récurrent : classes ajoutées dans `design-system.css` (ex. `.ds-card`).
- [ ] Clair + sombre vérifiés (notifications, fiche document, cartes tâches).
- [ ] Gates verts (`CONVENTIONS.md`). IDs/JS préservés.

## Journal

- _(vide)_
