# Connecteur WinBiz

Connexion a WinBiz via ODBC (base FoxPro).

## Prerequis

- WinBiz installe localement
- Driver ODBC Visual FoxPro (32-bit)
- PHP ODBC extension

## Configuration

```php
// config/connectors.php
'winbiz' => [
    'enabled' => true,
    'db_path' => 'C:\\WinBiz\\Data\\MACOMPAGNIE\\',
    'read_only' => false,
]
```

## Structure

```
winbiz/
├── WinBizConnector.php     # Classe principale
├── Models/
│   ├── Article.php         # Stock articles
│   ├── Client.php          # Clients WinBiz
│   ├── BonLivraison.php    # Bons de livraison
│   └── FicheTravail.php    # Fiches de travail
├── config.php
└── README.md
```

## Utilisation

```php
use KDocs\Connectors\WinBiz\WinBizConnector;

$connector = new WinBizConnector();

// Rechercher un article
$articles = $connector->searchArticles('vis M8');

// Lire un BL
$bl = $connector->getBonLivraison('BL-2025-0123');

// Rechercher factures fournisseur
$factures = $connector->getFacturesFournisseur('Dupont SA');
```

## Tables WinBiz accessibles

| Table | Description |
|-------|-------------|
| ARTICLE | Stock articles |
| CLIENT | Clients |
| FOURN | Fournisseurs |
| FACTURE | Factures clients |
| FACTFOURN | Factures fournisseurs |
| BL | Bons de livraison |
| FICHETRAV | Fiches de travail |

## Limitations

- Acces en lecture seule recommande
- Driver ODBC 32-bit uniquement
- Performance limitee sur grosses tables

## Statut

**A faire** - Phase de conception

---
*Connecteur WinBiz - K-Docs*
