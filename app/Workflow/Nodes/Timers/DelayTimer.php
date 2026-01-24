<?php
/**
 * K-Docs - DelayTimer
 * Timer avec délai (ex: attendre 24 heures)
 */

namespace KDocs\Workflow\Nodes\Timers;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class DelayTimer extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->executionId) {
            return ExecutionResult::failed('Aucune exécution associée');
        }
        
        $delaySeconds = $config['delay_seconds'] ?? null;
        $delayMinutes = $config['delay_minutes'] ?? null;
        $delayHours = $config['delay_hours'] ?? null;
        $delayDays = $config['delay_days'] ?? null;
        
        // Calculer le délai total en secondes
        $totalSeconds = 0;
        if ($delaySeconds !== null) {
            $totalSeconds += (int)$delaySeconds;
        }
        if ($delayMinutes !== null) {
            $totalSeconds += (int)$delayMinutes * 60;
        }
        if ($delayHours !== null) {
            $totalSeconds += (int)$delayHours * 3600;
        }
        if ($delayDays !== null) {
            $totalSeconds += (int)$delayDays * 86400;
        }
        
        if ($totalSeconds <= 0) {
            return ExecutionResult::failed('Délai invalide ou non spécifié');
        }
        
        // Calculer la date/heure de déclenchement
        $fireAt = date('Y-m-d H:i:s', time() + $totalSeconds);
        
        try {
            $db = Database::getInstance();
            
            // Créer le timer dans la base de données
            $stmt = $db->prepare("
                INSERT INTO workflow_timers 
                (execution_id, node_id, timer_type, fire_at, status)
                VALUES (?, ?, 'delay', ?, 'waiting')
            ");
            
            $nodeId = $config['node_id'] ?? null;
            $stmt->execute([
                $context->executionId,
                $nodeId,
                $fireAt
            ]);
            
            $timerId = $db->lastInsertId();
            
            // Mettre l'exécution en attente
            // Note: ExecutionResult::waiting() ne prend pas de données, on les stocke dans le contexte
            $result = new ExecutionResult(
                ExecutionResult::STATUS_WAITING,
                'timeout',
                [
                    'timer_id' => $timerId,
                    'fire_at' => $fireAt,
                    'delay_seconds' => $totalSeconds
                ],
                null,
                null,
                'timer'
            );
            return $result;
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur création timer: ' . $e->getMessage());
        }
    }
    
    public function getOutputs(): array
    {
        return ['timeout'];
    }
    
    public function getConfigSchema(): array
    {
        return [
            'delay_seconds' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Délai en secondes',
            ],
            'delay_minutes' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Délai en minutes',
            ],
            'delay_hours' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Délai en heures',
            ],
            'delay_days' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Délai en jours',
            ],
            'node_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID du node dans le workflow (pour référence)',
            ],
        ];
    }
}
