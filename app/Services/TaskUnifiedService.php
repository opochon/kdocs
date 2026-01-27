<?php
/**
 * K-Docs - TaskUnifiedService
 * Service qui agrège toutes les sources de tâches utilisateur
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Role;

class TaskUnifiedService
{
    private $db;
    private $validationService;
    private $userNoteService;

    // Types de tâches
    const TYPE_VALIDATION = 'validation';
    const TYPE_CONSUME = 'consume';
    const TYPE_WORKFLOW = 'workflow';
    const TYPE_NOTE = 'note';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->validationService = new ValidationService();
        $this->userNoteService = new UserNoteService();
    }

    /**
     * Récupère toutes les tâches d'un utilisateur
     */
    public function getAllTasksForUser(int $userId, array $filters = []): array
    {
        $tasks = [];

        // 1. Documents à valider
        if (!isset($filters['type']) || $filters['type'] === self::TYPE_VALIDATION) {
            $validations = $this->getValidationTasks($userId, $filters['limit'] ?? 50);
            $tasks = array_merge($tasks, $validations);
        }

        // 2. Documents à classer (consume folder)
        if (!isset($filters['type']) || $filters['type'] === self::TYPE_CONSUME) {
            $consumeTasks = $this->getConsumeTasks($userId, $filters['limit'] ?? 50);
            $tasks = array_merge($tasks, $consumeTasks);
        }

        // 3. Tâches workflow
        if (!isset($filters['type']) || $filters['type'] === self::TYPE_WORKFLOW) {
            $workflowTasks = $this->getWorkflowTasks($userId, $filters['limit'] ?? 50);
            $tasks = array_merge($tasks, $workflowTasks);
        }

        // 4. Notes avec action requise
        if (!isset($filters['type']) || $filters['type'] === self::TYPE_NOTE) {
            $noteTasks = $this->getNoteTasks($userId, $filters['limit'] ?? 50);
            $tasks = array_merge($tasks, $noteTasks);
        }

        // Trier par priorité et date
        usort($tasks, function ($a, $b) {
            $priorityOrder = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
            $aPriority = $priorityOrder[$a['priority']] ?? 2;
            $bPriority = $priorityOrder[$b['priority']] ?? 2;

            if ($aPriority !== $bPriority) {
                return $aPriority - $bPriority;
            }

            // À priorité égale, trier par deadline puis par date de création
            if (!empty($a['deadline']) && !empty($b['deadline'])) {
                return strtotime($a['deadline']) - strtotime($b['deadline']);
            }
            if (!empty($a['deadline'])) return -1;
            if (!empty($b['deadline'])) return 1;

            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // Appliquer la limite globale
        if (isset($filters['limit'])) {
            $tasks = array_slice($tasks, 0, $filters['limit']);
        }

        return $tasks;
    }

    /**
     * Compte toutes les tâches d'un utilisateur
     */
    public function getTaskCounts(int $userId): array
    {
        return [
            'total' => $this->getTotalCount($userId),
            'validation' => $this->getValidationCount($userId),
            'consume' => $this->getConsumeCount($userId),
            'workflow' => $this->getWorkflowCount($userId),
            'notes' => $this->getNoteCount($userId),
            'urgent' => $this->getUrgentCount($userId)
        ];
    }

    /**
     * Récupère les documents à valider pour l'utilisateur
     */
    private function getValidationTasks(int $userId, int $limit = 50): array
    {
        $pendingDocs = $this->validationService->getPendingForUser($userId, $limit);

        return array_map(function ($doc) {
            $priority = 'normal';
            if (!empty($doc['approval_deadline'])) {
                $deadline = strtotime($doc['approval_deadline']);
                $now = time();
                if ($deadline < $now) {
                    $priority = 'urgent';
                } elseif ($deadline < $now + 86400) { // 24h
                    $priority = 'high';
                }
            }

            return [
                'id' => 'validation_' . $doc['id'],
                'type' => self::TYPE_VALIDATION,
                'document_id' => $doc['id'],
                'title' => 'Valider : ' . ($doc['title'] ?: $doc['original_filename']),
                'description' => $this->formatValidationDescription($doc),
                'status' => 'pending',
                'priority' => $priority,
                'deadline' => $doc['approval_deadline'] ?? null,
                'created_at' => $doc['created_at'],
                'link' => '/documents/' . $doc['id'],
                'action_link' => '/mes-taches',
                'metadata' => [
                    'document_type' => $doc['document_type_label'] ?? null,
                    'correspondent' => $doc['correspondent_name'] ?? null,
                    'amount' => $doc['amount'] ?? null,
                    'currency' => $doc['currency'] ?? 'CHF',
                    'created_by' => $doc['created_by_username'] ?? null
                ]
            ];
        }, $pendingDocs);
    }

    /**
     * Récupère les documents à classer (consume folder)
     */
    private function getConsumeTasks(int $userId, int $limit = 50): array
    {
        // Documents en status 'pending' ou 'needs_review' dans la table documents
        $stmt = $this->db->prepare("
            SELECT
                d.id, d.title, d.original_filename, d.status, d.created_at,
                dt.label as document_type_label,
                c.name as correspondent_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.status IN ('pending', 'needs_review')
              AND d.deleted_at IS NULL
            ORDER BY d.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($doc) {
            return [
                'id' => 'consume_' . $doc['id'],
                'type' => self::TYPE_CONSUME,
                'document_id' => $doc['id'],
                'title' => 'Classer : ' . ($doc['title'] ?: $doc['original_filename']),
                'description' => $doc['status'] === 'needs_review'
                    ? 'Document nécessitant une révision manuelle'
                    : 'Document en attente de classification',
                'status' => 'pending',
                'priority' => $doc['status'] === 'needs_review' ? 'high' : 'normal',
                'deadline' => null,
                'created_at' => $doc['created_at'],
                'link' => '/admin/consume',
                'action_link' => '/admin/consume',
                'metadata' => [
                    'document_status' => $doc['status']
                ]
            ];
        }, $docs);
    }

    /**
     * Récupère les tâches workflow assignées à l'utilisateur
     */
    private function getWorkflowTasks(int $userId, int $limit = 50): array
    {
        // Vérifier si la table workflow_approval_tasks existe
        try {
            $stmt = $this->db->prepare("
                SELECT
                    wat.id, wat.token, wat.status, wat.created_at, wat.deadline,
                    d.id as document_id, d.title as document_title, d.original_filename,
                    wd.name as workflow_name
                FROM workflow_approval_tasks wat
                JOIN workflow_instances wi ON wat.instance_id = wi.id
                JOIN workflow_definitions wd ON wi.workflow_id = wd.id
                JOIN documents d ON wi.document_id = d.id
                WHERE wat.approver_user_id = ?
                  AND wat.status = 'pending'
                ORDER BY wat.deadline ASC, wat.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table n'existe pas ou autre erreur
            return [];
        }

        return array_map(function ($task) {
            $priority = 'normal';
            if (!empty($task['deadline'])) {
                $deadline = strtotime($task['deadline']);
                $now = time();
                if ($deadline < $now) {
                    $priority = 'urgent';
                } elseif ($deadline < $now + 86400) {
                    $priority = 'high';
                }
            }

            return [
                'id' => 'workflow_' . $task['id'],
                'type' => self::TYPE_WORKFLOW,
                'document_id' => $task['document_id'],
                'title' => 'Workflow : ' . ($task['document_title'] ?: $task['original_filename']),
                'description' => 'Étape du workflow "' . $task['workflow_name'] . '"',
                'status' => 'pending',
                'priority' => $priority,
                'deadline' => $task['deadline'],
                'created_at' => $task['created_at'],
                'link' => '/workflow/approve/' . $task['token'],
                'action_link' => '/workflow/approve/' . $task['token'],
                'metadata' => [
                    'workflow_name' => $task['workflow_name'],
                    'token' => $task['token']
                ]
            ];
        }, $tasks);
    }

    /**
     * Récupère les notes avec action requise
     */
    private function getNoteTasks(int $userId, int $limit = 50): array
    {
        $notes = $this->userNoteService->getPendingActionsForUser($userId, $limit);

        return array_map(function ($note) {
            return [
                'id' => 'note_' . $note['id'],
                'type' => self::TYPE_NOTE,
                'document_id' => $note['document_id'],
                'note_id' => $note['id'],
                'title' => $note['subject'] ?: ('Note de ' . ($note['from_fullname'] ?: $note['from_username'])),
                'description' => mb_substr($note['message'], 0, 200) . (mb_strlen($note['message']) > 200 ? '...' : ''),
                'status' => 'pending',
                'priority' => 'normal',
                'deadline' => null,
                'created_at' => $note['created_at'],
                'link' => $note['document_id'] ? '/documents/' . $note['document_id'] : '/mes-taches',
                'action_link' => '/mes-taches',
                'metadata' => [
                    'from_user' => $note['from_fullname'] ?: $note['from_username'],
                    'action_type' => $note['action_type'],
                    'document_title' => $note['document_title'] ?? $note['document_filename'] ?? null
                ]
            ];
        }, $notes);
    }

    /**
     * Formate la description d'une tâche de validation
     */
    private function formatValidationDescription(array $doc): string
    {
        $parts = [];

        if (!empty($doc['document_type_label'])) {
            $parts[] = $doc['document_type_label'];
        }
        if (!empty($doc['correspondent_name'])) {
            $parts[] = $doc['correspondent_name'];
        }
        if (!empty($doc['amount'])) {
            $parts[] = number_format($doc['amount'], 2, '.', "'") . ' ' . ($doc['currency'] ?? 'CHF');
        }
        if (!empty($doc['created_by_username'])) {
            $parts[] = 'par ' . $doc['created_by_username'];
        }

        return $parts ? implode(' - ', $parts) : 'Document en attente de validation';
    }

    /**
     * Compteurs individuels
     */
    private function getValidationCount(int $userId): int
    {
        $docs = $this->validationService->getPendingForUser($userId, 1000);
        return count($docs);
    }

    private function getConsumeCount(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM documents
            WHERE status IN ('pending', 'needs_review')
              AND deleted_at IS NULL
        ");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private function getWorkflowCount(int $userId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM workflow_approval_tasks
                WHERE approver_user_id = ? AND status = 'pending'
            ");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getNoteCount(int $userId): int
    {
        return $this->userNoteService->getPendingActionCount($userId);
    }

    private function getTotalCount(int $userId): int
    {
        return $this->getValidationCount($userId)
            + $this->getConsumeCount($userId)
            + $this->getWorkflowCount($userId)
            + $this->getNoteCount($userId);
    }

    private function getUrgentCount(int $userId): int
    {
        $count = 0;

        // Compter les validations urgentes (deadline dépassée ou < 24h)
        $docs = $this->validationService->getPendingForUser($userId, 1000);
        foreach ($docs as $doc) {
            if (!empty($doc['approval_deadline'])) {
                $deadline = strtotime($doc['approval_deadline']);
                if ($deadline < time() + 86400) {
                    $count++;
                }
            }
        }

        // Compter les workflows urgents
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM workflow_approval_tasks
                WHERE approver_user_id = ?
                  AND status = 'pending'
                  AND deadline IS NOT NULL
                  AND deadline < DATE_ADD(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$userId]);
            $count += (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            // Ignorer
        }

        return $count;
    }

    /**
     * Récupère les tâches urgentes pour le dashboard
     */
    public function getUrgentTasks(int $userId, int $limit = 5): array
    {
        $tasks = $this->getAllTasksForUser($userId, ['limit' => $limit * 3]);

        // Filtrer les tâches urgentes ou high
        $urgent = array_filter($tasks, function ($task) {
            return in_array($task['priority'], ['urgent', 'high']);
        });

        return array_slice(array_values($urgent), 0, $limit);
    }

    /**
     * Récupère un résumé des tâches pour le widget dashboard
     */
    public function getDashboardSummary(int $userId): array
    {
        $counts = $this->getTaskCounts($userId);
        $urgentTasks = $this->getUrgentTasks($userId, 5);

        return [
            'counts' => $counts,
            'urgent_tasks' => $urgentTasks,
            'has_urgent' => $counts['urgent'] > 0
        ];
    }
}
