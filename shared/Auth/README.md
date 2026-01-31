# Shared Auth

Module d'authentification partage entre K-Docs et les applications.

## Interface

`AuthInterface` definit le contrat d'authentification :

```php
interface AuthInterface
{
    public function check(): bool;
    public function user(): ?array;
    public function id(): ?int;
    public function hasRole(string $role): bool;
    public function can(string $permission): bool;
    public function login(string $username, string $password): bool;
    public function logout(): void;
}
```

## Adaptateurs

| Adaptateur | Usage |
|------------|-------|
| `KDocsAuthAdapter` | Utilise l'auth K-Docs (sessions PHP) |
| `StandaloneAuthAdapter` | Auth independante (pour apps standalone) |

## Utilisation dans une app

```php
use KDocs\Shared\Auth\KDocsAuthAdapter;

$auth = new KDocsAuthAdapter();

if ($auth->check()) {
    $user = $auth->user();
    echo "Connecte en tant que " . $user['username'];
}

if ($auth->hasRole('admin')) {
    // Actions admin
}
```

## Detection automatique

Les apps peuvent detecter si K-Docs est present :

```php
function getAuthAdapter(): AuthInterface
{
    if (class_exists('KDocs\\Core\\Auth')) {
        return new KDocsAuthAdapter();
    }
    return new StandaloneAuthAdapter();
}
```
