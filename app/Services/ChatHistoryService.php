<?php
/**
 * K-Docs - ChatHistoryService
 * Gestion de l'historique des conversations de chat
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use PDO;

class ChatHistoryService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTablesExist();
    }

    /**
     * Create tables if they don't exist
     */
    private function ensureTablesExist(): void
    {
        try {
            $this->db->query("SELECT 1 FROM chat_conversations LIMIT 1");
        } catch (\Exception $e) {
            // Tables don't exist, create them
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS chat_conversations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    title VARCHAR(255) DEFAULT 'Nouvelle conversation',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    is_archived TINYINT(1) DEFAULT 0,
                    INDEX idx_user_updated (user_id, updated_at DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS chat_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    conversation_id INT NOT NULL,
                    role ENUM('user', 'assistant') NOT NULL,
                    content TEXT NOT NULL,
                    metadata JSON DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_conversation (conversation_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    /**
     * Get recent conversations for a user
     */
    public function getRecentConversations(int $userId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = c.id) as message_count,
                   (SELECT content FROM chat_messages WHERE conversation_id = c.id AND role = 'user' ORDER BY created_at ASC LIMIT 1) as first_message
            FROM chat_conversations c
            WHERE c.user_id = ? AND c.is_archived = 0
            ORDER BY c.updated_at DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single conversation with messages
     */
    public function getConversation(int $conversationId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM chat_conversations
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conversation) {
            return null;
        }

        // Get messages
        $stmt = $this->db->prepare("
            SELECT * FROM chat_messages
            WHERE conversation_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$conversationId]);
        $conversation['messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $conversation;
    }

    /**
     * Create a new conversation
     */
    public function createConversation(int $userId, string $title = 'Nouvelle conversation'): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO chat_conversations (user_id, title) VALUES (?, ?)
        ");
        $stmt->execute([$userId, $title]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Add a message to a conversation
     */
    public function addMessage(int $conversationId, string $role, string $content, ?array $metadata = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO chat_messages (conversation_id, role, content, metadata)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $conversationId,
            $role,
            $content,
            $metadata ? json_encode($metadata) : null
        ]);

        // Update conversation timestamp and title if first user message
        $this->db->prepare("UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);

        // Auto-generate title from first user message
        if ($role === 'user') {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ? AND role = 'user'
            ");
            $stmt->execute([$conversationId]);
            if ((int) $stmt->fetchColumn() === 1) {
                $title = $this->generateTitle($content);
                $this->updateTitle($conversationId, $title);
            }
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Generate a title from the first message
     */
    private function generateTitle(string $content): string
    {
        // Take first 50 chars, cut at word boundary
        $title = mb_substr($content, 0, 50);
        if (mb_strlen($content) > 50) {
            $lastSpace = mb_strrpos($title, ' ');
            if ($lastSpace > 20) {
                $title = mb_substr($title, 0, $lastSpace);
            }
            $title .= '...';
        }
        return $title;
    }

    /**
     * Update conversation title
     */
    public function updateTitle(int $conversationId, string $title): bool
    {
        $stmt = $this->db->prepare("UPDATE chat_conversations SET title = ? WHERE id = ?");
        return $stmt->execute([$title, $conversationId]);
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM chat_conversations WHERE id = ? AND user_id = ?");
        return $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Archive a conversation
     */
    public function archiveConversation(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE chat_conversations SET is_archived = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$conversationId, $userId]);
    }

    /**
     * Get or create the last conversation for a user
     */
    public function getOrCreateLastConversation(int $userId): array
    {
        $conversations = $this->getRecentConversations($userId, 1);

        if (!empty($conversations)) {
            return $this->getConversation($conversations[0]['id'], $userId);
        }

        // Create new conversation
        $id = $this->createConversation($userId);
        return $this->getConversation($id, $userId);
    }
}
