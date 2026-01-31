# K-Docs Applications

Applications integrees legeres et portables.

## Contraintes techniques

- **PAS DE DOCKER** - Toutes les apps sont 100% PHP natif
- **Legeres** - Demarrage < 1 seconde
- **Portables** - Embarquables dans FrankenPHP/Tauri
- **Cross-platform** - Windows, Mac, Linux (futur: iOS, Android)

## Stack technique

| Composant | Technologie |
|-----------|-------------|
| Runtime | PHP 8.2+ natif |
| Base locale | SQLite (embarque) |
| Base partagee | MySQL (optionnel, GED) |
| Vectorisation | Qdrant binaire (PAS Docker) |
| Embeddings | Ollama local (optionnel) |
| Mail | PHP IMAP extension |
| Calendrier | CalDAV (Sabre/DAV) |

## Applications

| App | Description | Statut | Dependances |
|-----|-------------|--------|-------------|
| [mail](./mail/) | Client mail + agenda | A faire | IMAP ext, Qdrant bin |
| [timetrack](./timetrack/) | Saisie horaire + factures | A faire | MySQL |
| [invoices](./invoices/) | Gestion factures fournisseurs | A faire | GED Core, WinBiz |

## Structure d'une app

```
app-name/
├── Controllers/        # Controleurs HTTP
├── Models/            # Modeles de donnees
├── Services/          # Logique metier
├── templates/         # Vues PHP
├── migrations/        # Scripts SQL
├── routes.php         # Definition des routes
├── config.php         # Configuration
└── README.md          # Documentation
```

## Objectif : Application standalone

Chaque app peut fonctionner :
1. **Integree** dans K-Docs (mode web classique)
2. **Standalone** dans une app desktop (Tauri + FrankenPHP)
3. **Mobile** via PWA ou wrapper natif (futur)

## Utilisation du code partage

```php
// Authentification
use KDocs\Shared\Auth\KDocsAuthAdapter;
$auth = new KDocsAuthAdapter();

// Client API K-Docs
use KDocs\Shared\ApiClient\KDocsClient;
$client = new KDocsClient();

// Helpers
require_once __DIR__ . '/../shared/Helpers/functions.php';
$duration = parse_duration('2h30');
```

## Configuration

Les apps utilisent la configuration centralisee de K-Docs :

```php
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\Database;

$config = Config::get('apps.timetrack.default_rate');
$db = Database::getInstance();
```

---
*K-Docs Applications - PHP natif, portable, leger*
