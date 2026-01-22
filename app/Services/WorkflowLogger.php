<?php
/**
 * K-Docs - WorkflowLogger
 * Journalisation complète des exécutions de workflows
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class WorkflowLogger
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Log une exécution de workflow
     */
    public function logExecution(int $workflowId, int $documentId, string $status, string $message, array $data = []): void
    {
        try {
            // Vérifier si la table workflow_logs existe
            $this->db->query("SELECT 1 FROM workflow_logs LIMIT 1");
        } catch (\Exception $e) {
            // Table n'existe pas, utiliser error_log
            error_log("Workflow $workflowId - Document $documentId - $status: $message");
            return;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO workflow_logs 
                (workflow_id, document_id, status, message, data, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $workflowId,
                $documentId,
                $status,
                $message,
                json_encode($data)
            ]);
        } catch (\Exception $e) {
            error_log("WorkflowLogger: Erreur log: " . $e->getMessage());
        }
    }
    
    /**
     * Récupère les logs d'un workflow
     */
    public function getWorkflowLogs(int $workflowId, int $limit = 100): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM workflow_logs 
                WHERE workflow_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$workflowId, $limit]);
            return $stmt->fetchAll() ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Récupère les logs d'un document
     */
    public function getDocumentLogs(int $documentId, int $limit = 100): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT wl.*, w.name as workflow_name
                FROM workflow_logs wl
                LEFT JOIN workflows w ON wl.workflow_id = w.id
                WHERE wl.document_id = ?
                ORDER BY wl.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$documentId, $limit]);
            return $stmt->fetchAll() ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
