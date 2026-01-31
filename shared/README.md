# K-Docs Shared

Code partage entre la GED et les applications.

## Modules

| Module | Description | Statut |
|--------|-------------|--------|
| [Auth/](./Auth/) | Authentification unifiee | Implemente |
| [ApiClient/](./ApiClient/) | Client API interne K-Docs | Implemente |
| [UI/](./UI/) | Composants UI reutilisables | A faire |
| [Helpers/](./Helpers/) | Fonctions utilitaires | Implemente |

## Auth - Authentification unifiee

Permet aux apps d'utiliser l'auth K-Docs ou leur propre systeme.

```php
use KDocs\Shared\Auth\KDocsAuthAdapter;

$auth = new KDocsAuthAdapter();

if ($auth->check()) {
    $user = $auth->user();
    echo "Connecte: " . $user['username'];
}

if ($auth->hasRole('admin')) {
    // Actions admin
}
```

## ApiClient - Client API K-Docs

Interagir avec la GED depuis les applications.

```php
use KDocs\Shared\ApiClient\KDocsClient;

$client = new KDocsClient();

if ($client->isAvailable()) {
    $docs = $client->searchDocuments('facture');
    $doc = $client->getDocument(123);
    $result = $client->uploadDocument('/path/to/file.pdf', [
        'title' => 'Facture 2025-001'
    ]);
}
```

## Helpers - Fonctions utilitaires

```php
require_once __DIR__ . '/shared/Helpers/functions.php';

// Chemins
app_path('timetrack', 'Controllers/EntryController.php');
shared_path('Auth/AuthInterface.php');
storage_path('apps/mail/cache.sqlite');

// Durees
format_duration(2.5);    // "2:30"
parse_duration('2h30');  // 2.5

// Argent
format_money(1234.50);   // "1'234.50 CHF"

// Utilitaires
slugify('Facture Client');  // "facture-client"
is_kdocs_available();       // true/false
```

## UI - Composants (a venir)

Composants PHP pour generer du HTML avec Tailwind CSS.

```php
use KDocs\Shared\UI\Components\Button;
use KDocs\Shared\UI\Components\Modal;

echo Button::primary('Sauvegarder');
echo Modal::open('modal-edit', 'Modifier');
```

## Utilisation

Dans une app ou un connecteur :

```php
// Autoload
require_once __DIR__ . '/../../vendor/autoload.php';

// Ou chargement direct
require_once __DIR__ . '/../../shared/Helpers/functions.php';

use KDocs\Shared\Auth\KDocsAuthAdapter;
use KDocs\Shared\ApiClient\KDocsClient;

$auth = new KDocsAuthAdapter();
$client = new KDocsClient();
```

## Structure

```
shared/
├── Auth/
│   ├── AuthInterface.php      # Interface
│   ├── KDocsAuthAdapter.php   # Adaptateur K-Docs
│   └── README.md
├── ApiClient/
│   ├── KDocsClient.php        # Client HTTP
│   └── README.md
├── UI/
│   └── README.md              # A implementer
├── Helpers/
│   ├── functions.php          # Fonctions globales
│   └── README.md
└── README.md
```

---
*K-Docs Shared - Code partage entre apps*
