<?php
/**
 * K-Docs - Service TaskService
 * Gestion des tâches planifiées et de la file d'attente
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\ScheduledTask;

class TaskService
{
    /**
     * Ajoute une tâche à la file d'attente
     */
    public static function queue(string $taskType, array $taskData = [], int $priority = 5): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO task_queue (task_type, task_data, priority, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$taskType, json_encode($taskData), $priority]);
        return (int)$db->lastInsertId();
    }
    
    /**
     * Traite les tâches en attente
     */
    public static function processQueue(int $limit = 10): array
    {
        $db = Database::getInstance();
        
        // Récupérer les tâches en attente
        $stmt = $db->prepare("
            SELECT * FROM task_queue
            WHERE status = 'pending'
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $processed = 0;
        $errors = [];
        
        foreach ($tasks as $task) {
            try {
                // Marquer comme en traitement
                $db->prepare("UPDATE task_queue SET status = 'processing', started_at = NOW() WHERE id = ?")
                   ->execute([$task['id']]);
                
                $startTime = microtime(true);
                $result = self::executeTask($task['task_type'], json_decode($task['task_data'], true));
                $executionTime = (int)((microtime(true) - $startTime) * 1000);
                
                if ($result['success']) {
                    // Marquer comme complété
                    $db->prepare("UPDATE task_queue SET status = 'completed', completed_at = NOW() WHERE id = ?")
                       ->execute([$task['id']]);
                    
                    self::logTask($task['id'], null, $task['task_type'], 'success', $result['message'] ?? 'Tâche complétée', $executionTime);
                    $processed++;
                } else {
                    // Gérer les erreurs
                    $attempts = $task['attempts'] + 1;
                    if ($attempts >= $task['max_attempts']) {
                        $db->prepare("UPDATE task_queue SET status = 'failed', attempts = ?, error_message = ? WHERE id = ?")
                           ->execute([$attempts, $result['error'] ?? 'Erreur inconnue', $task['id']]);
                        self::logTask($task['id'], null, $task['task_type'], 'error', $result['error'] ?? 'Erreur inconnue', $executionTime);
                    } else {
                        $db->prepare("UPDATE task_queue SET status = 'pending', attempts = ?, error_message = ? WHERE id = ?")
                           ->execute([$attempts, $result['error'] ?? 'Erreur inconnue', $task['id']]);
                    }
                    $errors[] = $result['error'] ?? 'Erreur inconnue';
                }
            } catch (\Exception $e) {
                $attempts = $task['attempts'] + 1;
                if ($attempts >= $task['max_attempts']) {
                    $db->prepare("UPDATE task_queue SET status = 'failed', attempts = ?, error_message = ? WHERE id = ?")
                       ->execute([$attempts, $e->getMessage(), $task['id']]);
                } else {
                    $db->prepare("UPDATE task_queue SET status = 'pending', attempts = ?, error_message = ? WHERE id = ?")
                       ->execute([$attempts, $e->getMessage(), $task['id']]);
                }
                $errors[] = $e->getMessage();
            }
        }
        
        return ['processed' => $processed, 'errors' => $errors];
    }
    
    /**
     * Exécute une tâche selon son type
     */
    public static function executeTask(string $taskType, array $data = []): array
    {
        try {
            switch ($taskType) {
                case 'index_filesystem':
                    return self::indexFilesystem();
                
                case 'cleanup_trash':
                    return self::cleanupTrash();
                
                case 'check_mail':
                    return self::checkMail($data['account_id'] ?? null);
                
                case 'generate_thumbnails':
                    return self::generateThumbnails($data['document_id'] ?? null);
                
                case 'process_document':
                    return self::processDocument($data['document_id'] ?? null);
                
                case 'scan_consume_folder':
                    return self::scanConsumeFolder();
                
                default:
                    return ['success' => false, 'error' => "Type de tâche inconnu: $taskType"];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Indexe le filesystem
     */
    private static function indexFilesystem(): array
    {
        try {
            $indexer = new \KDocs\Services\FilesystemIndexer();
            if (method_exists($indexer, 'index')) {
                $result = $indexer->index();
                $new = $result['new'] ?? 0;
                $updated = $result['updated'] ?? 0;
                return ['success' => true, 'message' => "Indexation terminée: $new nouveaux, $updated mis à jour"];
            } else {
                return ['success' => false, 'error' => 'Méthode index() non trouvée'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Nettoie la corbeille
     */
    private static function cleanupTrash(): array
    {
        try {
            $db = Database::getInstance();
            // Supprimer les documents dans la corbeille depuis plus de 30 jours
            $stmt = $db->prepare("
                DELETE FROM documents
                WHERE deleted_at IS NOT NULL
                AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            return ['success' => true, 'message' => "$deleted documents supprimés définitivement"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Vérifie les emails
     */
    private static function checkMail(?int $accountId): array
    {
        try {
            if ($accountId) {
                $result = \KDocs\Services\MailService::processAccount($accountId);
            } else {
                // Traiter tous les comptes actifs
                $db = Database::getInstance();
                $accounts = $db->query("SELECT id FROM mail_accounts WHERE is_active = TRUE")->fetchAll(\PDO::FETCH_COLUMN);
                $totalProcessed = 0;
                foreach ($accounts as $id) {
                    $result = \KDocs\Services\MailService::processAccount($id);
                    $totalProcessed += $result['processed'] ?? 0;
                }
                $result = ['success' => true, 'processed' => $totalProcessed];
            }
            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Génère les miniatures manquantes
     */
    private static function generateThumbnails(?int $documentId): array
    {
        try {
            $db = Database::getInstance();
            
            if ($documentId) {
                $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
                $stmt->execute([$documentId]);
                $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
                $documents = $doc ? [$doc] : [];
            } else {
                // Documents sans miniature
                $documents = $db->query("
                    SELECT * FROM documents
                    WHERE thumbnail_path IS NULL
                    AND deleted_at IS NULL
                    LIMIT 100
                ")->fetchAll(\PDO::FETCH_ASSOC);
            }
            
            $generated = 0;
            $generator = new \KDocs\Services\ThumbnailGenerator();
            
            foreach ($documents as $doc) {
                try {
                    $generator->generate($doc['id'], $doc['file_path']);
                    $generated++;
                } catch (\Exception $e) {
                    // Ignorer les erreurs individuelles
                }
            }
            
            return ['success' => true, 'message' => "$generated miniatures générées"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Traite un document (OCR, métadonnées)
     */
    private static function processDocument(?int $documentId): array
    {
        try {
            if (!$documentId) {
                return ['success' => false, 'error' => 'ID de document requis'];
            }
            
            $processor = new \KDocs\Services\DocumentProcessor();
            if (method_exists($processor, 'process')) {
                $processor->process($documentId);
            } elseif (method_exists($processor, 'processDocument')) {
                $processor->processDocument($documentId);
            } else {
                return ['success' => false, 'error' => 'Méthode de traitement non trouvée'];
            }
            
            return ['success' => true, 'message' => "Document $documentId traité"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Scanne le dossier consume et traite les fichiers
     * Vérifie d'abord s'il y a des fichiers avant de scanner
     */
    private static function scanConsumeFolder(): array
    {
        try {
            $service = new \KDocs\Services\ConsumeFolderService();
            
            // Vérifier rapidement s'il y a au moins un fichier
            if (!$service->hasFiles()) {
                return ['success' => true, 'message' => 'Aucun fichier à traiter'];
            }
            
            // Scanner seulement s'il y a des fichiers
            $results = $service->scan();
            
            // Si le scan était déjà en cours, retourner un message approprié
            if (!empty($results['errors']) && strpos($results['errors'][0], 'Scan déjà en cours') !== false) {
                return ['success' => true, 'message' => 'Scan déjà en cours, ignoré'];
            }
            
            $message = sprintf(
                "Scan terminé: %d fichier(s) scanné(s), %d importé(s), %d ignoré(s)",
                $results['scanned'] ?? 0,
                $results['imported'] ?? 0,
                $results['skipped'] ?? 0
            );
            
            if (!empty($results['errors'])) {
                $message .= " (" . count($results['errors']) . " erreur(s))";
                return [
                    'success' => false,
                    'error' => $message . " - " . implode(", ", array_slice($results['errors'], 0, 3))
                ];
            }
            
            return ['success' => true, 'message' => $message];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Log une tâche
     */
    private static function logTask(?int $taskId, ?int $queueId, string $taskType, string $status, string $message, ?int $executionTime): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO task_logs (task_id, queue_id, task_type, status, message, execution_time_ms)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$taskId, $queueId, $taskType, $status, $message, $executionTime]);
    }
}
