<?php
/**
 * K-Docs - ValidationService
 * Service centralisé pour la validation des documents
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Role;
use KDocs\Workflow\WorkflowEngine;

class ValidationService
{
    private $db;
    private $notificationService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->notificationService = new NotificationService();
    }

    /**
     * Soumet un document pour validation
     */
    public function submitForApproval(int $documentId, ?int $submittedBy = null): array
    {
        // Récupérer le document
        $document = $this->getDocument($documentId);
        if (!$document) {
            return ['success' => false, 'error' => 'Document non trouvé'];
        }

        // Vérifier si déjà en cours de validation
        if ($document['validation_status'] === 'pending') {
            return ['success' => false, 'error' => 'Document déjà en attente de validation'];
        }

        if ($document['validation_status'] === 'approved') {
            return ['success' => false, 'error' => 'Document déjà approuvé'];
        }

        // Déterminer la règle d'approbation applicable
        $rule = $this->findApplicableRule($document);

        try {
            $this->db->beginTransaction();

            // Calculer la deadline
            $timeoutHours = $rule['timeout_hours'] ?? 72;
            $deadline = date('Y-m-d H:i:s', time() + ($timeoutHours * 3600));

            // Mettre à jour le document
            $stmt = $this->db->prepare("
                UPDATE documents
                SET validation_status = 'pending',
                    requires_approval = TRUE,
                    approval_deadline = ?,
                    validation_comment = NULL,
                    validated_by = NULL,
                    validated_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$deadline, $documentId]);

            // Enregistrer dans l'historique
            $stmt = $this->db->prepare("
                INSERT INTO document_validation_history
                (document_id, action, from_status, to_status, performed_by, comment)
                VALUES (?, 'submitted', ?, 'pending', ?, ?)
            ");
            $stmt->execute([
                $documentId,
                $document['validation_status'],
                $submittedBy,
                $rule ? "Règle appliquée: {$rule['name']}" : 'Soumission manuelle'
            ]);

            $this->db->commit();

            // Déclencher les workflows si configurés
            if (class_exists('\\KDocs\\Workflow\\WorkflowEngine')) {
                WorkflowEngine::executeForEvent('document_submitted_for_approval', $documentId, [
                    'submitted_by' => $submittedBy,
                    'rule_id' => $rule['id'] ?? null,
                    'deadline' => $deadline
                ]);
            }

            // Notifier les validateurs potentiels
            try {
                $this->notificationService->notifyValidationPending($documentId);
            } catch (\Exception $e) {
                error_log("ValidationService: notification error: " . $e->getMessage());
            }

            return [
                'success' => true,
                'deadline' => $deadline,
                'rule' => $rule
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Valide un document (approuve, rejette ou marque N/A)
     */
    public function validate(
        int $documentId,
        string $decision,
        int $validatedBy,
        ?string $comment = null
    ): array {
        if (!in_array($decision, ['approved', 'rejected', 'na'])) {
            return ['success' => false, 'error' => 'Décision invalide'];
        }

        $document = $this->getDocument($documentId);
        if (!$document) {
            return ['success' => false, 'error' => 'Document non trouvé'];
        }

        // Vérifier les droits de l'utilisateur
        $canValidate = Role::canUserValidateDocument($validatedBy, $document);
        if (!$canValidate['can_validate']) {
            return ['success' => false, 'error' => $canValidate['reason']];
        }

        $previousStatus = $document['validation_status'];

        try {
            $this->db->beginTransaction();

            // Mettre à jour le document
            $stmt = $this->db->prepare("
                UPDATE documents
                SET validation_status = ?,
                    validated_by = ?,
                    validated_at = NOW(),
                    validation_comment = ?,
                    requires_approval = FALSE
                WHERE id = ?
            ");
            $stmt->execute([$decision, $validatedBy, $comment, $documentId]);

            // Enregistrer dans l'historique
            $stmt = $this->db->prepare("
                INSERT INTO document_validation_history
                (document_id, action, from_status, to_status, performed_by, role_code, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $documentId,
                $decision,
                $previousStatus,
                $decision,
                $validatedBy,
                $canValidate['role_code'] ?? null,
                $comment
            ]);

            $this->db->commit();

            // Déclencher l'événement de changement de statut
            if (class_exists('\\KDocs\\Workflow\\WorkflowEngine')) {
                WorkflowEngine::executeForEvent('document_validation_changed', $documentId, [
                    'previous_status' => $previousStatus,
                    'new_status' => $decision,
                    'validated_by' => $validatedBy,
                    'comment' => $comment
                ]);
            }

            // Notifier le créateur du document du résultat
            try {
                $this->notificationService->notifyValidationResult($documentId, $decision, $validatedBy);
            } catch (\Exception $e) {
                error_log("ValidationService: notification error: " . $e->getMessage());
            }

            return [
                'success' => true,
                'status' => $decision,
                'validated_by' => $validatedBy,
                'role' => $canValidate['role_code'] ?? null
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Récupère les documents en attente de validation pour un utilisateur
     */
    public function getPendingForUser(int $userId, int $limit = 50): array
    {
        $roles = Role::getUserRoles($userId);
        if (empty($roles)) {
            return [];
        }

        // Construire les conditions basées sur les rôles
        $conditions = [];
        $params = [];

        foreach ($roles as $role) {
            $cond = "(1=1";
            if ($role['scope'] !== '*') {
                $cond .= " AND dt.code = ?";
                $params[] = $role['scope'];
            }
            if ($role['max_amount'] !== null) {
                $cond .= " AND (d.amount IS NULL OR d.amount <= ?)";
                $params[] = $role['max_amount'];
            }
            $cond .= ")";
            $conditions[] = $cond;
        }

        $whereRoles = '(' . implode(' OR ', $conditions) . ')';

        $sql = "
            SELECT
                d.id, d.title, d.original_filename, d.amount, d.currency,
                d.doc_date, d.validation_status, d.approval_deadline, d.created_at,
                dt.code as document_type_code, dt.label as document_type_label,
                c.name as correspondent_name,
                u.username as created_by_username,
                DATEDIFF(d.approval_deadline, NOW()) as days_until_deadline
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.requires_approval = TRUE
              AND d.validation_status IN ('pending', NULL)
              AND $whereRoles
            ORDER BY d.approval_deadline ASC, d.amount DESC
            LIMIT ?
        ";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Récupère l'historique de validation d'un document
     */
    public function getHistory(int $documentId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                h.*,
                u.username,
                CONCAT(u.first_name, ' ', u.last_name) as user_full_name
            FROM document_validation_history h
            LEFT JOIN users u ON h.performed_by = u.id
            WHERE h.document_id = ?
            ORDER BY h.created_at DESC
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Trouve la règle d'approbation applicable à un document
     */
    public function findApplicableRule(array $document): ?array
    {
        $amount = (float)($document['amount'] ?? 0);
        $documentTypeId = $document['document_type_id'] ?? null;
        $documentTypeCode = $document['document_type_code'] ?? null;
        $correspondentId = $document['correspondent_id'] ?? null;

        $stmt = $this->db->prepare("
            SELECT * FROM approval_rules
            WHERE is_active = TRUE
              AND (document_type_id IS NULL OR document_type_id = ?)
              AND (document_type_code IS NULL OR document_type_code = ?)
              AND (correspondent_id IS NULL OR correspondent_id = ?)
              AND (min_amount IS NULL OR ? >= min_amount)
              AND (max_amount IS NULL OR ? <= max_amount)
            ORDER BY priority ASC, min_amount DESC
            LIMIT 1
        ");
        $stmt->execute([
            $documentTypeId,
            $documentTypeCode,
            $correspondentId,
            $amount,
            $amount
        ]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Récupère un document avec ses infos complètes
     */
    private function getDocument(int $documentId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, dt.code as document_type_code, dt.label as document_type_label
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Statistiques de validation
     */
    public function getStatistics(?int $userId = null, ?string $period = 'month'): array
    {
        $dateCondition = match($period) {
            'day' => "DATE(validated_at) = CURDATE()",
            'week' => "validated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "validated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "validated_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)",
            default => "1=1"
        };

        $userCondition = $userId ? "AND validated_by = ?" : "";
        $params = $userId ? [$userId] : [];

        $sql = "
            SELECT
                validation_status,
                COUNT(*) as count,
                SUM(amount) as total_amount,
                AVG(amount) as avg_amount
            FROM documents
            WHERE validation_status IS NOT NULL
              AND $dateCondition
              $userCondition
            GROUP BY validation_status
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $stats = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Formater les résultats
        $result = [
            'approved' => ['count' => 0, 'total_amount' => 0, 'avg_amount' => 0],
            'rejected' => ['count' => 0, 'total_amount' => 0, 'avg_amount' => 0],
            'pending' => ['count' => 0, 'total_amount' => 0, 'avg_amount' => 0],
        ];

        foreach ($stats as $stat) {
            if (isset($result[$stat['validation_status']])) {
                $result[$stat['validation_status']] = [
                    'count' => (int)$stat['count'],
                    'total_amount' => (float)$stat['total_amount'],
                    'avg_amount' => (float)$stat['avg_amount']
                ];
            }
        }

        return $result;
    }
}
