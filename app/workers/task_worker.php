<?php
/**
 * K-Docs - Worker pour traitement de la file d'attente
 * À exécuter périodiquement via cron ou tâche planifiée Windows
 * 
 * Exemple cron (Linux) :
 * */5 * * * * cd /path/to/kdocs && php app/workers/task_worker.php
 * 
 * Exemple tâche planifiée Windows :
 * php.exe C:\wamp64\www\kdocs\app\workers\task_worker.php
 */

require_once __DIR__ . '/../../app/autoload.php';

use KDocs\Services\TaskService;
use KDocs\Models\ScheduledTask;

echo "[" . date('Y-m-d H:i:s') . "] Démarrage du worker de tâches\n";

// Traiter la file d'attente
$result = TaskService::processQueue(10);
echo "[" . date('Y-m-d H:i:s') . "] File d'attente traitée : {$result['processed']} tâche(s)\n";

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        echo "[" . date('Y-m-d H:i:s') . "] Erreur : $error\n";
    }
}

// Vérifier les tâches planifiées à exécuter
$dueTasks = ScheduledTask::getDueTasks();
foreach ($dueTasks as $task) {
    echo "[" . date('Y-m-d H:i:s') . "] Exécution de la tâche : {$task['name']}\n";
    try {
        $taskData = json_decode($task['task_data'] ?? '{}', true) ?: [];
        $result = TaskService::executeTask($task['task_type'], $taskData);
        ScheduledTask::updateExecution($task['id'], $result['success'] ? 'success' : 'error', $result['error'] ?? null);
        
        // Calculer la prochaine exécution
        $nextRun = ScheduledTask::calculateNextRun($task['schedule_cron']);
        if ($nextRun) {
            $db = \KDocs\Core\Database::getInstance();
            $db->prepare("UPDATE scheduled_tasks SET next_run_at = ? WHERE id = ?")
               ->execute([$nextRun->format('Y-m-d H:i:s'), $task['id']]);
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] Tâche terminée : " . ($result['success'] ? 'succès' : 'erreur') . "\n";
    } catch (\Exception $e) {
        ScheduledTask::updateExecution($task['id'], 'error', $e->getMessage());
        echo "[" . date('Y-m-d H:i:s') . "] Erreur lors de l'exécution : {$e->getMessage()}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Fin du worker\n";
