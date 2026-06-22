# GEDv1 — Oracles (invariants et contrats)

> Source de vérité comportementale pour K-Docs / GEDv1.
> Dépôt : `F:\DATA\DEVELOPPEMENT\GEDv1` (copié depuis `C:\wamp64\www\kdocs`).

## Invariants métier

### Documents

1. **Filesystem-first** : le fichier physique est la source ; la BDD porte métadonnées, index et relations.
2. **Pas d'écrasement silencieux** : upload et versioning créent de nouvelles entrées ; la corbeille est un soft-delete (`storage/trash/` + flag BDD).
3. **Séparation work / source utilisateur** : `storage/documents/` = espace applicatif ; pas de copie automatique vers un dossier utilisateur externe sans action explicite.
4. **Extensions autorisées** : définies dans `config.php` → `storage.allowed_extensions` (pdf, images, office, msg…).
5. **Consume folder** : ingestion automatique depuis `storage/consume/` avec validation humaine possible (`ConsumeController`, `ConsumptionApiController`).

### Classification et IA

1. **Cascade IA** : ordre configurable `ai.cascade` (training → claude → ollama → rules).
2. **Suggestions ≠ application automatique** : toute suggestion IA (`ClassificationSuggestionsApiController`) requiert action utilisateur ou règle d'attribution explicite.
3. **Audit classification** : toute modification de classification peut être tracée (`classification_audit_logs`, `ClassificationAuditApiController`).
4. **Embeddings optionnels** : Qdrant désactivé par défaut (`qdrant.enabled = false`) ; fallback MySQL fulltext / Ollama.

### Workflows et validation

1. **Workflows visuels** : nœuds typés (triggers, conditions, actions, waits) via `WorkflowEngine` + `NodeExecutorFactory`.
2. **Validation modulaire** : rôles métier (`role_types`, `user_roles`) avec montants et périmètres (`ValidationService`).
3. **Approbation par token** : route publique `/workflow/approve/{token}` pour approbateurs externes.

### Sécurité et auth

1. **Sessions PHP** : authentification via `KDocs\Core\Auth` ; routes `/api/*` protégées par `AuthMiddleware` sauf endpoints explicitement publics.
2. **CSRF** : formulaires web protégés par `CSRFMiddleware` + `KDocs\Core\CSRF`.
3. **Rate limiting** : `RateLimitMiddleware` sur le groupe protégé.
4. **Mots de passe faibles refusés** : `Auth::isWeakPassword()`.
5. **Secrets hors dépôt** : clés API Claude via env ou config locale ; ne jamais versionner `.env` avec secrets réels.

### Intégrations

1. **OnlyOffice** : édition via JWT/callback ; URLs publiques tokenisées pour download/callback uniquement.
2. **WinBiz** : accès ODBC **lecture seule recommandée** ; connecteur isolé sous `connectors/winbiz/`.
3. **Apps satellites** : 100 % PHP natif, **pas de Docker** pour les apps (OnlyOffice peut utiliser Docker pour la GED seule).

## Contrats API REST

Préfixe : `/api/` (groupe authentifié sauf mention **public**).

| Domaine | Contrat | Réponse attendue |
|---------|---------|------------------|
| Santé | `GET /health` **public** | JSON `{ status, checks... }` |
| Documents | `GET/POST/PUT/DELETE /api/documents` | JSON paginé, codes 4xx explicites |
| Recherche | `POST /api/search/*`, `/api/semantic-search/*` | JSON avec scores et métadonnées |
| Validation | `/api/validation/*` | États : pending, validated, rejected |
| OnlyOffice | `/api/onlyoffice/*` | Config éditeur + callback save |
| Erreurs API | Toutes routes `/api/*` | JSON `{ error, message }` via error middleware |

Documentation détaillée existante : `docs/API.md`.

## Conventions code

| Règle | Détail |
|-------|--------|
| Namespace core | `KDocs\` → `app/` |
| Namespace apps | `KDocs\Apps\` → `apps/` |
| Namespace connecteurs | `KDocs\Connectors\{Name}\` → `connectors/{name}/` |
| Framework HTTP | Slim 4, routes déclarées dans `index.php` |
| Modèles | Méthodes statiques sur classes `app/Models/*` |
| Migrations | `database/migrations/` + runner `app/Core/Migrations.php` |
| Tests gate | `run-tests.bat` → migration smoke offline ; `composer test` si vendor présent |
| Encoding | UTF-8 partout |
| Commits | Français, impératif (`feat(ged):`, `fix:`, `docs:`) |

## Invariants UI / chrome

- Templates PHP sous `templates/` (pas de framework JS).
- Document preview : miniature via `/documents/{id}/thumbnail`.
- Paramètres admin : `SettingsController`, `templates/admin/settings.php`.

## Dette connue (ne pas violer sans décision)

1. **Monolithe routes** : ~800 lignes dans `index.php` — extraire progressivement, pas de réécriture massive.
2. **Apps non branchées** : `apps/invoices/routes.php` et `apps/mail/routes.php` non chargés par `index.php`.
3. **P0 corrections** : voir `docs/CORRECTIONS_PRIORITAIRES.md` (miniatures, aperçu modale, OCR).
4. **Connecteur WinBiz** : code présent, statut « à valider en conditions réelles » (ODBC 32-bit).

## Oracles produit (shell, plugins, chrome)

Invariants navigation, `PluginRegistry`, ingest workers-only et règles chrome user/admin :
**`docs/ORACLES-KDOCS-PRODUCT.md`**

Spec simplification REDX : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`  
Roadmap phases B0→P2 : `docs/ROADMAP-KDOCS-PRODUCT.md`

## Fichiers de référence

| Fichier | Rôle |
|---------|------|
| `config/config.php` | Configuration active |
| `database/schema_consolidated.sql` | Schéma BDD canonique |
| `connectors/README.md` | Contrat connecteurs |
| `docs/ROADMAP.md` | Feuille de route technique (legacy) |
| `docs/ROADMAP-KDOCS-PRODUCT.md` | Feuille de route produit B0→P2 |
| `docs/ORACLES-KDOCS-PRODUCT.md` | Invariants shell/plugins/chrome |
| `docs/DETTE-UI-ORPHELINS.md` | Menus masqués / stubs |
| `docs/CORRECTIONS_PRIORITAIRES.md` | Bugs bloquants |

---
*Dernière mise à jour : 2026-06-22 — oracle produit B0*
