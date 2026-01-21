<?php
/**
 * K-Docs - Modèle pour les recherches sauvegardées (Priorité 3.2)
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class SavedSearch
{
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO saved_searches (user_id, name, query, filters, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['query'] ?? '',
            json_encode($data['filters'] ?? [])
        ]);
        return (int)$db->lastInsertId();
    }
    
    public static function findByUser(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM saved_searches WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        if ($result && !empty($result['filters'])) {
            $result['filters'] = json_decode($result['filters'], true);
        }
        return $result ?: null;
    }
    
    public static function delete(int $id, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM saved_searches WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}
