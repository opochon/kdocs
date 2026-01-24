<?php
/**
 * K-Docs - Service de gestion des queues avec n0nag0n/simple-job-queue
 * Encapsule l'utilisation de la bibliothèque pour simplifier l'utilisation
 */

namespace KDocs\Services;

use n0nag0n\Job_Queue;
use KDocs\Core\Database;

class QueueService
{
    private static ?Job_Queue $instance = null;
    
    /**
     * Obtient l'instance singleton de Job_Queue
     */
    public static function getInstance(): Job_Queue
    {
        if (self::$instance === null) {
            $pdo = Database::getInstance();
            
            self::$instance = new Job_Queue('mysql', [
                'mysql' => [
                    'table_name' => 'job_queue_jobs',
                    'use_compression' => true
                ]
            ]);
            
            self::$instance->addQueueConnection($pdo);
        }
        
        return self::$instance;
    }
    
    /**
     * Ajoute un job d'indexation de dossier
     * 
     * @param string $folderPath Chemin relatif du dossier à indexer
     * @param string $priority 'normal' ou 'high'
     * @return bool Succès de l'ajout
     */
    public static function queueIndexing(string $folderPath, string $priority = 'normal'): bool
    {
        try {
            $queue = self::getInstance();
            $pipeline = $priority === 'high' ? 'indexing_high' : 'indexing';
            
            $queue->selectPipeline($pipeline);
            
            $jobData = [
                'type' => 'index_folder',
                'path' => $folderPath,
                'created_at' => time()
            ];
            
            $queue->addJob(json_encode($jobData));
            
            return true;
        } catch (\Exception $e) {
            error_log("QueueService::queueIndexing - Erreur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ajoute un job OCR pour un document
     * 
     * @param int $documentId ID du document
     * @return bool Succès de l'ajout
     */
    public static function queueOCR(int $documentId): bool
    {
        try {
            $queue = self::getInstance();
            $queue->selectPipeline('ocr');
            
            $jobData = [
                'type' => 'ocr_document',
                'document_id' => $documentId,
                'created_at' => time()
            ];
            
            $queue->addJob(json_encode($jobData));
            
            return true;
        } catch (\Exception $e) {
            error_log("QueueService::queueOCR - Erreur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ajoute un job de génération de thumbnail
     * 
     * @param int $documentId ID du document
     * @return bool Succès de l'ajout
     */
    public static function queueThumbnail(int $documentId): bool
    {
        try {
            $queue = self::getInstance();
            $queue->selectPipeline('thumbnails');
            
            $jobData = [
                'type' => 'generate_thumbnail',
                'document_id' => $documentId,
                'created_at' => time()
            ];
            
            $queue->addJob(json_encode($jobData));
            
            return true;
        } catch (\Exception $e) {
            error_log("QueueService::queueThumbnail - Erreur: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Compte les jobs en attente dans un pipeline
     * 
     * @param string $pipeline Nom du pipeline
     * @return int Nombre de jobs en attente
     */
    public static function countPendingJobs(string $pipeline = 'indexing'): int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM job_queue_jobs 
                WHERE pipeline = ? AND reserved_at IS NULL AND available_at <= ?
            ");
            $stmt->execute([$pipeline, time()]);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log("QueueService::countPendingJobs - Erreur: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Compte les jobs actifs (réservés) dans un pipeline
     * 
     * @param string $pipeline Nom du pipeline
     * @return int Nombre de jobs actifs
     */
    public static function countActiveJobs(string $pipeline = 'indexing'): int
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM job_queue_jobs 
                WHERE pipeline = ? AND reserved_at IS NOT NULL
            ");
            $stmt->execute([$pipeline]);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            error_log("QueueService::countActiveJobs - Erreur: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Vérifie si un job existe déjà pour un chemin donné
     * 
     * @param string $folderPath Chemin du dossier
     * @return bool True si un job existe déjà
     */
    public static function hasJobForPath(string $folderPath): bool
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM job_queue_jobs 
                WHERE pipeline IN ('indexing', 'indexing_high')
                AND payload LIKE ?
                AND (reserved_at IS NULL OR reserved_at > ?)
            ");
            $searchPattern = '%"path":"' . addslashes($folderPath) . '"%';
            $stmt->execute([$searchPattern, time() - 300]); // Jobs réservés depuis moins de 5 min
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Exception $e) {
            error_log("QueueService::hasJobForPath - Erreur: " . $e->getMessage());
            return false;
        }
    }
}
