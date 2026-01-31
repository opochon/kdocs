# Shared API Client

Client pour interagir avec l'API K-Docs depuis les applications.

## Utilisation

```php
use KDocs\Shared\ApiClient\KDocsClient;

$client = new KDocsClient();

// Verifier si K-Docs est accessible
if ($client->isAvailable()) {
    // Rechercher des documents
    $docs = $client->searchDocuments('facture', [
        'correspondent' => 'Dupont SA'
    ]);

    // Recuperer un document
    $doc = $client->getDocument(123);

    // Uploader un document
    $result = $client->uploadDocument('/path/to/file.pdf', [
        'title' => 'Facture 2025-001',
        'tags' => ['facture', 'urgent']
    ]);
}
```

## Mode integre vs standalone

En mode integre (K-Docs present) :
- L'URL est detectee automatiquement
- L'authentification utilise la session courante

En mode standalone :
- Specifier l'URL et l'API key manuellement

```php
$client = new KDocsClient(
    'https://kdocs.example.com',
    'api-key-here'
);
```

## Methodes disponibles

| Methode | Description |
|---------|-------------|
| `isAvailable()` | Verifie si K-Docs repond |
| `searchDocuments($query, $filters)` | Recherche documents |
| `getDocument($id)` | Recupere un document |
| `uploadDocument($path, $metadata)` | Upload un fichier |
| `getCorrespondents()` | Liste correspondants |
| `getTags()` | Liste tags |
