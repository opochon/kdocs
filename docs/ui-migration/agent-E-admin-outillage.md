# Agent E — Admin : listes & outillage

**Priorité** : P2 (listes/outillage) + P3 (designer, attribution-rules) · **Dépend de** : A · **Statut** : `EN REVUE`

> Lire `CONVENTIONS.md`. Beaucoup de **tableaux** → `app.css` style déjà `table thead/tbody`
> via tokens ; surtout retirer les `bg-gray-*`/`text-gray-*` inline résiduels et les badges.

## Goal

Migrer les pages admin de consultation/outillage et le designer de workflow, clair + sombre.

## Portée (fichiers)

- Listes/référentiels : `admin/{users,users_list,roles,tags,correspondents,document_types,custom_fields,classification_fields,storage_paths,mail_accounts,scheduled_tasks,workflows,index}.php`
- Outillage : `admin/{settings? non→D}` → ici : `admin/{snapshots,snapshot_detail,snapshot_compare,audit_logs,indexing,diagnostic,consume,consume_card,api_usage,export_import,webhooks,webhook_logs}.php`
- `admin/attribution-rules/{index,logs}.php`
- `admin/user-groups/index.php`
- `templates/workflow/designer.php`

## Definition of Done

- [ ] Tableaux : en-têtes/lignes/hover via tokens (vérifier `app.css table*` suffit, sinon classes).
- [ ] Badges d'état (succès/alerte/erreur) → `--green/--amber/--red`.
- [ ] Cartes outillage (`diagnostic`, `consume`, `snapshots`) → tokens/`.ds-card`.
- [ ] Clair + sombre vérifiés (au moins diagnostic, indexing, audit_logs, snapshots, designer).
- [ ] Gates verts. IDs/JS préservés.

## Journal

### 2026-06-28 — Migration (Agent E) — `EN REVUE`

**29/29 fichiers migrés** — `php -l` 29/29 OK, **0 résidu** Tailwind (grep gris/blanc/couleurs), tous
les `id`/`name`/`onclick`/`data-*`/`href`/`onsubmit`/`onchange` préservés (comptes identiques à HEAD).

**Méthode appliquée (homogène) :**
- Cartes `bg-white dark:bg-gray-800 rounded-lg shadow` → `.ds-card` ; sous-panneaux/insets `bg-gray-50/100` → `style="background:var(--app-bg)"` ; `<code>` inline → `--hover`.
- Textes : 900/800→`--ink`, 700/600→`--ink-soft`, 500/400→`--dim`, 300→`--muted` (les paires `dark:` retirées).
- Champs `input/select/textarea` : **utilitaires couleur retirés**, base tokenisée par `app.css` (bordure/fond/focus). Layout (`w-full px py rounded`) conservé.
- Boutons : action primaire→`.btn .btn-primary` (anthracite), secondaire/annuler/retour→`.btn .btn-secondary`, suppression→`.btn .btn-danger`, valider→`.ds-btn-green`.
- **Tableaux** : `thead bg-gray-*`, `th text-gray-*`, `tbody divide-y divide-gray-*`, `tr hover:bg-gray-*` **retirés** (les règles `table*` d'`app.css` gèrent en-têtes/lignes/hover, déjà tokenisées) ; `td` méta → `style="color:var(--ink-soft/--dim)"`. `.border-t/.border-b` laissés (tokenisés par `app.css`).
- Badges/chips d'état → `.ds-chip--green/amber/red/accent/neutral` ; catégories décoratives (type snapshot, type entité, méthode IA/Local, node-types designer) **neutralisées** `.ds-chip--neutral` / tokens (DS monochrome, cf. décision Agent A #1).
- Cartes/encarts d'état (diagnostic, indexing, alertes export/import, synthèses) : **stopgap inline** `style="border-color:var(--green/amber/red);background:color-mix(in srgb,var(--TOKEN) 10%,transparent)"` (cf. demande de classe ci-dessous).
- **JS** (rule L) : chaînes `className`/innerHTML colorées migrées en `.ds-chip--*` ou `el.style.*` avec tokens — flux de contrôle inchangé (résultats indexing/diagnostic, chips de niveau de log construits en JS, badges de tags). Aucun `id`/hook/fetch modifié.
- **Diff snapshots** (`snapshot_compare`, `snapshot_detail`) : ajouté→`--green`, **modifié→`--amber`** (le bleu `--accent` reste réservé focus/liens), supprimé→`--red`.
- **`workflow/designer.php`** : couleurs de catégorie de nodes (déclencheurs/conditions/traitement/actions/attentes/timers) **neutralisées** en tokens ; `id`/`data-node-type`/`draggable`/JS canvas + 2 `<script>` préservés ; hex `#f3f4f6` du `#workflow-canvas` → `var(--app-bg)`.
- **Couplage `consume.php` ↔ `consume_card.php`** : le JS de `consume.php` qui bascule l'état des badges
  par `className.replace(...)` et recrée des badges a été aligné sur le markup `.ds-chip--accent/green/neutral`
  de `consume_card.php` (boutons × via `style.color` accent/green/red). Cohérence vérifiée.
- **Toggles** (indexing, consume ×2) : les utilitaires `peer-checked:`/`after:` (non inlinables, pas de classes arbitraires dans le CSS prébuild) tokenisés via un petit `<style>` scopé (`.idx-switch` / `.cns-switch`) — comportement `peer` conservé.

**Décisions à confirmer (clair + sombre) :**
1. **Catégories décoratives neutralisées** (snapshots type, entités, IA/Local, **node-types du designer**, chiffres de stats colorés) → monochrome. Changement visuel volontaire (perte du code couleur), conforme DS + précédent Agent A.
2. **Diff « modifié » bleu → ambre** (accent réservé focus/liens).
3. **Boutons « Restaurer »/« Scanner » → anthracite** (`.btn-primary`) au lieu de vert/bleu ; « Valider et classer » conservé **vert** (`.ds-btn-green`, action de validation).
4. **`text-white`** conservé uniquement sur surfaces colorées/primaires (boutons, chips solides).

**Demande de classe CSS (récurrent, stopgap inline en attendant) :** voir compte-rendu — cartes d'état
`.ds-card--ok/--warn/--neutral` (bordure + fond `color-mix` 10 %) répétées dans diagnostic/indexing/snapshots/alertes.

**Gates :** `php -l` **29/29** OK · grep résidu **0/29** · attributs hooks **inchangés vs HEAD** (29/29).
`migration_smoke`/phpunit/Playwright **non rejoués** (hors mandat « gates légères » Agent E ; au superviseur).
