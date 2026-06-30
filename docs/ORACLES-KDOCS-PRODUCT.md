# K-Docs — Oracles produit (shell, plugins, chrome)

> Invariants **produit et navigation** — complément de `docs/ORACLES.md` (métier/API).  
> Spec source : `docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md`

---

## 1. Shell user — routes canoniques

Le shell GED expose **5 intentions** (4 entrées sidebar + hub admin séparé) :

| Intention | Route canonique | Contrôleur / template |
|-----------|-----------------|----------------------|
| Bibliothèque | `GET /documents` | `DocumentsController`, `templates/documents/index.php` |
| Recherche | `GET /search` (canonique B1) ; `GET /chat` → redirect legacy | `SearchController` |
| À traiter | `GET /mes-taches` | `TaskController` + badge `pending`/`needs_review` |
| Importer | `GET /documents/upload` | upload + lien consume (`/admin/consume` admin-only) |
| Admin hub | `GET /admin` | hub tuiles — **hors navigation user principale** |

**Dashboard** (`GET /`) : page d'accueil optionnelle — **pas** une 6e entrée sidebar permanente (fusionnable dans Bibliothèque en B1).

### Interdits shell user

- Pas de lien sidebar vers `/invoices`, `/mail` tant que plugin désactivé.
- Pas de référentiels admin (tags, types, workflows…) dans la sidebar user — uniquement sous `/admin/*`.
- Pas d'emoji dans libellés ou boutons chrome (SVG uniquement).

---

## 2. Plugin registry

### Chargement

```php
// index.php — groupe authentifié
PluginRegistry::registerAppRoutes($group);
```

| Règle | Détail |
|-------|--------|
| Activation | `apps/{name}/config.php` → `app.enabled` (bool ou `env('…_ENABLED')`) |
| Routes | `apps/{name}/routes.php` retourne callable `function (RouteCollectorProxy $group)` |
| Exception | `timetrack` reste enregistré explicitement dans `index.php` (historique K-Time) |
| Désactivé | Aucune route montée — **aucune promesse UI** |

### Plugins métier (rollout)

| Plugin | Dossier | Flag activation | Phase |
|--------|---------|-----------------|-------|
| Factures | `apps/invoices/` | `INVOICES_APP_ENABLED` | A |
| Mail | `apps/mail/` | `app.enabled` dans config | Post-A |
| SMQ | `apps/smq/` (futur) | — | C |
| RH | `apps/rh/` (futur) | — | D |

### Connecteurs (≠ plugins UI)

- WinBiz : `connectors/winbiz/` — données ERP, pas de routes user directes.
- Bridge HTTP : `WinbizIntegrator` / `k-winbiz-bridge` — **pas** dans le core PHP.
- Registre unifié : `config/connectors.php` + `ConnectorRegistry::healthAll()` — voir `docs/CONNECTEURS-PLUGINS.md`.

| Règle | Détail |
|-------|--------|
| Ingest natif | `ingest-native` **toujours** `available` — GED autonome sans CMD ni WinBiz |
| Activation | Connecteur `enabled` dans `.env` + `health()` OK → `available` |
| Plugin `requires` | Ex. `invoices` requiert `erp-winbiz` — sinon statut `blocked`, pas de route UI |
| Diagnostic | `GET /admin/diagnostic` + `GET /api/admin/connectors/health` exposent le registre |
| `/health` public | Clé `connectors_registry` — résumé statuts (pas d'écriture ERP implicite) |

---

## 3. Ingest — workers only

| Invariant | Implémentation |
|-----------|----------------|
| Upload HTTP léger | Persistance fichier + enqueue `QueueService` |
| Traitement lourd async | `app/workers/queue_worker.php`, jobs `ClassifyDocumentJob`, etc. |
| Dual-mode CMD | `IngestEngineRouter` — **jamais** appel sidecar en boucle synchrone HTTP request |
| CMD v4 factures | PDF facture → `CmdV4IngestEngine` si v4 up ; sinon v3 ou natif — **jamais** 500 |
| Classification unique | `UnifiedClassifier` via `IngestClassificationService` |
| Pas de sync en fin de route | **Interdit** : OCR/classify/thumbnail dans middleware ou closure `index.php` post-response |

Config : `CMD_V4_ENABLED`, `CMD_V4_URL` — voir `docs/CMD-V4-CONNECTOR.md`.

---

## 4. Chrome et séparation admin

| Règle | État cible |
|-------|-------------|
| Sidebar user ≤ 5 entrées + lien Admin | Phase B0/B1 |
| Hub `/admin` | Tuiles : Paramètres, Tags, Workflows, Utilisateurs, Diagnostic… |
| Bannière sécurité root | Visible **uniquement** si `APP_DEBUG=true` |
| Compteurs sidebar | Même requête SQL que dashboard pour `pending` et total docs |
| Filtre docs test | `test_*` masqués hors debug (`documentVisibilitySql`) |

Dette UI documentée : `docs/DETTE-UI-ORPHELINS.md`.

---

## 5. Dette technique acceptée (ne pas violer sans décision)

1. `index.php` monolithe — extraire par domaine, pas réécriture massive.
2. `templates/documents/index.php` lourd — refactor progressif B1.
3. Recherche fragmentée (`SearchService`, `AISearchService`) — unification B1.
4. Qdrant optionnel — UI masquée si `qdrant.enabled = false`.

---

## 6. Fichiers de référence

| Fichier | Rôle |
|---------|------|
| `app/Core/PluginRegistry.php` | Bootstrap plugins |
| `docs/ROADMAP-KDOCS-PRODUCT.md` | Avancement phases |
| `docs/PLUGIN-SYSTEM.md` | Architecture connecteurs/apps |
| `SESSION-STATUS.md` | État session + harness |
| `tests/visual/` | Harness visuel Playwright (smoke DOM + captures) |

---

## 7. Tests visuels (Playwright)

Smoke **DOM + captures** du shell authentifié, complément des smokes PHP (qui restent la vérif structurelle/fonctionnelle).

| Invariant | Détail |
|-----------|--------|
| Emplacement | `tests/visual/` (Node dev-only, isolé du cœur PHP) |
| Portée | routes canoniques section 1 : réponse HTTP < 400, pas de redirection `/login`, aucun marqueur d'erreur PHP, capture pleine page |
| Lancement | `make test-visual` ou `npm --prefix tests/visual test` — **non bloquant** (hors pre-commit) |
| Serveur | démarré par Playwright (`php -S … router.php`), réutilisé si déjà actif |
| Auth | login `root`/vide → `storageState` réutilisé |
| Évolution | baseline pixel (`toHaveScreenshot`) activable sans refonte |

Remplace `tests/screenshot_runner.ps1` (capture sans gestion d'auth, retirée).

---

## 8. Fiche document & versioning (C.2)

| Invariant | Détail |
|-----------|--------|
| Fiche = **modale** | `GET /documents/{id}` → 302 `…/documents?open={id}` ; le détail s'ouvre en modale construite en JS dans `templates/documents/index.php`. **`templates/documents/show.php` est legacy mort** (jamais rendu). |
| Versioning SMQ | onglet **Versions** contextuel dans la modale, gated `SMQ_ENABLED` (`PluginRegistry::isEnabled('smq')`). Pas de page `/smq` parallèle. |
| Backend | `DocumentVersionsApiController` + `DocumentVersion` (list/restore/diff/download/upload) — déjà complet, exposé en UI seulement. |
| Quittance lecture (C.3) | `document_read_receipts` (1 par doc+version+user) ; API `…/versions/{n}/read` (POST) + `…/read-status` (GET) ; bloc dans l'onglet Versions, gated SMQ. Utilisateur courant via `getAttribute('user')`. |
| Réponses API | JSON strict : aucun warning/HTML ne doit précéder le corps (sinon `JSON.parse` casse côté front). |
| Migrations `.sql` | pas de runner auto (`Migrations.php` = `.php` only) → `php tools/apply-sql-migration.php <fichier>`. |

---

*Dernière mise à jour : 2026-06-26 — C.2 versioning SMQ (modale) + harness visuel*
