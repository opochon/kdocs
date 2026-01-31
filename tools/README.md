# K-Docs Tools

Outils d'installation et de maintenance.

## Structure

```
tools/
├── install/           # Scripts d'installation
├── sql/              # Scripts SQL de reference
└── README.md
```

## Dossier sql/

Scripts SQL pour reference. Les migrations principales sont dans :
- `database/migrations/` - Migrations K-Docs Core
- `apps/*/migrations/` - Migrations des applications

## Scripts d'installation

A venir :
- `install/setup.php` - Installation complete
- `install/check-deps.php` - Verification dependances
- `install/migrate.php` - Execution migrations

## Utilisation

```bash
# Verifier les dependances
php tools/install/check-deps.php

# Executer les migrations
php tools/install/migrate.php

# Installation complete
php tools/install/setup.php
```

---
*K-Docs Tools*
