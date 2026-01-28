<?php
/**
 * K-Docs - TagLoader Helper
 * Batch loads tags for multiple documents to avoid N+1 queries
 */

namespace KDocs\Helpers;

use KDocs\Core\Database;
use PDO;

class TagLoader
{
    /**
     * Load tags for multiple documents in a single query
     *
     * @param array $documents Array of documents with 'id' key
     * @return array Documents with 'tags' key added
     */
    public static function loadForDocuments(array $documents): array
    {
        if (empty($documents)) {
            return $documents;
        }

        // Extract document IDs
        $documentIds = array_column($documents, 'id');
        if (empty($documentIds)) {
            return $documents;
        }

        // Fetch all tags for these documents in a single query
        $tags = self::getTagsForDocumentIds($documentIds);

        // Group tags by document ID
        $tagsByDocument = [];
        foreach ($tags as $tag) {
            $docId = $tag['document_id'];
            if (!isset($tagsByDocument[$docId])) {
                $tagsByDocument[$docId] = [];
            }
            $tagsByDocument[$docId][] = [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'color' => $tag['color']
            ];
        }

        // Attach tags to documents
        foreach ($documents as &$document) {
            $document['tags'] = $tagsByDocument[$document['id']] ?? [];
        }

        return $documents;
    }

    /**
     * Get tags for multiple document IDs
     *
     * @param array $documentIds
     * @return array
     */
    public static function getTagsForDocumentIds(array $documentIds): array
    {
        if (empty($documentIds)) {
            return [];
        }

        try {
            $db = Database::getInstance();

            // Create placeholders for IN clause
            $placeholders = str_repeat('?,', count($documentIds) - 1) . '?';

            $sql = "
                SELECT
                    dt.document_id,
                    t.id,
                    t.name,
                    t.color
                FROM document_tags dt
                INNER JOIN tags t ON dt.tag_id = t.id
                WHERE dt.document_id IN ($placeholders)
                ORDER BY t.name
            ";

            $stmt = $db->prepare($sql);
            $stmt->execute($documentIds);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table might not exist yet
            return [];
        }
    }

    /**
     * Get tag IDs for a single document
     *
     * @param int $documentId
     * @return array
     */
    public static function getTagIdsForDocument(int $documentId): array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT tag_id FROM document_tags WHERE document_id = ?");
            $stmt->execute([$documentId]);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'tag_id');
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get full tag data for a single document
     *
     * @param int $documentId
     * @return array
     */
    public static function getTagsForDocument(int $documentId): array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT t.id, t.name, t.color
                FROM tags t
                INNER JOIN document_tags dt ON t.id = dt.tag_id
                WHERE dt.document_id = ?
                ORDER BY t.name
            ");
            $stmt->execute([$documentId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Count documents per tag
     *
     * @return array [tag_id => count]
     */
    public static function getDocumentCountsPerTag(): array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query("
                SELECT tag_id, COUNT(*) as count
                FROM document_tags
                GROUP BY tag_id
            ");

            $counts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['tag_id']] = (int)$row['count'];
            }
            return $counts;
        } catch (\Exception $e) {
            return [];
        }
    }
}
