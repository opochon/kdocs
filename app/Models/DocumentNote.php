<?php
/**
 * K-Docs - Modèle DocumentNote
 * Notes sur les documents
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class DocumentNote
{
    public static function allForDocument(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT dn.*, u.username as user_name
            FROM document_notes dn
            LEFT JOIN users u ON dn.user_id = u.id
            WHERE dn.document_id = ?
            ORDER BY dn.created_at DESC
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }
    
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM document_notes WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO document_notes (document_id, user_id, note)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $data['document_id'],
            $data['user_id'] ?? null,
            $data['note']
        ]);
        return $db->lastInsertId();
    }
    
    public static function update(int $id, string $note): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("UPDATE document_notes SET note = ? WHERE id = ?");
        return $stmt->execute([$note, $id]);
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM document_notes WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
