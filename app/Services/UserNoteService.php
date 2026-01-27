<?php
/**
 * K-Docs - UserNoteService
 * Service pour les notes inter-utilisateurs
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class UserNoteService
{
    private $db;
    private $notificationService;

    // Types d'action possibles
    const ACTION_CONTACT = 'contact';
    const ACTION_REVIEW = 'review';
    const ACTION_APPROVE = 'approve';
    const ACTION_FOLLOW_UP = 'follow_up';
    const ACTION_OTHER = 'other';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->notificationService = new NotificationService();
    }

    /**
     * Envoie une note à un utilisateur
     */
    public function sendNote(
        int $fromUserId,
        int $toUserId,
        string $message,
        ?int $documentId = null,
        array $options = []
    ): array {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_notes
                (from_user_id, to_user_id, document_id, subject, message, parent_note_id, action_required, action_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $fromUserId,
                $toUserId,
                $documentId,
                $options['subject'] ?? null,
                $message,
                $options['parent_note_id'] ?? null,
                $options['action_required'] ?? false,
                $options['action_type'] ?? null
            ]);

            $noteId = (int)$this->db->lastInsertId();

            // Créer une notification pour le destinataire
            $this->notificationService->notifyNoteReceived(
                $noteId,
                $fromUserId,
                $toUserId,
                $documentId,
                $options['action_required'] ?? false
            );

            return [
                'success' => true,
                'note_id' => $noteId
            ];
        } catch (\Exception $e) {
            error_log("UserNoteService::sendNote error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Répond à une note existante
     */
    public function reply(int $parentNoteId, int $fromUserId, string $message): array
    {
        // Récupérer la note parent pour obtenir les infos
        $parent = $this->getById($parentNoteId);
        if (!$parent) {
            return ['success' => false, 'error' => 'Note parent non trouvée'];
        }

        // Le destinataire de la réponse est l'expéditeur original
        $toUserId = ($parent['from_user_id'] == $fromUserId)
            ? $parent['to_user_id']
            : $parent['from_user_id'];

        return $this->sendNote(
            $fromUserId,
            $toUserId,
            $message,
            $parent['document_id'],
            [
                'parent_note_id' => $parentNoteId,
                'subject' => 'Re: ' . ($parent['subject'] ?? 'Note')
            ]
        );
    }

    /**
     * Récupère les notes reçues par un utilisateur
     */
    public function getNotesForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $whereClause = $unreadOnly ? "AND un.is_read = FALSE" : "";

        $stmt = $this->db->prepare("
            SELECT
                un.*,
                sender.username as from_username,
                CONCAT(sender.first_name, ' ', sender.last_name) as from_fullname,
                d.title as document_title,
                d.original_filename as document_filename,
                (SELECT COUNT(*) FROM user_notes replies WHERE replies.parent_note_id = un.id) as reply_count
            FROM user_notes un
            LEFT JOIN users sender ON un.from_user_id = sender.id
            LEFT JOIN documents d ON un.document_id = d.id
            WHERE un.to_user_id = ?
              AND un.parent_note_id IS NULL
              {$whereClause}
            ORDER BY un.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Récupère les notes envoyées par un utilisateur
     */
    public function getSentNotes(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT
                un.*,
                recipient.username as to_username,
                CONCAT(recipient.first_name, ' ', recipient.last_name) as to_fullname,
                d.title as document_title,
                d.original_filename as document_filename
            FROM user_notes un
            LEFT JOIN users recipient ON un.to_user_id = recipient.id
            LEFT JOIN documents d ON un.document_id = d.id
            WHERE un.from_user_id = ?
              AND un.parent_note_id IS NULL
            ORDER BY un.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Récupère la conversation (thread) d'une note
     */
    public function getThread(int $noteId): array
    {
        // D'abord, trouver la note racine
        $rootNote = $this->findRootNote($noteId);
        $rootId = $rootNote['id'] ?? $noteId;

        // Récupérer la note racine et toutes les réponses
        $stmt = $this->db->prepare("
            SELECT
                un.*,
                sender.username as from_username,
                CONCAT(sender.first_name, ' ', sender.last_name) as from_fullname
            FROM user_notes un
            LEFT JOIN users sender ON un.from_user_id = sender.id
            WHERE un.id = ? OR un.parent_note_id = ?
            ORDER BY un.created_at ASC
        ");
        $stmt->execute([$rootId, $rootId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Récupère toutes les notes liées à un document
     */
    public function getThreadForDocument(int $documentId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                un.*,
                sender.username as from_username,
                CONCAT(sender.first_name, ' ', sender.last_name) as from_fullname,
                recipient.username as to_username,
                CONCAT(recipient.first_name, ' ', recipient.last_name) as to_fullname
            FROM user_notes un
            LEFT JOIN users sender ON un.from_user_id = sender.id
            LEFT JOIN users recipient ON un.to_user_id = recipient.id
            WHERE un.document_id = ?
            ORDER BY un.created_at ASC
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Marque une note comme lue
     */
    public function markAsRead(int $noteId, ?int $userId = null): bool
    {
        try {
            $sql = "UPDATE user_notes SET is_read = TRUE WHERE id = ?";
            $params = [$noteId];

            if ($userId !== null) {
                $sql .= " AND to_user_id = ?";
                $params[] = $userId;
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\Exception $e) {
            error_log("UserNoteService::markAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Marque toutes les notes d'un utilisateur comme lues
     */
    public function markAllAsRead(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_notes SET is_read = TRUE
                WHERE to_user_id = ? AND is_read = FALSE
            ");
            return $stmt->execute([$userId]);
        } catch (\Exception $e) {
            error_log("UserNoteService::markAllAsRead error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Marque l'action d'une note comme terminée
     */
    public function markActionCompleted(int $noteId, ?int $userId = null): bool
    {
        try {
            $sql = "UPDATE user_notes SET action_completed_at = NOW() WHERE id = ? AND action_required = TRUE";
            $params = [$noteId];

            if ($userId !== null) {
                $sql .= " AND to_user_id = ?";
                $params[] = $userId;
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\Exception $e) {
            error_log("UserNoteService::markActionCompleted error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère les notes avec action en attente pour un utilisateur
     */
    public function getPendingActionsForUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT
                un.*,
                sender.username as from_username,
                CONCAT(sender.first_name, ' ', sender.last_name) as from_fullname,
                d.title as document_title,
                d.original_filename as document_filename
            FROM user_notes un
            LEFT JOIN users sender ON un.from_user_id = sender.id
            LEFT JOIN documents d ON un.document_id = d.id
            WHERE un.to_user_id = ?
              AND un.action_required = TRUE
              AND un.action_completed_at IS NULL
            ORDER BY un.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Compte les notes non lues pour un utilisateur
     */
    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_notes
            WHERE to_user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Compte les actions en attente pour un utilisateur
     */
    public function getPendingActionCount(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_notes
            WHERE to_user_id = ?
              AND action_required = TRUE
              AND action_completed_at IS NULL
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère une note par ID
     */
    public function getById(int $noteId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                un.*,
                sender.username as from_username,
                CONCAT(sender.first_name, ' ', sender.last_name) as from_fullname,
                recipient.username as to_username,
                CONCAT(recipient.first_name, ' ', recipient.last_name) as to_fullname,
                d.title as document_title,
                d.original_filename as document_filename
            FROM user_notes un
            LEFT JOIN users sender ON un.from_user_id = sender.id
            LEFT JOIN users recipient ON un.to_user_id = recipient.id
            LEFT JOIN documents d ON un.document_id = d.id
            WHERE un.id = ?
        ");
        $stmt->execute([$noteId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Trouve la note racine d'un thread
     */
    private function findRootNote(int $noteId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM user_notes WHERE id = ?");
        $stmt->execute([$noteId]);
        $note = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$note) return null;
        if (!$note['parent_note_id']) return $note;

        return $this->findRootNote($note['parent_note_id']);
    }

    /**
     * Supprime une note (soft delete ou hard delete selon config)
     */
    public function delete(int $noteId, ?int $userId = null): bool
    {
        try {
            $sql = "DELETE FROM user_notes WHERE id = ?";
            $params = [$noteId];

            if ($userId !== null) {
                // L'utilisateur ne peut supprimer que les notes qu'il a envoyées
                $sql .= " AND from_user_id = ?";
                $params[] = $userId;
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params) && $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            error_log("UserNoteService::delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Liste tous les utilisateurs pouvant recevoir des notes
     */
    public function getAvailableRecipients(?int $excludeUserId = null): array
    {
        $sql = "
            SELECT id, username, CONCAT(first_name, ' ', last_name) as fullname, email
            FROM users
            WHERE is_active = TRUE
        ";
        $params = [];

        if ($excludeUserId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }

        $sql .= " ORDER BY first_name, last_name, username";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
