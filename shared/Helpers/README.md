# Shared Helpers

Fonctions utilitaires partagees entre K-Docs et les applications.

## Fonctions disponibles

### Chemins

```php
app_path('timetrack', 'Controllers/EntryController.php');
// => /path/to/kdocs/apps/timetrack/Controllers/EntryController.php

shared_path('Auth/AuthInterface.php');
// => /path/to/kdocs/shared/Auth/AuthInterface.php

storage_path('apps/mail/cache.sqlite');
// => /path/to/kdocs/storage/apps/mail/cache.sqlite
```

### Formatage duree

```php
format_duration(2.5);    // => "2:30"
format_duration(1.75);   // => "1:45"

parse_duration('2.5h');  // => 2.5
parse_duration('2h30');  // => 2.5
parse_duration('2:30');  // => 2.5
```

### Formatage argent

```php
format_money(1234.50);           // => "1'234.50 CHF"
format_money(1234.50, 'EUR');    // => "1'234.50 EUR"
```

### Utilitaires

```php
slugify('Facture Client #123');  // => "facture-client-123"

is_kdocs_available();            // => true si K-Docs Core present
```

## Utilisation

```php
require_once __DIR__ . '/../../shared/Helpers/functions.php';

$duration = parse_duration($_POST['time']);
$formatted = format_duration($duration);
```
