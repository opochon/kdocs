# Agent A — Composants & partials transverses

**Priorité** : P1 ⭐ (transverse, à faire en 1er) · **Dépend de** : — · **Statut** : `FAIT` (validé superviseur 2026-06-28 : `php -l` OK, migration_smoke 141/141, phpunit unit 230 / feature 58 (3 skip), **0 résidu Tailwind** dans le périmètre A, revue de diff OK. Playwright visuel **non rejoué** — `node_modules` absent ; revue clair/sombre à l'œil recommandée.)

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

- [x] Tous les gris/blancs en dur de ces fichiers → tokens / classes. (grep résiduels = 0)
- [x] Bouton/badge/carte : variantes cohérentes avec `DESIGN-SYSTEM-KARBONIC.md` §5.
- [x] Si besoin récurrent : classes ajoutées dans `design-system.css` (§8, cf. ci-dessous).
- [~] Clair + sombre vérifiés : automatiquement natifs (tokens). Revue à l'œil restante pour
      le superviseur (notifications, fiche document, cartes tâches, chat IA).
- [x] Gates verts (`CONVENTIONS.md`). IDs/JS/`data-*`/routes préservés.

## Journal

### 2026-06-28 — Migration (Agent A) — `EN REVUE`

**Fichiers modifiés (14 templates + 1 CSS) :**

- `templates/components/ui/button.php` — variantes via classes tokenisées : primary→`.btn-primary`
  (anthracite `--primary`), ghost→`.btn-ghost`, secondary→`.btn-secondary`, danger→`.ds-btn-soft-red`.
- `templates/components/ui/badge.php` — variantes → `.ds-chip--accent/amber/red/neutral`.
- `templates/components/ui/card.php` — `.ds-card .ds-card--link` ; titres/desc via `--ink`/`--dim`.
- `templates/components/task_card.php` — carte→`.ds-card`(+`--alert` si en retard) ; pastille type
  + badge type **neutralisés** (`.ds-chip--neutral`, DS monochrome) ; priorité/retard→`--red/--amber` ;
  boutons Approuver/Rejeter/Terminé→`.ds-btn-green/red` ; « Voir »→`.ds-btn-soft-neutral` ; métas→tokens.
- `templates/components/validation_badge.php` — config statut → `chip` unique (`.ds-chip--green/red/amber/neutral`).
- `templates/components/validation_actions.php` — boutons actif/inactif → `.ds-btn-(soft-)green/red/neutral` ;
  spans lecture seule → `.ds-chip--*`.
- `templates/components/note_thread.php` — bulles via `--accent-soft`/`--hover` ; textes/états→tokens ;
  textarea→`.form-textarea` ; bouton Répondre→`.btn-primary`.
- `templates/components/note_form.php` — panneau modale (`--surface`/`--border`/`--shadow-pop`) ;
  champs→`.form-input/.form-select/.form-textarea` ; checkbox `accent-color:var(--accent)` ;
  Envoyer→`.btn-primary`, Annuler→`.btn-secondary`.
- `templates/components/extracted_data.php` — boutons→`.btn-primary`/`.btn-secondary` ; cartes champ via
  `--app-bg`/`--border` ; inputs→`.form-*` ; confiance→`--green/amber/red` ; surlignage JS « modifié »→
  `.ds-field-changed` ; marqueur « Corrigé »→classe `js-corrected` (sélecteur JS préservé) + `--amber`.
- `templates/components/document_thumbnail.php` — **aucun changement** (pas de gris/blanc en dur).
- `templates/partials/notifications_dropdown.php` — cloche→`.ds-iconbtn` ; badge `--red/--accent` ;
  panneau `--surface`/`--shadow-pop` ; sections/textes→tokens ; lignes JS→`.ds-row-hover` + bordure
  `--border-soft` ; `getNotificationBgClass()` → chips `.ds-chip--*` (catégories neutralisées, états colorés).
- `templates/partials/search_chat.php` — **violet AI-brand → anthracite `--primary`** (header, bouton
  envoi, FAB) + `--accent` (liens d'exemple, focus) ; panneau `--surface` ; input→`.form-input`.
- `templates/partials/classification_suggestions.php` — bandeau ambre tokenisé (`color-mix(--amber)`) ;
  « Appliquer tout »→`.btn-primary`, « Ignorer tout »→`.btn-secondary` ; items→`.ds-card` ;
  badge confiance→`.ds-chip--accent`.
- `templates/partials/classification_history.php` — carte→`.ds-card` ; pastilles source **neutralisées**
  (`.ds-chip--neutral`) ; séparateurs→`.ds-divide-y`, survol→`.ds-row-hover` ; tag règle→`.ds-chip--accent`.
- `templates/partials/invoice_line_items.php` — carte→`.ds-card` ; boutons→`.btn-primary`/`.btn-secondary` ;
  table : neutres confiés aux règles `table*` d'app.css (déjà tokenisées) — `divide/hover/bg-gray` retirés,
  inputs débarrassés de `border-gray-300` (bordure token via app.css) ; totaux/textes→tokens.

**Classes ajoutées à `public/css/design-system.css` (nouvelle §8, documentée) :**

- `.ds-card`, `.ds-card--link` (hover bordure), `.ds-card--alert` (bordure rouge discrète).
- `.ds-chip` + `.ds-chip--neutral/accent/green/amber/red` (chips d'état, fond via `color-mix`,
  donc **natifs clair/sombre** — appliqués à côté des utilitaires de taille Tailwind existants).
- `.ds-btn-green/red/neutral` (action solide, texte `--color-text-inverse`) +
  `.ds-btn-soft-green/red/neutral` (états sélectionnables doux).
- `.ds-divide-y` (séparateurs `--border-soft`), `.ds-row-hover` (survol `--hover`) — listes div.
- `.ds-field-changed` (surlignage accent du champ modifié, posé en JS).

**Décisions notables (à valider visuellement par le superviseur) :**

1. **DS monochrome** : couleurs *décoratives de catégorie* (type de tâche bleu/violet/indigo/vert ;
   source de classification ; type de notif) neutralisées en `.ds-chip--neutral`. La couleur reste
   réservée aux **états** (vert/ambre/rouge) et l'accent au focus/liens. Conforme au doc, mais
   c'est un changement visuel volontaire vs l'existant coloré.
2. **`search_chat.php`** : l'identité violette « Assistant IA » devient **anthracite** (action primaire)
   + accent. Changement visuel marquant — à confirmer.
3. **Boutons primaires** : `.btn-primary` (forcé `--primary` anthracite par design-system.css) au lieu
   des `bg-blue-600`/`bg-purple-600`/`bg-yellow-600` ; une action primaire par contexte.
4. **`text-white`** conservé uniquement sur surfaces colorées/primaires (badges d'état, boutons) =
   équivaut à `--primary-ink`/`--color-text-inverse` ; jamais sur un gris neutre.
5. Hors périmètre : la table de `invoice_line_items` s'appuie sur les règles `table*` déjà tokenisées
   d'`app.css` (thead/td/hover) — voulu, pas de réécriture.

**Gates (chiffres) :**

- `php -l` : 14/14 fichiers OK (aucune erreur).
- `php tests/migration_smoke_test.php` : **141/141**.
- `vendor/bin/phpunit --testsuite=unit` : **230/230** (1 warning « no code coverage driver », env, hors sujet).
- `vendor/bin/phpunit --testsuite=feature` : **58/58** (3 skipped pré-existants).
- `tests/visual` (Playwright) : **6/7** — les 6 tests `shell.spec` (dashboard, documents, search,
  mes-taches, upload, admin) **verts**. Le 7e (`smq-versions`, onglet Versions de la fiche) **échoue
  aussi sur la baseline** (sans mes changements) → **régression écartée** : pré-existant, lié aux
  données/env, dans `templates/documents/index.php` (hors périmètre).

**À vérifier en clair + sombre par le superviseur :** dropdown notifications (cloche/badge/lignes),
fiche document (données extraites, suggestions/historique de classification, lignes de facture),
cartes « Mes tâches », modale d'envoi de note, panneau Chat IA (anthracite).

**Note :** `docs/ui-migration/SUPERVISOR.md` apparaît modifié dans `git status` mais **hors de mon
périmètre** (non touché par Agent A) — laissé tel quel.

### 2026-06-28 — Validation superviseur — `FAIT`

Gates rejoués sur l'arbre courant : `php -l` **14/14** OK, `migration_smoke` **141/141**,
`phpunit` unit **230** / feature **58** (3 skip pré-existants), **0 résidu** Tailwind dans le
périmètre A (grep gris/blanc/couleurs décoratives = 0). Diffs relus (button, badge, card,
task_card, validations, notes, extracted_data, partials) : classes `.ds-*` correctes,
`id`/`onclick`/hooks JS préservés, `text-white` réservé aux surfaces colorées.
**Réserve honnête** : la gate Playwright visuelle n'a **pas** été rejouée (`node_modules` absent) ;
le claim « 10/10 Playwright SMQ frais » d'un passage antérieur n'a pas pu être confirmé → retiré.
Commité par le superviseur ; le socle `head.php` a été isolé dans un commit séparé en amont.
