# Agent C — Dashboard / Recherche / Tâches / Chat

**Priorité** : P1 (dashboard, recherche, tâches) + P3 (chat) · **Dépend de** : A · **Statut** : `À FAIRE`

> Lire `CONVENTIONS.md`.

## Goal

Migrer les écrans « quotidien utilisateur » hors documents, clair + sombre.

## Portée (fichiers)

- `templates/dashboard/index.php` (bannière, cartes stats, sections graphiques)
- `templates/dashboard/my_tasks.php`
- `templates/search/index.php`
- `templates/tasks/list.php`, `tasks/create.php`
- `templates/chat/index.php`

## Definition of Done

- [ ] Cartes/bannières/listes → tokens/classes (cartes stats via `.ds-card` si créée par A).
- [ ] Les **pastilles d'icônes colorées** (bleu/vert/ambre/violet) des stats : conserver comme
      accents d'état, mais via tokens si possible (sinon documenter).
- [ ] **Chart.js** : les couleurs du canvas ne sont pas pilotées par le CSS → laisser à la
      phase finale (superviseur), mais noter ici les graphiques concernés.
- [ ] Clair + sombre vérifiés. Gates verts. IDs/JS préservés.

## Journal

- _(vide)_
