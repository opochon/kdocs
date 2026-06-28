# Agent F — Auth & erreurs (reste)

**Priorité** : reste · **Dépend de** : — (indépendant) · **Statut** : `À FAIRE`

> Lire `CONVENTIONS.md`. Petite portée ; `auth.php` ne charge que `tailwind` + `design-system`
> (pas theme/app) — donc privilégier tokens en `style="var(--…)"` ou classes `.ds-*`.

## Goal

Migrer la page de connexion et les pages d'erreur, clair + sombre.

## Portée (fichiers)

- `templates/auth/login.php`
- `templates/errors/404.php`, `errors/500.php`

## Definition of Done

- [ ] Login : carte/champs/bouton via tokens ; bouton primaire = `--primary`.
- [ ] Pages 404/500 : texte/fond via tokens, lisibles clair + sombre.
- [ ] Le login respecte déjà le thème (no-FOUC) ; vérifier le rendu sombre sans le header.
- [ ] Gates verts. Comportement de login inchangé.

## Journal

- _(vide)_
