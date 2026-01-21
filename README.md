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

✅ Phase 1 - Fondations : Complétée
- Structure de base créée
- Base de données initialisée (18 tables)
- Classes Core implémentées (Config, Database, App, Auth)

🔄 Phase 2 - Authentification : À venir
- Page de login
- Dashboard
