# Agent C — Dashboard / Recherche / Tâches / Chat

**Priorité** : P1 (dashboard, recherche, tâches) + P3 (chat) · **Dépend de** : A · **Statut** : `EN REVUE`

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

- [x] Cartes/bannières/listes → tokens/classes (cartes stats via `.ds-card` / `.ds-chip--*`).
- [x] Les **pastilles d'icônes colorées** (bleu/vert/ambre/violet) des stats : conservées comme
      accents d'état via tokens (`.ds-chip--accent/green/amber/neutral`). Violet → neutre (pas de token violet).
- [x] **Chart.js** : couleurs canvas non pilotées par CSS → laissées intactes ; graphiques notés ci-dessous.
- [x] Clair + sombre natifs (plus de dépendance au shim §5). Gates verts. IDs/JS/onclick/data préservés.

## Journal

### 2026-06-28 — Migration tokens Karbonic (Statut → EN REVUE)

**6 fichiers migrés, 0 résidu grep, `php -l` OK sur chacun.**

#### Méthode clé (réutilisation maximale, indépendance du shim §5)
- `app.css` tokenise déjà les **éléments nus** : `table`/`thead th`/`tbody td`/`tr:hover`,
  `input[type=text|email|number|date|url]`/`select`/`textarea` (bordure/fond/texte/focus accent),
  et `a` (couleur `--accent` + hover). → pour ces éléments j'ai **retiré** les utilitaires durs
  et laissé les règles d'élément (tokenisées) s'appliquer.
- `.ds-chip--*` et `.btn-secondary/.btn-primary/.btn-ghost` ne posent **que des couleurs**
  (pas de display/padding) → utilisés comme **« color packs »** par-dessus les utilitaires de
  layout Tailwind existants : géométrie préservée + hover tokenisé sans toucher au CSS partagé.

#### Résumé par fichier
- **dashboard/index.php** : bannière + 11 cartes → `.ds-card`. Pastilles stats : bleu→`.ds-chip--accent`,
  vert→`.ds-chip--green`, ambre→`.ds-chip--amber`, violet(Tâches)→`.ds-chip--neutral` (icône via `currentColor`).
  Table « Documents récents » dé-utilitarisée (règles `table` d'`app.css`). Cartes « Actions rapides »
  → `.ds-card .ds-card--link`. Textes → `--ink/--ink-soft/--dim`. **Script Chart.js non touché.**
- **dashboard/my_tasks.php** (page autonome, `<head>` déjà centralisé, `body.ds-shell` déjà posé) :
  onglets actifs/inactifs via `style` ternaire (`--accent` / `--dim`), pastilles compteurs
  `.ds-chip--accent`/`--neutral`. Modale note : fond `--surface`, champs natifs dé-utilitarisés,
  checkbox `accent-color:var(--accent)`, boutons → `.btn-primary`/`.btn-secondary`. Toast JS :
  bg `bg-*-500` → `--green/--red/--accent` (inline, `text-white` conservé sur fond coloré).
- **search/index.php** : entête + champ recherche `type=search` → `.form-input` (non couvert par
  les règles d'élément). Selects dé-utilitarisés. Carte form/résultats vides → `.ds-card`,
  vignettes résultats → `.ds-card .ds-card--link`, fond miniature `--app-bg`. Bouton « Rechercher »
  (ex-`bg-gray-900`) → `.btn-primary` (anthracite, s'inverse en sombre) ; « Réinitialiser » → `.btn-ghost`.
- **tasks/list.php** : cartes stats colorées → `.ds-chip--amber/accent/green/neutral` (tint+texte),
  badges priorité/statut (tableaux PHP) → classes `.ds-chip--*`, table dé-utilitarisée,
  bouton « Filtrer » → secondaire bordé (pour ne garder qu'**une** action primaire « Créer une tâche »).
- **tasks/create.php** : alertes erreur/succès → `.ds-chip--red/green` (tint), form → `.ds-card`,
  champs natifs dé-utilitarisés (règles d'élément), boutons → `.btn-primary`/`.btn-secondary`.
- **chat/index.php** : rail + zone chat + entête → tokens ; bulles JS : **user** `--primary`
  (anthracite, s'inverse en sombre), **assistant** `--hover`/`--ink` ; cartes documents JS →
  `.ds-card .ds-card--link` ; surbrillance conversation active `classList bg-gray-200` →
  `style.background=var(--active)` ; chips questions rapides → `.ds-btn-soft-neutral` ;
  scores → `--dim/--green/--amber` ; `<mark>` `#fef08a` → `color-mix(--amber 24%)`. Avatar IA
  (violet) → `.ds-chip--neutral`.

#### Décisions visuelles à confirmer (clair + sombre)
1. **Violet → neutre** partout (pastille « Tâches »/« Mes tâches », avatar IA) : aucun token violet.
2. **Orange → ambre** (priorité « high » fusionne avec « medium »); **in_progress → accent** (bleu).
3. **Bulle utilisateur chat = `--primary`** (anthracite en clair, clair sur sombre en sombre) — îlot
   sombre converti en token explicite (cf. CONVENTIONS §7), pas une simple inversion.
4. **Action primaire unique par contexte** : « Filtrer » (tasks/list) passe de plein gris à
   **secondaire bordé** ; « Rechercher » et boutons sombres `bg-gray-900` → `.btn-primary`.
5. **Hover de couleur supprimé** là où un `style` inline de base était requis (onglets inactifs,
   bouton ✕ de la modale) → voir demandes de classes ci-dessous. Comportement JS/route inchangé.
6. Alertes (erreur/succès) : **bordure retirée**, tint seul via `.ds-chip--*` (lisible clair/sombre).

#### Résidu grep (gate 2) : **0**
`grep -rnE "bg-gray-[0-9]|text-gray-[0-9]|border-gray-[0-9]|bg-white|hover:bg-gray|bg-blue-[0-9]|bg-purple-|bg-yellow-|bg-indigo-|bg-orange-|bg-green-[0-9]|bg-red-[0-9]|divide-gray|text-blue-[0-9]"` → exit 1 (aucune correspondance) sur les 6 fichiers.
(Résidus volontaires hors-gate, lisibles en sombre : `text-green-600/hover:text-green-900` sur « ✓ Terminer », `text-red-500` astérisque requis, `hover:text-red-500` sur boutons supprimer, `focus:ring-blue-500` = focus accent.)

#### Templates avec graphique Chart.js (pour la phase finale superviseur)
- **`templates/dashboard/index.php`** uniquement — 4 `<canvas>` :
  `documentsByMonthChart` (line), `documentsByTypeChart` (doughnut),
  `documentsByCorrespondentChart` (bar), `amountsByMonthChart` (bar).
  Couleurs `rgb(...)` codées en dur dans le `<script>` (non thématisables via CSS) → **laissées telles quelles**.
  Aucun graphique dans my_tasks / search / tasks / chat.

#### Demandes de classes CSS (récurrentes, non couvertes — stopgap inline en attendant)
1. **`.ds-tab` / `.ds-tab.is-active`** (ou `--active`/`--inactive`) : onglets soulignés
   (actif = `--accent` texte+bordure ; inactif = `--dim`, hover `--ink-soft` + bordure).
   Besoin : `my_tasks.php` (5 onglets). Stopgap : `style` ternaire, **hover de couleur perdu**.
2. **`.ds-alert--green` / `.ds-alert--red`** (tint + bordure + texte d'état) pour les bandeaux
   erreur/succès. Besoin : `tasks/create.php`, `tasks/list.php` (et probablement d'autres agents).
   Stopgap : `.ds-chip--green/red` (tint sans bordure).

#### Blocages
Aucun. `design-system.css`, `SUPERVISOR.md` et les autres `agent-*.md` non touchés.
Gates lourds (migration_smoke / phpunit / npm) **non lancés** (consigne agent).
