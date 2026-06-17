# GEDv1 — Analyse code

> Revue technique post-migration — 2026-06-17

## Synthèse

| Aspect | Évaluation | Commentaire |
|--------|------------|-------------|
| Maturité fonctionnelle | **Élevée** | GED core riche (IA, workflows, API) |
| Architecture | **Moyenne** | Monolithe routes, modularité partielle |
| Tests | **Moyenne** | 20 tests PHPUnit + scripts smoke/api |
| Sécurité | **Moyenne+** | Auth/CSRF/rate-limit ; vigilance uploads/SQL |
| Dette technique | **Élevée** | index.php massif, apps non branchées, P0 bugs UI |
| Documentation | **Bonne** | docs/ existant + oracles migration |

## Dépendances (`composer.json`)

### Production

| Package | Usage |
|---------|-------|
| `slim/slim` ^4 | Framework HTTP |
| `slim/psr7` ^1 | Messages PSR-7 |
| `php-di/php-di` ^7 | Injection dépendances |
| `monolog/monolog` ^3 | Logging |

### Extensions PHP requises

`curl`, `mbstring`, `pdo`, `gd`, `zip`, `fileinfo` — optionnelles runtime : `odbc` (WinBiz), `imap` (mail).

### Dev

`phpunit/phpunit` ^10, `phpstan/phpstan` ^1.10, `php_codesniffer`, `php-cs-fixer`.

### Outils externes (non Composer)

Tesseract, Poppler (pdftotext), Ghostscript, LibreOffice, Ollama, OnlyOffice Docker, Qdrant binaire.

## Schéma base de données

**Fichier canonique** : `database/schema_consolidated.sql` (~495 lignes).

### Tables principales

| Table | Rôle |
|-------|------|
| `users`, `groups`, `user_groups` | Authentification |
| `role_types`, `user_roles` | Rôles métier validation |
| `documents` | Métadonnées documents |
| `document_types`, `correspondents`, `tags` | Référentiels |
| `document_tags`, `custom_fields` | Relations |
| `workflows`, `workflow_nodes`, `workflow_executions` | Automatisation |
| `audit_logs` | Traçabilité |
| `classification_fields`, `attribution_rules` | Classification |
| `embeddings` / fulltext | Recherche (migration 026, 028) |
| `snapshots`, `document_versions` | Versioning |
| `notifications`, `chat_conversations`, `user_notes` | Collaboration |

**Migrations** : 30+ fichiers dans `database/migrations/` — risque de drift si `schema_consolidated.sql` non régénéré après chaque migration.

## Points d'attention architecture

### 1. Monolithe `index.php` (~52 Ko)

- ~800 lignes de routes inline
- Traitement background en fin de requête (`DocumentProcessor`, `CrawlerAutoTrigger`)
- **Risque** : conflits merge, difficulté test unitaire routes
- **Recommandation** : extraire par fichiers `routes/web.php`, `routes/api.php`, `routes/apps.php`

### 2. Apps non intégrées

`apps/invoices/routes.php` et `apps/mail/routes.php` définissent des routes vers des contrôleurs **inexistants**.

### 3. Duplication backup

Fichiers `*.backup_*`, `SearchService.php.backup_*` dans `app/Services/` — à nettoyer.

### 4. Config sensible

- `.env` copié contient `TEST_USER` / `TEST_PASSWORD` uniquement (OK)
- **Exclus de la copie** : `claude_api_key.txt`, `cookies.txt` (bien)
- `config/config.php` peut contenir clés API — vérifier avant commit git

## Qualité code

### Points positifs

- Namespaces PSR-4 cohérents
- Interfaces contrats (`app/Contracts/`)
- Exceptions typées (`app/Exceptions/`)
- PHPStan configuré (`phpstan.neon`)
- Middleware stack structuré
- Moteur workflow extensible (Strategy par nœud)

### Dette identifiée

| Item | Fichier / zone | Sévérité |
|------|----------------|----------|
| Miniatures cassées | `ThumbnailGenerator`, templates | P0 |
| Aperçu modale DOCX | `templates/documents/index.php` | P0 |
| OCR non indexé | `DocumentProcessor` | P0 |
| `env()` manquant | `connectors/winbiz/config.php` utilise `env()` non définie dans le core | P1 |
| Qdrant désactivé par défaut | `config.php` | Info |
| Models tout-statique | `app/Models/` | Design debt |

## Sécurité

### Authentification

- Sessions PHP via `KDocs\Core\Auth`
- `password_hash` en BDD
- Vérification mot de passe faible
- Routes API protégées `AuthMiddleware`

### CSRF

- Token sur formulaires web (`CSRFMiddleware`)
- API JSON : auth session/cookie (pas de JWT documenté)

### Uploads

- Whitelist extensions `storage.allowed_extensions`
- Stockage hors webroot (`storage/documents/`)
- **À vérifier** : validation MIME réelle vs extension, taille max, scan antivirus (absent)

### SQL injection

- Majorité via PDO prepared statements (`Database`, Models)
- **WinBiz ODBC** : `WinBizConnector::executeQuery()` — vérifier échappement paramètres `?`
- Pas d'ORM — requêtes raw dans certains Models à auditer

### XSS

- Templates PHP — vérifier `htmlspecialchars()` sur sorties utilisateur
- CSP configurée dans `index.php` (OnlyOffice localhost)

### Rate limiting

- `RateLimitMiddleware` sur groupe protégé

### Secrets

- Ne pas versionner `.env`, `claude_api_key.txt`, tokens OnlyOffice JWT

## Performance

- Indexation filesystem incrémentale (`.index`)
- Cache APCu possible (roadmap, pas implémenté)
- Traitement documents en fin de requête HTTP (risque timeout sur gros volumes)
- Workers CLI disponibles mais déploiement cron à configurer

## Tests existants

| Suite | Fichiers | Couverture |
|-------|----------|------------|
| Unit | 20 tests | Core, Services, Helpers |
| Feature | 5 tests | Upload, API, Health, Validation |
| Scripts | smoke, api, integration, ui | Nécessitent serveur + BDD |

**Nouveau** : `tests/migration_smoke_test.php` — offline, structure dépôt.

## Recommandations prioritaires

1. Corriger P0 (`docs/CORRECTIONS_PRIORITAIRES.md`)
2. `composer install` + `run-tests.bat` en CI locale
3. Extraire routes de `index.php`
4. Brancher ou supprimer stubs `apps/invoices`, `apps/mail`
5. Implémenter `ConnectorInterface` sur WinBiz + test ODBC
6. Régénérer `schema_consolidated.sql` après migrations
7. Nettoyer fichiers `.backup_*`
8. Ajouter `.env.example` (absent)

---
*Dernière mise à jour : 2026-06-17*
