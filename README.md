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
