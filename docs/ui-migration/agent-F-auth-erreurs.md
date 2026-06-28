# Agent F — Auth & erreurs (reste)

**Priorité** : reste · **Dépend de** : — (indépendant) · **Statut** : `EN REVUE`

> Lire `CONVENTIONS.md`. Petite portée ; `auth.php` ne charge que `tailwind` + `design-system`
> (pas theme/app) — donc privilégier tokens en `style="var(--…)"` ou classes `.ds-*`.

## Goal

Migrer la page de connexion et les pages d'erreur, clair + sombre.

## Portée (fichiers)

- `templates/auth/login.php`
- `templates/errors/404.php`, `errors/500.php`

## Definition of Done

- [x] Login : carte/champs/bouton via tokens ; bouton primaire = `--primary`.
- [x] Pages 404/500 : texte/fond via tokens, lisibles clair + sombre.
- [x] Le login respecte déjà le thème (no-FOUC) ; vérifier le rendu sombre sans le header.
- [x] Gates verts. Comportement de login inchangé.

## Journal

### 2026-06-28 — Migration tokens (EN REVUE)

**Fichiers migrés** : `templates/auth/login.php`, `templates/errors/404.php`, `templates/errors/500.php`.

**Classes existantes réutilisées** (chargées via design-system.css sur les 3 pages, y compris login) :
- `.ds-card` (§8) → cartes login + erreurs (remplace `bg-white`/`rounded-lg`).
- `.ds-shell` (§3) → `<body>` des pages d'erreur (remplace `bg-gray-100` : fond `--app-bg` + texte `--ink`).
- `.btn-primary` (§3) → boutons d'action primaire (login « Se connecter », 404/500). La règle §3
  ne pose QUE les couleurs (`--primary` / `--primary-ink` + hover `brightness`), donc fonctionne
  sur login sans theme/app ; la mise en page reste via utilitaires Tailwind. Supprime `text-white`
  (illisible en sombre car `--primary` s'inverse).
- `.ds-btn-soft-neutral` (§8) → boutons secondaires des pages d'erreur (remplace
  `bg-gray-200 hover:bg-gray-300 text-gray-700`, avec hover `--active`).

**Tokens inline** (stopgap, pas de classe dispo) : titres/labels/textes (`--ink`/`--ink-soft`/`--dim`),
champs login (`--surface`/`--ink`/`--border`), encarts d'erreur rouges (`color-mix` sur `--red`),
liens (`--accent`), code debug (`--rail`), encart « aide » 500 (`--app-bg`), icônes SVG (`--dim`/`--red`).

**Focus** : champs + boutons gardent `focus:ring-2 focus:ring-blue-500` (anneau = accent focus,
autorisé par règle 2 ; non ciblé par le grep). `focus:ring-offset-2` retiré (le liseré blanc
de l'offset jurait en sombre). `focus:border-blue-500` retiré (battu par le `border-color` inline).

**Gates** : `php -l` OK sur les 3 ; grep couleurs en dur → 0 résidu.

**Demandes de classe** (à promouvoir dans design-system.css par le superviseur — voir compte-rendu) :
`.ds-input`/`.ds-field` (champ tokenisé + `:focus` via `--accent`) et `.ds-btn-primary`
(bouton primaire complet hover/focus tokenisés), pour retirer le dernier `focus:ring-blue-500`
en dur et les `style` inline des champs login.
