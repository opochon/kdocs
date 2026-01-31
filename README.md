# K-Docs

Gestion Electronique de Documents (GED) modulaire avec applications metier integrees.

## Vision

- **Filesystem-first** : Documents dans une arborescence classique
- **Leger** : Pas de Docker pour les apps (PHP natif)
- **Portable** : Embarquable dans une app desktop (Tauri)
- **Intelligent** : Classification IA, recherche semantique

## Structure

```
kdocs/
├── app/              # GED Core
├── apps/             # Applications integrees (PHP natif)
│   ├── mail/        # Client mail + agenda
│   ├── timetrack/   # Saisie horaire
│   └── invoices/    # Gestion factures fournisseurs
├── connectors/      # Connecteurs ERP
│   └── winbiz/      # WinBiz (ODBC)
├── bin/             # Binaires (qdrant, etc.)
├── shared/          # Code partage
│   ├── Auth/        # Authentification unifiee
│   ├── ApiClient/   # Client API K-Docs
│   ├── UI/          # Composants UI
│   └── Helpers/     # Fonctions utilitaires
├── config/          # Configuration
├── docs/            # Documentation
├── public/          # Assets statiques
├── storage/         # Fichiers stockes
├── templates/       # Vues PHP
├── tests/           # Tests
└── tools/           # Installation et maintenance
```

## Stack technique

| Composant | Technologie |
|-----------|-------------|
| Backend | PHP 8.2+ natif |
| Base GED | MySQL 8.0 / MariaDB 11.5 |
| Base Apps | MySQL (partagee) ou SQLite |
| Vectorisation | Qdrant (binaire, PAS Docker) |
| IA | Claude API / Ollama local |
| OCR | Tesseract |
| Office | OnlyOffice (Docker, GED seulement) |

## Installation

### Prerequis
- PHP 8.2+
- MariaDB 11.5+ sur port 3307
- Composer
- Apache avec mod_rewrite
- Tesseract OCR (optionnel)
- Qdrant binaire (optionnel, pour recherche semantique)

### Installation rapide

```bash
# 1. Installer les dependances
composer install

# 2. Creer la base de donnees
php database/install.php

# 3. Acceder a l'application
# URL: http://localhost/kdocs
# Compte: root (mot de passe vide)
```

## Fonctionnalites GED Core

- [x] Gestion documents (CRUD, arborescence)
- [x] OCR multi-langue (Tesseract)
- [x] Extraction texte PDF natif (pdftotext)
- [x] Extraction texte DOCX/Office
- [x] Miniatures automatiques
- [x] Classification par regles + IA
- [x] Tags, correspondants, types
- [x] Recherche fulltext + semantique
- [x] API REST complete
- [x] Workflows visuels
- [x] Dossier consume (ingestion auto)
- [x] Corbeille (soft delete)
- [x] Audit logs

## Applications integrees

| App | Description | Statut |
|-----|-------------|--------|
| K-Mail | Client mail + agenda IMAP | A faire |
| K-Time | Saisie horaire + factures | A faire |
| K-Invoices | Gestion factures fournisseurs | A faire |

**Contrainte** : Toutes les apps sont 100% PHP natif (PAS de Docker).

## Connecteurs

| Connecteur | Type | Statut |
|------------|------|--------|
| WinBiz | ODBC/FoxPro | A faire |
| kDrive | WebDAV | Planifie |
| SharePoint | Graph API | Planifie |

## API REST

| Endpoint | Description |
|----------|-------------|
| `GET /api/documents` | Liste documents |
| `GET /api/documents/{id}` | Details document |
| `POST /api/documents/upload` | Upload document |
| `GET /api/search` | Recherche |
| `GET /api/health` | Etat du systeme |

Voir [docs/API.md](docs/API.md) pour la documentation complete.

## Documentation

- [Feuille de route](ROADMAP.md)
- [Structure apps](docs/KDOCS_STRUCTURE_APPS.md)
- [Spec K-Time](docs/KTIME_SPECIFICATION.md)
- [Corrections prioritaires](docs/CORRECTIONS_PRIORITAIRES.md)

## Tests

```bash
# Tous les tests
php vendor/bin/phpunit

# Tests avec details
php vendor/bin/phpunit --testdox

# Suite de tests complete
php tests/full_test_suite.php
```

## Licence

Proprietaire - Karbonic Sarl

---
*K-Docs - GED modulaire, intelligente, portable*
