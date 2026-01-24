<?php
/**
 * K-Docs - Process Timers Cron
 * Traite les timers de workflow qui doivent être déclenchés
 * 
 * À exécuter toutes les minutes via cron :
 * * * * * * php /path/to/kdocs/cron/process_timers.php
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;
use KDocs\Workflow\ExecutionEngine;

try {
    $db = Database::getInstance();
    
    echo "[" . date('Y-m-d H:i:s') . "] Traitement des timers workflow...\n";
    
    // Récupérer les timers qui doivent être déclenchés
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.execution_id,
            t.node_id,
            t.timer_type,
            t.fire_at,
            e.status as execution_status,
            e.current_node_id
        FROM workflow_timers t
        INNER JOIN workflow_executions e ON t.execution_id = e.id
        WHERE t.status = 'waiting'
        AND t.fire_at <= NOW()
        AND e.status = 'waiting'
        ORDER BY t.fire_at ASC
        LIMIT 100
    ");
    
    $stmt->execute();
    $timers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    if (empty($timers)) {
        echo "Aucun timer à traiter.\n";
        exit(0);
    }
    
    echo "→ " . count($timers) . " timer(s) à traiter\n";
    
    $processed = 0;
    $errors = 0;
    
    foreach ($timers as $timer) {
        try {
            // Vérifier que l'exécution est toujours en attente
            $execution = \KDocs\Models\WorkflowExecution::findById($timer['execution_id']);
            if (!$execution || $execution['status'] !== 'waiting') {
                // L'exécution n'est plus en attente, annuler le timer
                $db->prepare("UPDATE workflow_timers SET status = 'cancelled' WHERE id = ?")
                   ->execute([$timer['id']]);
                echo "  ⚠ Timer {$timer['id']}: exécution {$timer['execution_id']} n'est plus en attente, timer annulé\n";
                continue;
            }
            
            // Vérifier que le timer correspond toujours au node actuel
            if ($execution['current_node_id'] != $timer['node_id']) {
                // Le workflow a avancé, annuler le timer
                $db->prepare("UPDATE workflow_timers SET status = 'cancelled' WHERE id = ?")
                   ->execute([$timer['id']]);
                echo "  ⚠ Timer {$timer['id']}: node actuel différent, timer annulé\n";
                continue;
            }
            
            // Marquer le timer comme déclenché
            $db->prepare("
                UPDATE workflow_timers 
                SET status = 'fired', fired_at = NOW() 
                WHERE id = ?
            ")->execute([$timer['id']]);
            
            echo "  ✓ Timer {$timer['id']}: déclenché pour exécution {$timer['execution_id']}\n";
            
            // Reprendre l'exécution du workflow
            // Le timer a pour output 'timeout' (voir DelayTimer::getOutputs())
            $resumed = ExecutionEngine::resume($timer['execution_id'], 'timeout');
            
            if ($resumed) {
                echo "    → Exécution {$timer['execution_id']} reprise avec succès\n";
                $processed++;
            } else {
                echo "    ⚠ Exécution {$timer['execution_id']} n'a pas pu être reprise\n";
                $errors++;
            }
            
        } catch (\Exception $e) {
            echo "  ❌ Erreur traitement timer {$timer['id']}: " . $e->getMessage() . "\n";
            error_log("process_timers: Erreur timer {$timer['id']}: " . $e->getMessage());
            $errors++;
        }
    }
    
    echo "\nRésumé: $processed traité(s), $errors erreur(s)\n";
    echo "[" . date('Y-m-d H:i:s') . "] Traitement terminé.\n";
    
} catch (\Exception $e) {
    echo "❌ ERREUR FATALE: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    error_log("process_timers: Erreur fatale: " . $e->getMessage());
    exit(1);
}
