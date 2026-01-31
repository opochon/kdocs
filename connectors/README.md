# K-Docs Connecteurs

Connecteurs vers systemes externes (ERP, comptabilite, cloud).

## Principe

- Chaque connecteur est **isole** dans son dossier
- Communication via **classes PHP** (pas d'API externe)
- Configuration dans le dossier du connecteur

## Connecteurs

| Connecteur | Type | Statut | Description |
|------------|------|--------|-------------|
| [winbiz](./winbiz/) | FoxPro/ODBC | A faire | ERP suisse, compta |
| kdrive | WebDAV | Planifie | Infomaniak kDrive |
| sharepoint | Graph API | Planifie | Microsoft 365 |
| nextcloud | WebDAV | Planifie | Nextcloud/ownCloud |
| s3 | AWS SDK | Planifie | Amazon S3 / MinIO |

## Interface standard

```php
namespace KDocs\Connectors;

interface ConnectorInterface
{
    public function connect(): bool;
    public function disconnect(): void;
    public function isConnected(): bool;
    public function testConnection(): array;
}
```

## Connecteur WinBiz

Connexion a WinBiz via ODBC (base FoxPro).

### Prerequis
- WinBiz installe localement
- Driver ODBC Visual FoxPro (32-bit)
- PHP ODBC extension

### Configuration
```php
// connectors/winbiz/config.php
'odbc' => [
    'driver' => 'Microsoft Visual FoxPro Driver',
    'db_path' => 'C:\\WinBiz\\Data\\MACOMPAGNIE\\',
]
```

### Utilisation
```php
use KDocs\Connectors\WinBiz\WinBizConnector;

$connector = new WinBizConnector();

// Rechercher un article
$articles = $connector->searchArticles('vis M8');

// Lire un BL
$bl = $connector->getBonLivraison('BL-2025-0123');

// Tester la connexion
$result = $connector->testConnection();
```

## Creer un nouveau connecteur

1. Creer le dossier `connectors/my-connector/`
2. Implementer `ConnectorInterface`
3. Creer `config.php` et `README.md`
4. Documenter dans ce README

```php
namespace KDocs\Connectors\MyConnector;

class MyConnector implements ConnectorInterface
{
    // Implementer les methodes...
}
```

---
*K-Docs Connecteurs - Integration systemes externes*
