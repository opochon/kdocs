# K-Docs - Système de Queue avec n0nag0n/simple-job-queue

## 📋 Résumé

Migration vers une solution de queue professionnelle utilisant `n0nag0n/simple-job-queue` au lieu de fichiers JSON custom.

## 🎯 Avantages

| Aspect | Ancien système (fichiers JSON) | Nouveau système (bibliothèque) |
|--------|-------------------------------|--------------------------------|
| Gestion queues | Fichiers JSON dans `crawl_queue/` | Table SQL `job_queue_jobs` |
| Retry/Backoff | Manuel | Intégré (attempts) |
| Concurrence | Risque de doublons | Géré (reserve/delete) |
| Monitoring | Aucun | Possible via table SQL |
| Maintenance | À réinventer | Communauté + tests |

## 📦 Installation

### 1. Installer la bibliothèque

```bash
composer require n0nag0n/simple-job-queue
```

### 2. Créer la table SQL

```bash
php database/migrations/create_job_queue_table.php
```

Ou manuellement :

```sql
CREATE TABLE IF NOT EXISTS job_queue_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pipeline VARCHAR(255) NOT NULL DEFAULT 'default',
    payload LONGBLOB NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reserved_at INT UNSIGNED NULL,
    available_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX idx_pipeline_available (pipeline, available_at),
    INDEX idx_reserved (reserved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 🚀 Utilisation

### Ajouter un job d'indexation

```php
use KDocs\Services\QueueService;

// Priorité normale
QueueService::queueIndexing('2024/tribunal');

// Priorité haute
QueueService::queueIndexing('2024/tribunal', 'high');
```

### Ajouter un job OCR

```php
QueueService::queueOCR($documentId);
```

### Ajouter un job thumbnail

```php
QueueService::queueThumbnail($documentId);
```

## 👷 Worker

### Démarrer le worker

#### Windows (développement)

```batch
# Double-cliquer sur :
app/workers/start_worker.bat

# Ou depuis la ligne de commande :
php app/workers/queue_worker.php
```

#### Linux/Mac (production)

```bash
# Directement
php app/workers/queue_worker.php

# Avec supervisor (recommandé)
supervisorctl start kdocs-worker
```

### Configuration supervisor (Linux)

Créer `/etc/supervisor/conf.d/kdocs-worker.conf` :

```ini
[program:kdocs-worker]
command=php /var/www/kdocs/app/workers/queue_worker.php
directory=/var/www/kdocs
user=www-data
numprocs=2
autostart=true
autorestart=true
startsecs=10
stopwaitsecs=60
stdout_logfile=/var/log/kdocs/worker.log
stderr_logfile=/var/log/kdocs/worker-error.log
```

Puis :

```bash
supervisorctl reread
supervisorctl update
supervisorctl start kdocs-worker
```

## 📊 Pipelines

Le système utilise plusieurs pipelines pour organiser les jobs :

- **`indexing_high`** : Indexation prioritaire (traité en premier)
- **`indexing`** : Indexation normale
- **`ocr`** : Traitement OCR
- **`thumbnails`** : Génération de miniatures

## 🔍 Monitoring

### Compter les jobs en attente

```php
$pending = QueueService::countPendingJobs('indexing');
echo "Jobs en attente: $pending\n";
```

### Compter les jobs actifs

```php
$active = QueueService::countActiveJobs('indexing');
echo "Jobs actifs: $active\n";
```

### Vérifier si un job existe pour un chemin

```php
if (QueueService::hasJobForPath('2024/tribunal')) {
    echo "Un job existe déjà pour ce chemin\n";
}
```

### Requête SQL directe

```sql
-- Jobs en attente
SELECT COUNT(*) FROM job_queue_jobs 
WHERE pipeline = 'indexing' 
AND reserved_at IS NULL 
AND available_at <= UNIX_TIMESTAMP();

-- Jobs actifs
SELECT COUNT(*) FROM job_queue_jobs 
WHERE pipeline = 'indexing' 
AND reserved_at IS NOT NULL;

-- Derniers jobs traités
SELECT id, pipeline, payload, attempts, created_at 
FROM job_queue_jobs 
ORDER BY created_at DESC 
LIMIT 10;
```

## 🔄 Migration depuis l'ancien système

### Ancien système (fichiers JSON)

Les fichiers dans `storage/crawl_queue/` peuvent être migrés manuellement si nécessaire, mais le nouveau système fonctionne indépendamment.

### Compatibilité

Le code vérifie si `QueueService` est disponible avant de l'utiliser :

```php
if (class_exists('\KDocs\Services\QueueService')) {
    QueueService::queueIndexing($path);
} else {
    // Fallback vers l'ancien système si nécessaire
}
```

## 📝 Fichiers créés/modifiés

### Nouveaux fichiers

1. **`app/Services/QueueService.php`**
   - Encapsule l'utilisation de `n0nag0n/simple-job-queue`
   - Méthodes pour ajouter des jobs (indexing, OCR, thumbnails)
   - Méthodes de monitoring

2. **`app/workers/queue_worker.php`**
   - Worker unifié pour tous les pipelines
   - Traite les jobs par priorité
   - Gestion des erreurs avec retry (max 3 tentatives)

3. **`database/migrations/create_job_queue_table.php`**
   - Migration pour créer la table `job_queue_jobs`

4. **`app/workers/start_worker.bat`**
   - Script batch pour démarrer le worker sur Windows

### Fichiers modifiés

1. **`app/Services/IndexingService.php`**
   - Simplifié pour utiliser `QueueService`
   - Méthode `indexFolder()` simplifiée
   - Suppression de la gestion custom des queues

2. **`app/Controllers/Api/FoldersApiController.php`**
   - Utilise `QueueService` au lieu de `IndexingService::addQueue()`
   - Vérification du nombre de jobs actifs

## 🐛 Dépannage

### Worker ne démarre pas

1. Vérifier que la bibliothèque est installée :
   ```bash
   composer show n0nag0n/simple-job-queue
   ```

2. Vérifier que la table existe :
   ```sql
   SHOW TABLES LIKE 'job_queue_jobs';
   ```

3. Vérifier les logs PHP :
   ```bash
   tail -f /var/log/php/error.log
   ```

### Jobs bloqués

Les jobs réservés depuis plus de 5 minutes peuvent être considérés comme bloqués. Pour les libérer :

```sql
-- Libérer les jobs bloqués (réservés depuis plus de 5 min)
UPDATE job_queue_jobs 
SET reserved_at = NULL, attempts = attempts + 1
WHERE reserved_at IS NOT NULL 
AND reserved_at < UNIX_TIMESTAMP() - 300;
```

### Performance

Si le worker est trop lent :

1. Augmenter `numprocs` dans supervisor (plus de workers)
2. Réduire `sleep_time` dans `queue_worker.php`
3. Vérifier les index SQL sur `job_queue_jobs`

## 📚 Références

- [n0nag0n/simple-job-queue sur GitHub](https://github.com/n0nag0n/simple-job-queue)
- [Documentation FlightPHP - Simple Job Queue](https://docs.flightphp.com/awesome-plugins/simple_job_queue)
- `docs/REFONTE_INDEXATION.md` : Documentation de la refonte d'indexation
