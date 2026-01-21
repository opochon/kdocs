<?php
/**
 * K-Docs - Service Workflow (Phase 3.3)
 * Exécute les workflows automatiques
 */

namespace KDocs\Services;

use KDocs\Models\Workflow;
use KDocs\Core\Database;

class WorkflowService
{
    /**
     * Exécute les workflows pour un document nouvellement ajouté
     */
    public static function executeOnDocumentAdded(int $documentId): void
    {
        self::executeWorkflows('document_added', $documentId);
    }
    
    /**
     * Exécute les workflows pour un document modifié
     */
    public static function executeOnDocumentModified(int $documentId): void
    {
        self::executeWorkflows('document_modified', $documentId);
    }
    
    /**
     * Exécute les workflows correspondants
     */
    private static function executeWorkflows(string $triggerType, int $documentId): void
    {
        $db = Database::getInstance();
        
        // Récupérer le document
        $docStmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $docStmt->execute([$documentId]);
        $document = $docStmt->fetch();
        
        if (!$document) {
            return;
        }
        
        // Récupérer tous les workflows actifs
        $workflows = $db->query("SELECT * FROM workflows WHERE enabled = 1 ORDER BY order_index")->fetchAll();
        
        foreach ($workflows as $workflow) {
            $triggers = Workflow::getTriggers($workflow['id']);
            
            // Vérifier si un trigger correspond
            $shouldExecute = false;
            foreach ($triggers as $trigger) {
                if ($trigger['trigger_type'] === $triggerType) {
                    if (self::checkCondition($trigger, $document)) {
                        $shouldExecute = true;
                        break;
                    }
                }
            }
            
            if ($shouldExecute) {
                self::executeWorkflow($workflow['id'], $documentId);
            }
        }
    }
    
    /**
     * Vérifie si une condition de trigger est remplie
     */
    private static function checkCondition(array $trigger, array $document): bool
    {
        if ($trigger['condition_type'] === 'always') {
            return true;
        }
        
        $db = Database::getInstance();
        
        switch ($trigger['condition_type']) {
            case 'if_has_tag':
                $tagId = (int)$trigger['condition_value'];
                $stmt = $db->prepare("SELECT COUNT(*) FROM document_tags WHERE document_id = ? AND tag_id = ?");
                $stmt->execute([$document['id'], $tagId]);
                return $stmt->fetchColumn() > 0;
                
            case 'if_has_correspondent':
                $corrId = (int)$trigger['condition_value'];
                return $document['correspondent_id'] == $corrId;
                
            case 'if_has_type':
                $typeId = (int)$trigger['condition_value'];
                return $document['document_type_id'] == $typeId;
                
            case 'if_match':
                // Utiliser MatchingService pour vérifier le texte OCR
                $ocrText = $document['ocr_text'] ?? '';
                return \KDocs\Services\MatchingService::match($ocrText, 'exact', $trigger['condition_value']);
                
            default:
                return false;
        }
    }
    
    /**
     * Exécute un workflow sur un document
     */
    private static function executeWorkflow(int $workflowId, int $documentId): void
    {
        $db = Database::getInstance();
        $actions = Workflow::getActions($workflowId);
        
        foreach ($actions as $action) {
            try {
                switch ($action['action_type']) {
                    case 'assign_tag':
                        $tagId = (int)$action['action_value'];
                        $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$documentId, $tagId]);
                        break;
                        
                    case 'assign_correspondent':
                        $corrId = (int)$action['action_value'];
                        $db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?")->execute([$corrId, $documentId]);
                        break;
                        
                    case 'assign_type':
                        $typeId = (int)$action['action_value'];
                        $db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?")->execute([$typeId, $documentId]);
                        break;
                        
                    case 'assign_storage_path':
                        $spathId = (int)$action['action_value'];
                        $db->prepare("UPDATE documents SET storage_path_id = ? WHERE id = ?")->execute([$spathId, $documentId]);
                        break;
                }
                
                Workflow::logExecution($workflowId, $documentId, 'success');
            } catch (\Exception $e) {
                Workflow::logExecution($workflowId, $documentId, 'error', $e->getMessage());
            }
        }
    }
}
