# GEDv1 (K-Docs) — Audit code et qualité

> Date : 2026-06-18 · Périmètre : `F:\DATA\DEVELOPPEMENT\GEDv1`  
> Sources : `docs/CODE-ANALYSIS.md`, `docs/ARCHITECTURE.md`, `docs/ORACLES.md`, revue code + smoke 62 pages (64 OK / 0 KO hors apps stub)

---

## Synthèse

| Dimension | Score (1–10) | Commentaire |
|-----------|--------------|-------------|
| Maturité fonctionnelle | **7** | GED riche : IA, workflows, API, consume, OnlyOffice |
| Architecture | **5** | Monolithe routes, modularité partielle, apps satellites débranchées |
| Qualité / maintenabilité | **5** | PSR-4 cohérent mais dette visible (backups, models statiques) |
| Tests | **5** | 24 tests PHPUnit + smokes ; couverture faible sur pipeline ingestion/IA |
| Sécurité | **6** | Auth/CSRF/rate-limit solides ; uploads et secrets à durcir |
| Documentation | **7** | `docs/` dense, oracles migration, delta REDX |

**Score global code : 5,5 / 10** — base solide fonctionnellement, dette structurelle qui freine la montée en gamme pro.

---

## Architecture PHP / Slim

### Stack

| Couche | Technologie |
|--------|-------------|
| Runtime | PHP ≥ 8.1 (cible 8.2+) |
| HTTP | Slim 4 + PHP-DI 7 |
| BDD | MySQL/MariaDB (port dev 3307) |
| Logs | Monolog |
| Analyse | PHPStan (`phpstan.neon`), PHPCS, PHP-CS-Fixer |
| Tests | PHPUnit 10 |

### Schéma d'ensemble

```
Navigateur / API client
        ↓
index.php (~795 lignes) — routes inline + traitement fin requête
        ↓
Controllers (Web + Api/ + Admin/)
        ↓
Services (~45 classes métier) ↔ Models statiques
        ↓
MySQL + storage/ (filesystem-first)
        ↓
Workers CLI (queue, indexation) · Qdrant (opt.) · Ollama/Claude
```

### Points d'entrée

| Entrée | Fichier | Rôle |
|--------|---------|------|
| HTTP | `index.php` | Bootstrap Slim, ~40 contrôleurs API, middleware stack |
| Router dev | `router.php` | Serveur PHP built-in port 8765 |
| Workers | `app/workers/*.php` | Queue OCR/thumbnail/indexation |
| BDD | `database/install.php`, `app/Core/Migrations.php` | Schéma + 30+ migrations |

### Services clés (métier)

| Domaine | Services principaux |
|---------|---------------------|
| Ingestion | `ConsumeFolderService`, `DocumentProcessor`, `EmailIngestionService`, `MSGImportService` |
| OCR / extraction | `OCRService`, `ExtractionService`, `MetadataExtractor` |
| Classification | `AIClassifierService`, `AutoClassifierService`, `FieldAIClassifierService`, `TrainingService`, `ClassificationService` |
| Recherche | `SearchService`, `AISearchService`, `VectorSearchService`, `EmbeddingService`, `NaturalLanguageQueryService` |
| Workflows | `WorkflowService`, `WorkflowEngine`, nœuds `app/Workflow/Nodes/` |
| Intégrations | `OnlyOfficeService`, `WinBizConnector`, plugins `app/Core/PluginRegistry.php` |

---

## Dette technique

### 1. Monolithe `index.php` (~795 lignes)

- Routes web + API + middleware + **traitement background en fin de requête** (`DocumentProcessor`, `CrawlerAutoTrigger`).
- Risques : conflits merge, tests routes impossibles, timeouts HTTP sur gros volumes.
- Recommandation : extraire `routes/web.php`, `routes/api.php`, `routes/admin.php` ; déplacer le post-traitement vers workers uniquement.

### 2. Apps satellites non branchées

| App | État | Preuve |
|-----|------|--------|
| `apps/invoices/` | Routes définies, **404 en prod dev** | Smoke : `/invoices`, `/invoices/api/pending` → 404 |
| `apps/mail/` | Stub | Non chargé dans `index.php` |
| `apps/timetrack/` | Partiel | `/time/*` OK |

### 3. Duplication et fichiers morts

- Fichiers `*.backup_*` dans `app/Services/` (ex. `SearchService.php.backup_*`).
- Config WAMP héritée visible en settings (`C:\wamp64\www\kdocs\...`) alors que le dépôt est sous `F:\DATA\DEVELOPPEMENT\GEDv1`.

### 4. Models tout-statique

- Pattern `app/Models/*` avec méthodes statiques : testabilité limitée, couplage BDD fort.
- Pas d'ORM — requêtes raw à auditer cas par cas.

### 5. Drift schéma BDD

- `database/schema_consolidated.sql` (~495 lignes) vs 30+ migrations — risque de divergence si non régénéré.

---

## Sécurité

### Points forts

- Sessions PHP (`KDocs\Core\Auth`), `password_hash`, refus mots de passe faibles.
- `CSRFMiddleware` sur formulaires web.
- `RateLimitMiddleware` sur groupe protégé.
- Headers sécurité + CSP dans `index.php` (OnlyOffice localhost).
- Stockage hors webroot (`storage/documents/`).
- Secrets hors git (`.env`, `claude_api_key.txt`).

### Vigilance requise

| Zone | Risque | Action |
|------|--------|--------|
| Uploads | Whitelist extension ; validation MIME non documentée | Ajouter contrôle MIME + taille + scan optionnel |
| Compte root dev | Mot de passe vide — bannière orange permanente | Forcer mot de passe au 1er login |
| WinBiz ODBC | Requêtes paramétrées à vérifier | Audit `WinBizConnector::executeQuery()` |
| XSS templates | Sorties PHP mixtes | Audit systématique `htmlspecialchars()` |
| API | Session/cookie, pas de JWT documenté | OK intranet ; documenter pour intégrations externes |
| OnlyOffice | `ssl_verify: false` en dev | Désactiver en prod |

---

## Tests et couverture

### Suites existantes

| Suite | Volume | Périmètre |
|-------|--------|-----------|
| Unit | 24 fichiers | Core, Auth, CSRF, Services (Search, AI, Matching, Training…) |
| Feature | ~5 tests | Upload, API, Health, Validation |
| Scripts | smoke, api, integration, ui | Nécessitent serveur + BDD |
| Smoke complet | 62 pages | 64 OK — 2026-06-18 (`docs/SMOKE-FULL-REPORT.md`) |

### Lacunes de couverture

- **Aucun test E2E** sur pipeline complet upload → OCR → classification → recherche.
- `DocumentProcessor` : pas de test d'intégration avec Tesseract mock.
- `ConsumeFolderService` : pas de test de charge / concurrence lock.
- `AIClassifierService` : tests unitaires partiels (`ClaudeServiceTest`, `AIProviderServiceTest`) sans scénarios cascade complète.
- Couverture PHPUnit HTML configurée (`tests/output/coverage/`) mais non exécutée dans ce audit.

### Gate recommandé

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
composer test
run-tests.bat
tools\run-live-smokes.bat
```

---

## Points forts

1. **Richesse fonctionnelle** proche Paperless-ngx + extensions (workflows visuels, chat IA, validation modulaire, WinBiz).
2. **Contrats documentés** : `docs/ORACLES.md`, cascade IA, suggestions ≠ application auto.
3. **Stack moderne** : Slim 4, PHP-DI, Monolog, PHPStan.
4. **API REST large** (~40 contrôleurs sous `app/Controllers/Api/`).
5. **Système plugin formalisé** (`docs/PLUGIN-SYSTEM.md`, `PluginRegistry`).
6. **Smokes récents verts** sur le cœur GED (hors apps stub).

---

## Points faibles

1. Monolithe routes + post-traitement synchrone HTTP.
2. UI et backend déconnectés (voir `AUDIT-UI-UX.md`).
3. Apps invoices/mail fantômes (404, dette cognitive).
4. Config héritée WAMP / chemins incohérents post-migration.
5. Qdrant désactivé, embeddings Ollama seuls — recherche sémantique partielle.
6. Parité REDX ~52 % (`docs/DELTA-REDX.md`) — conformité archivage Suisse absente.

---

## Recommandations priorisées

| Priorité | Action | Effort |
|----------|--------|--------|
| P0 | Extraire routes `index.php` + supprimer traitement sync fin requête | M |
| P0 | Brancher ou supprimer stubs `apps/invoices`, `apps/mail` | S |
| P1 | Tests intégration `DocumentProcessor` + `ConsumeFolderService` | M |
| P1 | Nettoyer `.backup_*`, régénérer `schema_consolidated.sql` | S |
| P1 | Durcir uploads (MIME, taille, antivirus optionnel) | M |
| P2 | Refactor Models → injection / repositories | L |
| P2 | CI locale : `composer test` + smoke gate avant commit | S |

---

*Références : `docs/CODE-ANALYSIS.md`, `docs/ARCHITECTURE.md`, `docs/ORACLES.md`, `docs/CORRECTIONS_PRIORITAIRES.md`, `docs/DELTA-REDX.md`*
