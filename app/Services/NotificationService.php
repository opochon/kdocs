<?php
/**
 * K-Docs - NotificationService
 * Service centralisé pour la gestion des notifications utilisateur
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class NotificationService
{
    private $db;

    // Types de notifications supportés
    const TYPE_VALIDATION_PENDING = 'validation_pending';
    const TYPE_VALIDATION_APPROVED = 'validation_approved';
    const TYPE_VALIDATION_REJECTED = 'validation_rejected';
    const TYPE_NOTE_RECEIVED = 'note_received';
    const TYPE_NOTE_ACTION_REQUIRED = 'note_action_required';
    const TYPE_TASK_ASSIGNED = 'task_assigned';
    const TYPE_TASK_COMPLETED = 'task_completed';
    const TYPE_DOCUMENT_SHARED = 'document_shared';
    const TYPE_WORKFLOW_STEP = 'workflow_step';
    const TYPE_SYSTEM = 'system';

    // Priorités
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Crée une nouvelle notification
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        ?string $message = null,
        ?string $link = null,
        array $options = []
    ): ?int {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications
                (user_id, type, title, message, link, document_id, related_user_id, priority, action_url, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, NOW())
            ");
            $stmt->execute([
                $userId,
                $type,
                $title,
                $message,
                $link,
                $options['document_id'] ?? null,
                $options['related_user_id'] ?? null,
                $options['priority'] ?? self::PRIORITY_NORMAL,
                $options['action_url'] ?? $link
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\Exception $e) {
            error_log("NotificationService::create error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère les notifications non lues d'un utilisateur
     */
    public function getUnreadForUser(int $userId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT
                n.*,
                d.title as document_title,
                d.original_filename as document_filename,
                u.username as related_username,
                CONCAT(u.first_name, ' ', u.last_name) as related_user_fullname
            FROM notifications n
            LEFT JOIN documents d ON n.document_id = d.id
            LEFT JOIN users u ON n.related_user_id = u.id
            WHERE n.user_id = ? AND n.is_read = FALSE
            ORDER BY
                FIELD(n.priority, 'urgent', 'high', 'normal', 'low'),
                n.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Récupère toutes les notifications d'un utilisateur (avec pagination)
     */
    public function getAllForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT
                n.*,
                d.title as document_title,
                d.original_filename as document_filename,
                u.username as related_username,
                CONCAT(u.first_name, ' ', u.last_name) as related_user_fullname
            FROM notifications n
            LEFT JOIN documents d ON n.document_id = d.id
            LEFT JOIN users u ON n.related_user_id = u.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Compte les notifications non lues d'un utilisateur
     */
    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère le compteur avec détails par priorité
     */
    public function getUnreadCountByPriority(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                priority,
                COUNT(*) as count
            FROM notifications
            WHERE user_id = ? AND is_read = FALSE
            GROUP BY priority
        ");
        $stmt->execute([$userId]);

        $result = [
            'total' => 0,
            'urgent' => 0,
            'high' => 0,
            'normal' => 0,
            'low' => 0
        ];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['priority']] = (int)$row['count'];
            $result['total'] += (int)$row['count'];
        }

        return $result;
    }

    /**
     * Marque une notification comme lue
     */
    public function markAsRead(int $notificationId, ?int $userId = null): bool
    {
        try {
            $sql = "UPDATE notifications SET is_read = TRUE WHERE id = ?";
            $params = [$notificationId];

            if ($userId !== null) {
                $sql .= " AND user_id = ?";
                $params[] = $userId;
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\Exception $e) {
            error_log("NotificationService::markAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Marque toutes les notifications d'un utilisateur comme lues
     */
    public function markAllAsRead(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications SET is_read = TRUE
                WHERE user_id = ? AND is_read = FALSE
            ");
            return $stmt->execute([$userId]);
        } catch (\Exception $e) {
            error_log("NotificationService::markAllAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprime une notification
     */
    public function delete(int $notificationId, ?int $userId = null): bool
    {
        try {
            $sql = "DELETE FROM notifications WHERE id = ?";
            $params = [$notificationId];

            if ($userId !== null) {
                $sql .= " AND user_id = ?";
                $params[] = $userId;
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\Exception $e) {
            error_log("NotificationService::delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifie les validateurs qu'un document est en attente de validation
     */
    public function notifyValidationPending(int $documentId, array $targetUserIds = []): int
    {
        // Récupérer les infos du document
        $stmt = $this->db->prepare("
            SELECT d.*, u.username as creator_username,
                   CONCAT(u.first_name, ' ', u.last_name) as creator_fullname
            FROM documents d
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return 0;
        }

        // Si pas de cibles spécifiées, trouver les validateurs potentiels
        if (empty($targetUserIds)) {
            $targetUserIds = $this->findValidators($document);
        }

        $count = 0;
        $title = $document['title'] ?: $document['original_filename'];
        $creator = $document['creator_fullname'] ?: $document['creator_username'];

        foreach ($targetUserIds as $userId) {
            $id = $this->create(
                $userId,
                self::TYPE_VALIDATION_PENDING,
                "Document à valider : {$title}",
                "Document soumis par {$creator}",
                "/documents/{$documentId}",
                [
                    'document_id' => $documentId,
                    'related_user_id' => $document['created_by'],
                    'priority' => self::PRIORITY_HIGH,
                    'action_url' => "/mes-taches"
                ]
            );
            if ($id) $count++;
        }

        return $count;
    }

    /**
     * Notifie le créateur du résultat de validation
     */
    public function notifyValidationResult(int $documentId, string $status, int $validatedBy): ?int
    {
        // Récupérer les infos du document
        $stmt = $this->db->prepare("
            SELECT d.*, u.username as validator_username,
                   CONCAT(u.first_name, ' ', u.last_name) as validator_fullname
            FROM documents d
            LEFT JOIN users u ON u.id = ?
            WHERE d.id = ?
        ");
        $stmt->execute([$validatedBy, $documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document || !$document['created_by']) {
            return null;
        }

        $title = $document['title'] ?: $document['original_filename'];
        $validator = $document['validator_fullname'] ?: $document['validator_username'];
        $isApproved = ($status === 'approved');

        return $this->create(
            $document['created_by'],
            $isApproved ? self::TYPE_VALIDATION_APPROVED : self::TYPE_VALIDATION_REJECTED,
            $isApproved ? "Document approuvé : {$title}" : "Document rejeté : {$title}",
            "Par {$validator}" . ($document['validation_comment'] ? " - " . $document['validation_comment'] : ''),
            "/documents/{$documentId}",
            [
                'document_id' => $documentId,
                'related_user_id' => $validatedBy,
                'priority' => $isApproved ? self::PRIORITY_NORMAL : self::PRIORITY_HIGH
            ]
        );
    }

    /**
     * Notifie un utilisateur d'une nouvelle note
     */
    public function notifyNoteReceived(int $noteId, int $fromUserId, int $toUserId, ?int $documentId = null, bool $actionRequired = false): ?int
    {
        // Récupérer les infos de l'expéditeur
        $stmt = $this->db->prepare("
            SELECT username, CONCAT(first_name, ' ', last_name) as fullname
            FROM users WHERE id = ?
        ");
        $stmt->execute([$fromUserId]);
        $sender = $stmt->fetch(\PDO::FETCH_ASSOC);

        $senderName = $sender['fullname'] ?: $sender['username'] ?: 'Utilisateur';

        return $this->create(
            $toUserId,
            $actionRequired ? self::TYPE_NOTE_ACTION_REQUIRED : self::TYPE_NOTE_RECEIVED,
            $actionRequired ? "Action requise de {$senderName}" : "Nouvelle note de {$senderName}",
            null,
            $documentId ? "/documents/{$documentId}" : "/mes-taches",
            [
                'document_id' => $documentId,
                'related_user_id' => $fromUserId,
                'priority' => $actionRequired ? self::PRIORITY_HIGH : self::PRIORITY_NORMAL,
                'action_url' => "/mes-taches"
            ]
        );
    }

    /**
     * Trouve les utilisateurs pouvant valider un document
     */
    private function findValidators(array $document): array
    {
        // Récupérer les utilisateurs avec un rôle de validation
        $stmt = $this->db->prepare("
            SELECT DISTINCT ur.user_id
            FROM user_roles ur
            JOIN role_types rt ON ur.role_type_id = rt.id
            WHERE rt.code IN ('VALIDATOR_L1', 'VALIDATOR_L2', 'APPROVER', 'ADMIN')
              AND (ur.scope = '*' OR ur.scope = ?)
              AND (ur.valid_from IS NULL OR ur.valid_from <= CURDATE())
              AND (ur.valid_to IS NULL OR ur.valid_to >= CURDATE())
        ");

        $docTypeCode = $document['document_type_code'] ?? '*';
        $stmt->execute([$docTypeCode]);

        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'user_id');
    }

    /**
     * Récupère une notification par ID
     */
    public function getById(int $notificationId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM notifications WHERE id = ?
        ");
        $stmt->execute([$notificationId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Nettoie les anciennes notifications (optionnel, pour maintenance)
     */
    public function cleanupOld(int $daysOld = 90): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM notifications
                WHERE is_read = TRUE
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysOld]);
            return $stmt->rowCount();
        } catch (\Exception $e) {
            error_log("NotificationService::cleanupOld error: " . $e->getMessage());
            return 0;
        }
    }
}
