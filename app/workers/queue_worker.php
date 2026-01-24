<?php
/**
 * K-Docs - Worker unifié pour les queues
 * 
 * Traite les jobs de tous les pipelines :
 * - indexing_high (priorité haute)
 * - indexing (priorité normale)
 * - ocr
 * - thumbnails
 * 
 * Utilise n0nag0n/simple-job-queue
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../autoload.php';

use n0nag0n\Job_Queue;
use KDocs\Core\Database;
use KDocs\Services\IndexingService;
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

echo "[QueueWorker] Démarré - PID: " . getmypid() . "\n";
echo "[QueueWorker] Pipelines: " . implode(', ', $config['pipelines']) . "\n";

while ($jobsProcessed < $config['max_jobs']) {
    $jobFound = false;
    
    // Vérifier chaque pipeline par priorité
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
                        if (empty($path)) {
                            throw new \Exception("Chemin manquant dans le payload");
                        }
                        
                        // Créer le fichier .indexing pour indiquer la progression
                        $indexing->writeIndexingProgress($path, 0, 0, 'starting');
                        
                        // Indexer le dossier
                        $stats = $indexing->indexFolder($path);
                        
                        // Supprimer le fichier .indexing
                        $indexing->removeIndexing($path);
                        
                        echo "[QueueWorker] Indexation terminée: " . json_encode($stats) . "\n";
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
            
        } catch (\Exception $e) {
            error_log("[QueueWorker] Erreur pipeline $pipeline: " . $e->getMessage());
        }
    }
    
    // Pause si aucun job trouvé
    if (!$jobFound) {
        usleep($config['sleep_time']);
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
