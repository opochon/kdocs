# GEDv1 — Architecture technique

> K-Docs (nom interne : kdocs) — GED modulaire PHP native.
> Emplacement : `F:\DATA\DEVELOPPEMENT\GEDv1`

## Vue d'ensemble

```
┌─────────────────────────────────────────────────────────────┐
│  Navigateur / Client API                                     │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTP
┌──────────────────────────▼──────────────────────────────────┐
│  index.php (Slim 4)                                          │
│  ├── Middleware: Auth, CSRF, RateLimit, AutoIndex             │
│  ├── Controllers Web (templates/)                            │
│  └── Controllers API (JSON)                                  │
└──────────┬───────────────────────────────┬──────────────────┘
           │                               │
┌──────────▼──────────┐         ┌──────────▼──────────┐
│  app/Services/       │         │  app/Models/         │
│  OCR, IA, Search,    │◄───────►│  Document, User,     │
│  Workflow, OnlyOffice│         │  Workflow, Tag…      │
└──────────┬──────────┘         └──────────┬──────────┘
           │                               │
┌──────────▼───────────────────────────────▼──────────────────┐
│  MySQL/MariaDB (kdocs)          storage/ (fichiers)          │
│  schema_consolidated.sql        documents, thumbs, consume   │
└──────────────────────────────────────────────────────────────┘
           │                               │
┌──────────▼──────────┐         ┌──────────▼──────────┐
│  Qdrant (optionnel)  │         │  OnlyOffice Docker   │
│  port 6333           │         │  port 8080           │
└─────────────────────┘         └─────────────────────┘
           │
┌──────────▼──────────┐
│  Ollama / Claude API │
└─────────────────────┘
```

## Stack

| Couche | Technologie | Version / note |
|--------|-------------|----------------|
| Runtime | PHP | ≥ 8.1 (cible 8.2+) |
| HTTP | Slim 4 + PHP-DI | `composer.json` |
| BDD | MySQL / MariaDB | Port dev souvent 3307 |
| Logs | Monolog | `app/Core/` |
| Tests | PHPUnit 10 | `tests/Unit`, `tests/Feature` |
| Static analysis | PHPStan | `phpstan.neon` |
| Vector search | Qdrant binaire | `bin/qdrant.exe`, désactivé par défaut |
| OCR | Tesseract | Config `config.tools` |
| Office | LibreOffice CLI | Miniatures + conversion |
| Édition | OnlyOffice | Docker optionnel |
| IA | Claude API / Ollama | Cascade configurable |

## Points d'entrée

| Entrée | Chemin | Rôle |
|--------|--------|------|
| HTTP principal | `index.php` | Bootstrap, routes, `$app->run()` |
| Bootstrap | `app/Core/App.php` | Factory Slim, timezone, error JSON API |
| Autoload | `vendor/autoload.php` + repli `app/autoload.php` | PSR-4 |
| Workers CLI | `app/workers/*.php` | Queue, indexation, crawl dossiers |
| Install BDD | `database/install.php` | Création schéma initial |
| Migrations | `database/migrations/`, `app/Core/Migrations.php` | Évolution schéma |

## Structure des dossiers

```
GEDv1/
├── app/                 # Cœur (~252 fichiers PHP)
│   ├── Controllers/     # Web + Api/ + Admin/
│   ├── Core/            # App, Config, Auth, Database, Migrations
│   ├── Services/        # Métier (OCR, IA, workflows…)
│   ├── Models/          # Accès données statique
│   ├── Middleware/
│   ├── Workflow/Nodes/  # Moteur workflow visuel
│   └── workers/
├── apps/                # Apps satellites
│   ├── timetrack/       # Partiellement câblé
│   ├── invoices/        # Stub routes
│   └── mail/            # Stub routes
├── connectors/          # ERP / cloud
│   └── winbiz/          # ODBC FoxPro
├── config/              # config.php actif
├── database/            # Schémas + migrations (~96 fichiers)
├── templates/           # Vues PHP
├── public/css/          # Assets
├── storage/             # Runtime (gitignored en prod)
├── tests/               # PHPUnit + scripts smoke/api
└── docs/                # Documentation projet
```

## Flux document principal

1. **Upload** → `DocumentsController::upload` ou `DocumentsApiController::create`
2. **Stockage** → `storage/documents/{yyyy}/{mm}/`
3. **Traitement async** → `DocumentProcessor::processPendingDocuments()` (fin de requête `index.php`)
4. **Pipeline** : extraction texte → OCR si besoin → miniature → classification → embeddings optionnels
5. **Indexation** → MySQL fulltext + Qdrant si activé
6. **Workflows** → déclencheurs sur upload/tag/validation

## Couche API

~40 contrôleurs API sous `app/Controllers/Api/`. Routes groupées dans `index.php` lignes ~100-900.

Domaines : documents, dossiers, recherche, IA, workflows, validation, notifications, chat, notes, extraction, snapshots, versions, MSG import, email ingestion.

## Apps modulaires (état)

| App | Dossier | Branché dans index.php |
|-----|---------|------------------------|
| K-Time | `apps/timetrack/` | Partiel (`/time/*`) |
| K-Invoices | `apps/invoices/` | Non |
| K-Mail | `apps/mail/` | Non |

Pattern prévu : `apps/{name}/routes.php` + `config.php` + autoload PSR-4 `KDocs\Apps\`.

## Connecteurs

Architecture isolée par dossier, interface cible `ConnectorInterface` (`connectors/README.md`).

Implémenté : **WinBiz** (`WinBizConnector.php`) — articles, clients, BL, fiches travail via ODBC.

Planifiés : kDrive, SharePoint, Nextcloud, S3.

## Base de données

Schéma consolidé : `database/schema_consolidated.sql` (2026-01-25).

Tables principales : `users`, `groups`, `documents`, `document_types`, `correspondents`, `tags`, `workflows`, `workflow_executions`, `audit_logs`, `classification_fields`, `embeddings` (si migration 026), `snapshots`, `document_versions`, `notifications`, `chat_conversations`, `user_notes`.

Migrations numérotées `007`–`030` + scripts datés `2026_*`.

## Déploiement dev

```cmd
cd F:\DATA\DEVELOPPEMENT\GEDv1
composer install
php database/install.php
REM Apache WAMP : alias ou copie vers www/kdocs
REM URL : http://localhost/kdocs
```

## Évolutions architecturales prévues

1. Extraction routes `index.php` → fichiers par domaine
2. Chargement dynamique `apps/*/routes.php`
3. Système plugin formalisé (voir `docs/PLUGIN-SYSTEM.md`)
4. App desktop Tauri + FrankenPHP (roadmap Q2-Q3 2026)

---
*Dernière mise à jour : 2026-06-17*
