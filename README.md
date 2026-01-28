# K-Docs - Gestion Électronique de Documents

## Installation

### Prérequis
- PHP 8.3+
- MariaDB 11.5+ sur port 3307
- Composer
- Apache avec mod_rewrite

### Étapes d'installation

1. **Installer les dépendances Composer**
   ```bash
   composer install
   ```

2. **Créer la base de données**
   ```bash
   php database/install.php
   ```

3. **Vérifier la configuration**
   - Vérifier le fichier `config/config.php`
   - Port MariaDB : 3307 (pas 3306)
   - User BDD : root (mot de passe vide)

4. **Accéder à l'application**
   - URL : http://localhost/kdocs
   - Compte par défaut : username=`root`, password=(vide)

## Structure du projet

```
kdocs/
├── app/              # Code PHP
│   ├── Core/         # Classes fondamentales
│   ├── Models/       # Modèles BDD
│   ├── Controllers/  # Contrôleurs
│   ├── Services/     # Logique métier
│   └── Middleware/   # Middleware
├── config/           # Configuration
├── database/         # Schémas SQL
├── templates/        # Vues PHP
├── public/           # Assets statiques
├── storage/          # Fichiers uploadés
└── index.php         # Point d'entrée
```

## Développement

L'application utilise :
- **Backend** : PHP 8.3 + Slim Framework
- **Frontend** : PHP templates + Tailwind CSS (CDN)
- **BDD** : MariaDB avec PDO

## Statut

✅ **Phase 1 - Fondations** : Complétée
- Structure de base créée
- Base de données initialisée (18+ tables)
- Classes Core implémentées (Config, Database, App, Auth)

✅ **Phase 2 - Authentification** : Complétée
- Page de login (`/login`)
- Système d'authentification avec sessions
- Middleware de protection des routes
- Dashboard (`/dashboard`)
- Gestion des utilisateurs

✅ **Phase 3 - Gestion de Documents** : Complétée
- CRUD complet des documents
- Upload et traitement automatique
- OCR avec Tesseract (fallback pdftotext)
- Génération de miniatures
- Extraction de métadonnées
- Vue grille/liste/tableau
- Recherche simple et avancée
- Filtrage par dossier, correspondant, tag, type
- Partage et historique

✅ **Phase 4 - Consume Folder** : Complétée
- Scan automatique du dossier `storage/consume/`
- Classification automatique (3 modes : rules, ai, auto)
- Champs de classification configurables
- Validation manuelle des documents
- Génération de chemins de stockage dynamiques
- Découpage intelligent de PDFs multi-pages (IA)

✅ **Phase 5 - Workflows** : Complétée
- Designer visuel de workflows
- 14 types de nodes (Triggers, Processing, Conditions, Actions, Waits, Timers)
- Exécution automatique des workflows
- Système d'approbation
- Timers avec cron job

✅ **Phase 6 - IA/Claude** : Complétée
- Classification intelligente des documents
- Recherche en langage naturel
- Chat IA intégré
- Extraction de données avec prompts personnalisés

✅ **Phase 7 - Administration** : Complétée
- 18 pages d'administration
- Gestion des correspondants, tags, types de documents
- Champs personnalisés et de classification
- Chemins de stockage
- Workflows et webhooks
- Utilisateurs et permissions
- Statistiques API

**État général** : **95% fonctionnel**, architecture moderne, prêt pour production

## Tests

K-Docs utilise PHPUnit 10 pour les tests automatisés.

### Exécuter les tests

```bash
# Tous les tests
php vendor/bin/phpunit

# Tests avec détails
php vendor/bin/phpunit --testdox

# Tests unitaires uniquement
php vendor/bin/phpunit --testsuite Unit

# Tests Feature/API
php vendor/bin/phpunit --testsuite Feature
```

### Structure des tests

```
tests/
├── bootstrap.php           # Configuration tests
├── TestCase.php            # Classe de base
├── Unit/                   # Tests unitaires
│   ├── Core/               # Tests classes Core (CSRF, Validator)
│   └── Services/           # Tests Services
└── Feature/                # Tests d'intégration API
    ├── ApiTestCase.php     # Base pour tests API
    └── *ApiTest.php        # Tests endpoints
```

## API REST

K-Docs expose une API REST complète. Voir [docs/API.md](docs/API.md) pour la documentation détaillée.

### Endpoints principaux

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/documents` | GET | Liste des documents |
| `/api/documents/{id}` | GET | Détails document |
| `/api/documents` | POST | Créer document |
| `/api/documents/{id}` | PUT | Modifier document |
| `/api/documents/{id}` | DELETE | Supprimer document |
| `/api/search` | GET | Recherche documents |
| `/api/validation/pending` | GET | Documents à valider |
| `/api/validation/{id}/status` | POST | Définir statut validation |
| `/api/notifications` | GET | Notifications utilisateur |

## Configuration

### Variables d'environnement

Éditer `config/config.php` :

```php
'database' => [
    'host' => 'localhost',
    'port' => 3307,           // MariaDB port
    'name' => 'kdocs',
    'user' => 'root',
    'password' => '',
    'charset' => 'utf8mb4'
],
'claude' => [
    'api_key' => 'sk-ant-...'  // Clé API Claude (optionnel)
],
'ocr' => [
    'engine' => 'tesseract',   // ou 'pdftotext'
    'language' => 'fra+eng'
]
```

### Dossier Consume

Les documents placés dans `storage/consume/` sont automatiquement traités :
1. OCR et extraction de texte
2. Classification automatique (IA si configurée)
3. Déplacement vers le bon dossier

## Sécurité

- **CSRF** : Protection automatique sur tous les formulaires
- **Validation** : Validation centralisée des entrées utilisateur
- **Rate Limiting** : 100 requêtes/minute par IP sur l'API
- **Authentification** : Sessions PHP sécurisées

## Contribution

1. Fork le projet
2. Créer une branche feature (`git checkout -b feature/ma-feature`)
3. Exécuter les tests (`php vendor/bin/phpunit`)
4. Commit (`git commit -am 'Add feature'`)
5. Push (`git push origin feature/ma-feature`)
6. Créer une Pull Request

## Licence

Propriétaire - Usage interne uniquement
