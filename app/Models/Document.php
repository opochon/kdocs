<?php
/**
 * K-Docs - Modèle Document
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class Document
{
    /**
     * Récupère tous les documents avec pagination
     */
    public static function getAll(int $limit = 20, int $offset = 0): array
    {
        $db = Database::getInstance();
        
        try {
            $stmt = $db->prepare("
                SELECT d.*,
                       dt.label as document_type_label,
                       c.name as correspondent_name,
                       u.username as created_by_username
                FROM documents d
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                LEFT JOIN users u ON d.created_by = u.id
                ORDER BY d.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("Document::getAll - " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Compte le nombre total de documents
     */
    public static function count(): int
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT COUNT(*) FROM documents");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Récupère un document par ID
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT d.*,
                   dt.label as document_type_label,
                   c.name as correspondent_name,
                   u.username as created_by_username
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.id = :id
        ");
        
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        
        return $result ?: null;
    }

    /**
     * Crée un nouveau document
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            INSERT INTO documents (
                title, filename, original_filename, file_path, file_size, mime_type,
                document_type_id, correspondent_id, doc_date, amount, currency,
                created_by
            ) VALUES (
                :title, :filename, :original_filename, :file_path, :file_size, :mime_type,
                :document_type_id, :correspondent_id, :doc_date, :amount, :currency,
                :created_by
            )
        ");
        
        $stmt->execute([
            'title' => $data['title'] ?? null,
            'filename' => $data['filename'],
            'original_filename' => $data['original_filename'],
            'file_path' => $data['file_path'],
            'file_size' => $data['file_size'],
            'mime_type' => $data['mime_type'],
            'document_type_id' => $data['document_type_id'] ?? null,
            'correspondent_id' => $data['correspondent_id'] ?? null,
            'doc_date' => $data['doc_date'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'CHF',
            'created_by' => $data['created_by'],
        ]);
        
        return (int)$db->lastInsertId();
    }
    
    /**
     * Crée un document depuis un fichier
     */
    public static function createFromFile(string $filePath, array $data = []): int
    {
        if (!file_exists($filePath)) {
            throw new \Exception("Fichier introuvable: $filePath");
        }
        
        $basePath = \KDocs\Core\Config::get('storage.documents', 'C:\\wamp64\\www\\kdocs\\storage\\documents');
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }
        
        $originalFilename = $data['original_filename'] ?? basename($filePath);
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $targetPath = $basePath . DIRECTORY_SEPARATOR . $filename;
        
        // Copier le fichier
        if (!copy($filePath, $targetPath)) {
            throw new \Exception("Impossible de copier le fichier");
        }
        
        // Créer le document
        return self::create([
            'title' => $data['title'] ?? pathinfo($originalFilename, PATHINFO_FILENAME),
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'file_path' => $targetPath,
            'file_size' => filesize($targetPath),
            'mime_type' => $data['mime_type'] ?? mime_content_type($targetPath) ?: 'application/octet-stream',
            'document_type_id' => $data['document_type_id'] ?? null,
            'correspondent_id' => $data['correspondent_id'] ?? null,
            'doc_date' => $data['document_date'] ?? $data['doc_date'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'CHF',
            'created_by' => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Déplace un document dans la corbeille (ne supprime jamais définitivement)
     */
    public static function delete(int $id, int $userId): bool
    {
        // Delete embedding from vector store (delta sync)
        try {
            \KDocs\Jobs\EmbedDocumentJob::dispatchDelete($id);
        } catch (\Exception $e) {
            error_log("Failed to delete embedding on document delete: " . $e->getMessage());
        }

        $trash = new \KDocs\Services\TrashService();
        return $trash->moveToTrash($id, $userId);
    }
    
    /**
     * Restaure un document depuis la corbeille
     */
    public static function restore(int $id): bool
    {
        $trash = new \KDocs\Services\TrashService();
        return $trash->restoreFromTrash($id);
    }
}
