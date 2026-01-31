<?php
/**
 * K-Docs - Worker unifié pour les queues
 * 
 * Traite les jobs de DEUX sources :
 * 1. Table job_queue_jobs (nouveau système via n0nag0n/simple-job-queue)
 * 2. Fichiers JSON dans storage/crawl_queue (ancien système - pour compatibilité)
 * 
 * Pipelines supportés :
 * - indexing_high (priorité haute)
 * - indexing (priorité normale)
 * - ocr
 * - thumbnails
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use n0nag0n\Job_Queue;
use KDocs\Core\Database;
use KDocs\Services\IndexingService;
use KDocs\Jobs\EmbedDocumentJob;
use KDocs\Core\Config;

// Configuration
$config = [
    'memory_limit' => '256M',
    'max_jobs' => 100,        // Arrêter après X jobs (pour éviter memory leaks)
    'sleep_time' => 500000,   // 500ms entre chaque vérification
    'pipelines' => ['indexing_high', 'indexing', 'ocr', 'thumbnails']
];

ini_set('memory_limit', $config['memory_limit']);

// Connexion à la queue
try {
    $pdo = Database::getInstance();
    $queue = new Job_Queue('mysql', [
        'mysql' => [
            'table_name' => 'job_queue_jobs',
            'use_compression' => true
        ]
    ]);
    $queue->addQueueConnection($pdo);
} catch (\Exception $e) {
    error_log("[QueueWorker] Erreur initialisation: " . $e->getMessage());
    exit(1);
}

// Services
$indexing = new IndexingService();

$jobsProcessed = 0;
$startTime = time();
$lockFile = __DIR__ . '/../../storage/queue_worker.lock';
$lastLockUpdate = 0;

// Créer le fichier lock initial
file_put_contents($lockFile, json_encode([
    'pid' => getmypid(),
    'started' => date('c'),
    'jobs' => 0
]));

// Nettoyer le lock à la fin du script
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

echo "[QueueWorker] Démarré - PID: " . getmypid() . "\n";
echo "[QueueWorker] Pipelines: " . implode(', ', $config['pipelines']) . "\n";

// Fonction helper pour traiter un job d'indexation
function processIndexingJob($indexing, $path, $jobId = 'file') {
    if (empty($path) && $path !== '') {
        throw new \Exception("Chemin manquant dans le payload");
    }
    
    // Créer le fichier .indexing pour indiquer la progression
    $indexing->writeIndexingProgress($path, 0, 0, 'starting');
    
    // Indexer le dossier
    $stats = $indexing->indexFolder($path);
    
    // Supprimer le fichier .indexing
    $indexing->removeIndexing($path);
    
    echo "[QueueWorker] Indexation terminée (job $jobId): " . json_encode($stats) . "\n";
    return $stats;
}

while ($jobsProcessed < $config['max_jobs']) {
    $jobFound = false;
    
    // =====================================================
    // 1. Traiter les fichiers JSON (ancien système - priorité)
    // =====================================================
    $crawlDir = __DIR__ . '/../../storage/crawl_queue';
    if (is_dir($crawlDir)) {
        $taskFiles = glob($crawlDir . '/crawl_*.json');
        
        // Trier par priorité et date
        if (!empty($taskFiles)) {
            usort($taskFiles, function($a, $b) {
                $taskA = json_decode(@file_get_contents($a), true) ?: [];
                $taskB = json_decode(@file_get_contents($b), true) ?: [];
                
                // Priorité haute d'abord
                if (($taskA['priority'] ?? 'normal') === 'high' && ($taskB['priority'] ?? 'normal') !== 'high') {
                    return -1;
                }
                if (($taskB['priority'] ?? 'normal') === 'high' && ($taskA['priority'] ?? 'normal') !== 'high') {
                    return 1;
                }
                
                // Plus ancien d'abord
                return ($taskA['created_at'] ?? 0) <=> ($taskB['created_at'] ?? 0);
            });
            
            // Traiter le premier fichier
            $taskFile = $taskFiles[0];
            $task = json_decode(@file_get_contents($taskFile), true);
            
            if ($task && isset($task['path'])) {
                $jobFound = true;
                $path = $task['path'];
                
                echo "[QueueWorker] Job fichier: $taskFile - Path: $path\n";
                
                try {
                    processIndexingJob($indexing, $path, basename($taskFile));
                    
                    // Supprimer le fichier de tâche
                    @unlink($taskFile);
                    echo "[QueueWorker] Job fichier terminé avec succès\n";
                    
                } catch (\Exception $e) {
                    echo "[QueueWorker] Erreur traitement fichier: " . $e->getMessage() . "\n";
                    // Supprimer quand même pour éviter boucle infinie
                    @unlink($taskFile);
                }
                
                $jobsProcessed++;
            }
        }
    }
    
    // =====================================================
    // 2. Traiter les jobs de la table DB (nouveau système)
    // =====================================================
    if (!$jobFound) {
        foreach ($config['pipelines'] as $pipeline) {
            try {
                $queue->watchPipeline($pipeline);
                $job = $queue->getNextJobAndReserve();
                
                if (empty($job)) {
                    continue;
                }
                
                $jobFound = true;
                $payload = json_decode($job['payload'], true);
                $type = $payload['type'] ?? 'unknown';
                
                echo "[QueueWorker] Job #{$job['id']} - Pipeline: $pipeline - Type: $type\n";
                
                try {
                    switch ($type) {
                        case 'index_folder':
                            $path = $payload['path'] ?? '';
                            processIndexingJob($indexing, $path, $job['id']);
                            break;
                            
                        case 'ocr_document':
                            $docId = $payload['document_id'] ?? 0;
                            if ($docId > 0) {
                                // TODO: Implémenter OCRService::process($docId)
                                echo "[QueueWorker] OCR pour document #$docId (non implémenté)\n";
                            }
                            break;
                            
                        case 'generate_thumbnail':
                            $docId = $payload['document_id'] ?? 0;
                            if ($docId > 0) {
                                // TODO: Implémenter ThumbnailGenerator::generateForDocument($docId)
                                echo "[QueueWorker] Thumbnail pour document #$docId (non implémenté)\n";
                            }
                            break;
                            
                        default:
                            echo "[QueueWorker] Type inconnu: $type\n";
                    }
                    
                    // Job terminé avec succès
                    $queue->deleteJob($job);
                    echo "[QueueWorker] Job #{$job['id']} terminé avec succès\n";
                    
                } catch (\Exception $e) {
                    echo "[QueueWorker] Erreur traitement job #{$job['id']}: " . $e->getMessage() . "\n";
                    
                    // Réessayer max 3 fois, sinon enterrer
                    $attempts = $job['attempts'] ?? 0;
                    if ($attempts < 3) {
                        $queue->buryJob($job);
                        echo "[QueueWorker] Job #{$job['id']} enterré (tentative " . ($attempts + 1) . "/3)\n";
                    } else {
                        $queue->deleteJob($job);
                        echo "[QueueWorker] Job #{$job['id']} abandonné après 3 tentatives\n";
                    }
                }
                
                $jobsProcessed++;
                break; // Sortir de la boucle des pipelines après avoir traité un job
                
            } catch (\Exception $e) {
                error_log("[QueueWorker] Erreur pipeline $pipeline: " . $e->getMessage());
            }
        }
    }
    
    // =====================================================
    // 3. Process embedding jobs
    // =====================================================
    if (!$jobFound) {
        try {
            $embeddingResult = EmbedDocumentJob::processPending(5);
            if ($embeddingResult['total'] > 0) {
                $jobFound = true;
                $jobsProcessed += $embeddingResult['processed'];
                echo "[QueueWorker] Embeddings: processed={$embeddingResult['processed']}, failed={$embeddingResult['failed']}\n";
            }
        } catch (\Exception $e) {
            error_log("[QueueWorker] Embedding processing error: " . $e->getMessage());
        }
    }

    // Pause si aucun job trouvé
    if (!$jobFound) {
        usleep($config['sleep_time']);
    }

    // Mettre à jour le fichier lock toutes les 30 secondes
    if (time() - $lastLockUpdate > 30) {
        @touch($lockFile);
        $lastLockUpdate = time();
    }

    // Vérifier le temps d'exécution (arrêter après 1 heure pour éviter les memory leaks)
    if (time() - $startTime > 3600) {
        echo "[QueueWorker] Arrêt après 1 heure d'exécution\n";
        break;
    }
}

$duration = time() - $startTime;
echo "[QueueWorker] Arrêté après $jobsProcessed jobs en {$duration}s\n";
exit(0);
