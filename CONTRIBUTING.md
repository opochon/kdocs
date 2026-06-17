# Contributing to K-Docs

## Prérequis

- PHP 8.1+
- MySQL 8.0+
- Composer
- Git

## Installation développement

```bash
git clone <repo>
cd kdocs
composer install
cp config/config.example.php config/config.php
# Éditer config.php avec vos paramètres
```

## Structure du projet

```
kdocs/
├── app/                    # Code principal (PSR-4: KDocs\)
│   ├── Controllers/        # Contrôleurs HTTP
│   ├── Services/           # Logique métier
│   ├── Repositories/       # Accès données
│   ├── Models/             # Modèles
│   └── Core/               # Framework core
├── config/                 # Configuration
├── proofofconcept/         # POC validé
├── storage/                # Fichiers utilisateur
├── templates/              # Vues PHP
├── tests/                  # Tests automatisés
└── docs/pilotage/          # Documentation technique
```

## Workflow de développement

### 1. Créer une branche

```bash
git checkout -b feature/ma-feature
# ou
git checkout -b fix/mon-fix
```

### 2. Développer

Respecter les conventions :
- PSR-12 pour le code PHP
- UTF-8 partout
- Requêtes SQL préparées
- Pas de credentials dans le code

### 3. Tester

```bash
# Tests rapides (obligatoire avant commit)
composer test:smoke

# Tous les tests
composer test

# Analyse statique
composer phpstan

# Vérifier le style
composer cs-check
```

### 4. Commit

Utiliser les [Conventional Commits](https://www.conventionalcommits.org/) :

```bash
git commit -m "feat(documents): add bulk delete action"
git commit -m "fix(upload): handle special characters in filename"
git commit -m "test(api): add search endpoint tests"
```

Types autorisés :
- `feat` : nouvelle fonctionnalité
- `fix` : correction de bug
- `docs` : documentation
- `style` : formatage (pas de changement de code)
- `refactor` : refactoring
- `test` : ajout/modification de tests
- `chore` : maintenance, dépendances

### 5. Pre-commit hooks

Les hooks sont automatiquement exécutés avant chaque commit :
- Smoke tests
- Syntax check PHP
- PHPStan (warning only)

Si les tests échouent, le commit est bloqué.

### 6. Pull Request

- Titre clair décrivant le changement
- Description des modifications
- Tests ajoutés/modifiés
- Screenshots si UI change

## Tests

### Types de tests

| Suite | Commande | Durée | Quand |
|-------|----------|-------|-------|
| Smoke | `composer test:smoke` | ~30s | Avant chaque commit |
| API | `composer test:api` | ~1min | Avant PR |
| Integration | `composer test:integration` | ~3min | Avant release |
| Unit | `composer test:unit` | ~1min | CI |
| POC | `composer test:poc` | ~2min | Après modif POC |

### Ajouter un test

```php
// tests/Unit/Services/MonServiceTest.php
namespace KDocs\Tests\Unit\Services;

use KDocs\Tests\TestCase;
use KDocs\Services\MonService;

class MonServiceTest extends TestCase
{
    public function testMaMethode(): void
    {
        $service = new MonService();
        $result = $service->maMethode();
        
        $this->assertIsArray($result);
    }
}
```

## Base de données

### Migrations

Les migrations sont dans `database/migrations/`. 

Pour ajouter une migration :
```bash
# Créer le fichier
touch database/migrations/2026_02_04_add_ma_table.sql
```

### Conventions SQL

```sql
-- Tables en snake_case
CREATE TABLE document_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Clé étrangère
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
);
```

## Documentation

- **PILOTAGE.md** : Gouvernance technique, état du projet
- **CHANGELOG.md** : Historique des versions
- **README.md** : Introduction et installation

Mettre à jour PILOTAGE.md après chaque fonctionnalité majeure.

## Release

1. Mettre à jour CHANGELOG.md
2. Bump version dans composer.json
3. Tag Git : `git tag -a v1.x.x -m "Release 1.x.x"`
4. Push : `git push origin v1.x.x`

## Support

- Issues GitHub pour les bugs
- Discussions GitHub pour les questions
- PR pour les contributions
