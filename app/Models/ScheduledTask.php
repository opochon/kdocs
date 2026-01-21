<?php
/**
 * K-Docs - Modèle ScheduledTask
 * Tâches planifiées
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class ScheduledTask
{
    /**
     * Récupère toutes les tâches planifiées
     */
    public static function all(): array
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('ScheduledTask::all', 'Method entry', [], 'E');
        // #endregion
        
        $db = Database::getInstance();
        
        // #region agent log
        \KDocs\Core\DebugLogger::log('ScheduledTask::all', 'Before query execution', [], 'E');
        // #endregion
        
        try {
            $result = $db->query("SELECT * FROM scheduled_tasks ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
            
            // #region agent log
            \KDocs\Core\DebugLogger::log('ScheduledTask::all', 'Query successful', [
                'resultCount' => count($result)
            ], 'E');
            // #endregion
            
            return $result;
        } catch (\PDOException $e) {
            // #region agent log
            \KDocs\Core\DebugLogger::logException($e, 'ScheduledTask::all - Query failed', 'E');
            // #endregion
            throw $e;
        }
    }
    
    /**
     * Trouve une tâche par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM scheduled_tasks WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Trouve une tâche par nom
     */
    public static function findByName(string $name): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM scheduled_tasks WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Met à jour une tâche
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        
        $fields = [];
        $params = [];
        
        $allowedFields = ['name', 'task_type', 'schedule_cron', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $id;
        $stmt = $db->prepare("UPDATE scheduled_tasks SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }
    
    /**
     * Met à jour le statut d'exécution
     */
    public static function updateExecution(int $id, string $status, ?string $error = null): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE scheduled_tasks
            SET last_run_at = NOW(),
                last_status = ?,
                last_error = ?,
                next_run_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$status, $error, $id]);
    }
    
    /**
     * Calcule la prochaine exécution depuis une expression cron
     */
    public static function calculateNextRun(string $cronExpression): ?\DateTime
    {
        // Parser simple de cron (format: minute hour day month weekday)
        // Exemple: "0 2 * * *" = tous les jours à 2h00
        $parts = explode(' ', $cronExpression);
        if (count($parts) !== 5) {
            return null;
        }
        
        [$minute, $hour, $day, $month, $weekday] = $parts;
        
        $now = new \DateTime();
        $next = clone $now;
        
        // Logique simplifiée pour calculer la prochaine exécution
        // Pour une implémentation complète, utiliser une bibliothèque cron
        if ($minute !== '*' && $hour !== '*') {
            $next->setTime((int)$hour, (int)$minute, 0);
            if ($next <= $now) {
                $next->modify('+1 day');
            }
        } else {
            $next->modify('+1 hour');
        }
        
        return $next;
    }
    
    /**
     * Récupère les tâches à exécuter
     */
    public static function getDueTasks(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("
            SELECT * FROM scheduled_tasks
            WHERE is_active = TRUE
            AND (next_run_at IS NULL OR next_run_at <= NOW())
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
