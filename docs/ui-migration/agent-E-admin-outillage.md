# Agent E — Admin : listes & outillage

**Priorité** : P2 (listes/outillage) + P3 (designer, attribution-rules) · **Dépend de** : A · **Statut** : `À FAIRE`

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

- _(vide)_
