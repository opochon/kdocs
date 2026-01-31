# K-Docs CLI & Binaires

Scripts executables, outils en ligne de commande et binaires natifs.

## Binaires natifs (PAS Docker)

| Binaire | Usage | Telechargement |
|---------|-------|----------------|
| qdrant.exe | Base vectorielle | [GitHub Qdrant](https://github.com/qdrant/qdrant/releases) |
| ollama.exe | LLM local (optionnel) | [Ollama.ai](https://ollama.ai) |

### Installation Qdrant

```bash
# Windows - telecharger et extraire
curl -LO https://github.com/qdrant/qdrant/releases/latest/download/qdrant-x86_64-pc-windows-msvc.zip
unzip qdrant-*.zip -d bin/

# Lancer
bin/qdrant.exe --config-path config/qdrant.yaml

# Ou comme service Windows
sc create Qdrant binPath= "C:\wamp64\www\kdocs\bin\qdrant.exe" start= auto
```

---

## Structure

```
bin/
├── kdocs              # CLI principal (PHP)
├── services/          # Scripts de gestion des services
│   ├── start.bat      # Démarrer tous les services
│   ├── stop.bat       # Arrêter tous les services
│   └── status.bat     # État des services
├── maintenance/       # Scripts de maintenance
│   ├── cleanup.php    # Nettoyage fichiers temporaires
│   ├── reindex.php    # Réindexation complète
│   └── backup.php     # Sauvegarde base + fichiers
└── tools/             # Outils divers
    ├── migrate.php    # Migrations base de données
    └── seed.php       # Données de test
```

## CLI Principal

```bash
# Aide générale
php bin/kdocs help

# Commandes disponibles
php bin/kdocs index:run          # Lancer l'indexation
php bin/kdocs ocr:process <id>   # OCR sur un document
php bin/kdocs search <query>     # Rechercher des documents
php bin/kdocs user:create        # Créer un utilisateur
php bin/kdocs cache:clear        # Vider le cache
php bin/kdocs queue:work         # Traiter la queue

# Exemples
php bin/kdocs index:run --folder=/Documents/2024
php bin/kdocs search "facture électricité" --limit=10
php bin/kdocs user:create admin --admin --password=secret
```

## Scripts de services

### Windows (.bat)

```batch
:: Démarrer les services
bin\services\start.bat

:: Arrêter les services
bin\services\stop.bat

:: Vérifier l'état
bin\services\status.bat
```

### PowerShell (.ps1)

```powershell
# Démarrer avec plus d'options
.\bin\services\start.ps1 -Services qdrant,ollama

# État détaillé
.\bin\services\status.ps1 -Verbose
```

## Scripts de maintenance

```bash
# Nettoyage des fichiers temporaires (> 7 jours)
php bin/maintenance/cleanup.php --days=7

# Réindexation complète
php bin/maintenance/reindex.php --force

# Réindexation d'un dossier
php bin/maintenance/reindex.php --folder=/Documents/Archive

# Sauvegarde
php bin/maintenance/backup.php --output=/backups/kdocs-2026-01-30.tar.gz
```

## Migrations

```bash
# Voir les migrations en attente
php bin/tools/migrate.php status

# Exécuter les migrations
php bin/tools/migrate.php up

# Rollback dernière migration
php bin/tools/migrate.php down

# Créer une nouvelle migration
php bin/tools/migrate.php create add_custom_field
```

## Cron recommandé

```cron
# Indexation toutes les 5 minutes
*/5 * * * * php /path/to/kdocs/bin/kdocs index:run --quiet

# Queue worker
* * * * * php /path/to/kdocs/bin/kdocs queue:work --once

# Nettoyage quotidien
0 3 * * * php /path/to/kdocs/bin/maintenance/cleanup.php

# Backup hebdomadaire
0 2 * * 0 php /path/to/kdocs/bin/maintenance/backup.php
```

## Créer un nouveau script

```php
#!/usr/bin/env php
<?php
// bin/tools/my-script.php

require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;

// Votre code ici...
echo "Script exécuté!\n";
```

Rendre exécutable (Linux/Mac):
```bash
chmod +x bin/tools/my-script.php
```
