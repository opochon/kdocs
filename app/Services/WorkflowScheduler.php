<?php
/**
 * K-Docs - WorkflowScheduler
 * Gestion des workflows planifiés (scheduled)
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Workflow;
use KDocs\Models\WorkflowDefinition;

class WorkflowScheduler
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Vérifie et exécute les workflows planifiés qui doivent être déclenchés
     */
    public function processScheduledWorkflows(): array
    {
        $results = [];
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowScheduler::processScheduledWorkflows', 'Starting scheduled check', [], 'A');
        // #endregion
        
        try {
            // Récupérer les workflows avec trigger scheduled
            $workflows = $this->getScheduledWorkflows();
            
            foreach ($workflows as $workflow) {
                try {
                    $trigger = $this->getScheduledTrigger($workflow['id']);
                    if (!$trigger) {
                        continue;
                    }
                    
                    // Trouver les documents qui matchent les critères de planification
                    $documents = $this->findDocumentsForSchedule($trigger);
                    
                    foreach ($documents as $document) {
                        // Vérifier si le workflow doit être exécuté pour ce document
                        if ($this->shouldExecuteSchedule($trigger, $document)) {
                            $engine = new WorkflowEngine();
                            $result = $engine->executeForEvent('scheduled', $document['id'], [
                                'trigger_id' => $trigger['id'],
                                'workflow_id' => $workflow['id']
                            ]);
                            
                            $results[] = [
                                'workflow_id' => $workflow['id'],
                                'document_id' => $document['id'],
                                'result' => $result
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    error_log("WorkflowScheduler: Erreur workflow {$workflow['id']}: " . $e->getMessage());
                    $results[] = [
                        'workflow_id' => $workflow['id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("WorkflowScheduler: Erreur générale: " . $e->getMessage());
        }
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('WorkflowScheduler::processScheduledWorkflows', 'Completed', [
            'resultsCount' => count($results)
        ], 'A');
        // #endregion
        
        return $results;
    }
    
    /**
     * Récupère les workflows avec trigger scheduled
     */
    private function getScheduledWorkflows(): array
    {
        try {
            $sql = "
                SELECT DISTINCT w.* FROM workflows w
                INNER JOIN workflow_triggers t ON w.id = t.workflow_id
                WHERE w.enabled = 1 AND t.trigger_type = 'scheduled'
                ORDER BY w.order_index, w.name
            ";
            $stmt = $this->db->query($sql);
            return $stmt->fetchAll() ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Récupère le trigger scheduled d'un workflow
     */
    private function getScheduledTrigger(int $workflowId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM workflow_triggers 
                WHERE workflow_id = ? AND trigger_type = 'scheduled'
                LIMIT 1
            ");
            $stmt->execute([$workflowId]);
            return $stmt->fetch() ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Trouve les documents qui matchent les critères de planification
     */
    private function findDocumentsForSchedule(array $trigger): array
    {
        $dateField = $trigger['schedule_date_field'] ?? 'created';
        $offsetDays = (int)($trigger['schedule_offset_days'] ?? 0);
        $customFieldId = $trigger['schedule_date_custom_field'] ?? null;
        
        // Calculer la date cible
        $targetDate = date('Y-m-d', strtotime("$offsetDays days"));
        
        $sql = "SELECT * FROM documents WHERE deleted_at IS NULL";
        $params = [];
        
        if ($dateField === 'custom_field' && $customFieldId) {
            // Utiliser un custom field comme date
            $sql .= " AND id IN (
                SELECT document_id FROM document_custom_fields 
                WHERE custom_field_id = ? AND DATE(value) = ?
            )";
            $params[] = $customFieldId;
            $params[] = $targetDate;
        } else {
            // Utiliser un champ standard
            $fieldMap = [
                'created' => 'created_at',
                'added' => 'created_at',
                'modified' => 'updated_at'
            ];
            $field = $fieldMap[$dateField] ?? 'created_at';
            $sql .= " AND DATE($field) = ?";
            $params[] = $targetDate;
        }
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (\Exception $e) {
            error_log("WorkflowScheduler: Erreur recherche documents: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Vérifie si le workflow doit être exécuté pour ce document
     */
    private function shouldExecuteSchedule(array $trigger, array $document): bool
    {
        // Vérifier si récurrent
        $isRecurring = !empty($trigger['schedule_is_recurring']);
        $intervalDays = (int)($trigger['schedule_recurring_interval_days'] ?? 0);
        
        if ($isRecurring && $intervalDays > 0) {
            // Vérifier si le workflow a déjà été exécuté récemment pour ce document
            // TODO: Implémenter la vérification de dernière exécution
        }
        
        return true;
    }
}
