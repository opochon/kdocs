<?php
/**
 * K-Docs - Service de gestion de la corbeille
 * Les documents ne sont jamais supprimés définitivement, ils vont dans le trash
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;

class TrashService
{
    private $db;
    private string $trashPath;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $config = Config::load();
        $this->trashPath = $config['storage']['trash'] ?? __DIR__ . '/../../storage/trash';
        
        // Créer le dossier trash s'il n'existe pas
        if (!is_dir($this->trashPath)) {
            @mkdir($this->trashPath, 0755, true);
        }
    }
    
    /**
     * Déplace un document dans la corbeille
     * 
     * @param int $documentId ID du document
     * @param int $userId ID de l'utilisateur qui supprime
     * @return bool Succès
     */
    public function moveToTrash(int $documentId, int $userId): bool
    {
        $document = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $document->execute([$documentId]);
        $doc = $document->fetch();
        
        if (!$doc) {
            return false;
        }
        
        // Vérifier si déjà dans le trash
        if ($doc['deleted_at'] !== null) {
            return true; // Déjà dans le trash
        }
        
        try {
            $this->db->beginTransaction();
            
            // Marquer comme supprimé dans la DB
            $stmt = $this->db->prepare("
                UPDATE documents 
                SET deleted_at = NOW(), deleted_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $documentId]);
            
            // Si le fichier existe et n'est pas dans le filesystem de base, le déplacer
            // (pour les fichiers uploadés via l'interface)
            if (!empty($doc['file_path']) && file_exists($doc['file_path'])) {
                // Utiliser Config::get pour récupérer base_path (inclut les settings DB)
                $basePath = Config::get('storage.base_path', '');
                
                // Si le fichier n'est pas dans le dossier de base, le déplacer vers le trash
                if ($basePath && strpos($doc['file_path'], $basePath) === false) {
                    $trashFilePath = $this->trashPath . '/' . $documentId . '_' . basename($doc['file_path']);
                    if (@rename($doc['file_path'], $trashFilePath)) {
                        $updateStmt = $this->db->prepare("UPDATE documents SET file_path = ? WHERE id = ?");
                        $updateStmt->execute([$trashFilePath, $documentId]);
                    }
                }
                // Sinon, le fichier reste dans le filesystem de base (on ne le supprime pas)
            }
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Erreur TrashService: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Restaure un document depuis la corbeille
     */
    public function restoreFromTrash(int $documentId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE documents 
                SET deleted_at = NULL, deleted_by = NULL
                WHERE id = ? AND deleted_at IS NOT NULL
            ");
            return $stmt->execute([$documentId]);
        } catch (\Exception $e) {
            error_log("Erreur restauration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprime définitivement un document (vide la corbeille)
     */
    public function deletePermanently(int $documentId): bool
    {
        $document = $this->db->prepare("SELECT * FROM documents WHERE id = ? AND deleted_at IS NOT NULL");
        $document->execute([$documentId]);
        $doc = $document->fetch();
        
        if (!$doc) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            // Supprimer le fichier physique s'il est dans le trash
            if (!empty($doc['file_path']) && file_exists($doc['file_path']) && strpos($doc['file_path'], $this->trashPath) !== false) {
                @unlink($doc['file_path']);
            }
            
            // Supprimer de la base de données
            $stmt = $this->db->prepare("DELETE FROM documents WHERE id = ?");
            $stmt->execute([$documentId]);
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Erreur suppression définitive: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vide la corbeille (supprime définitivement tous les documents supprimés)
     */
    public function emptyTrash(int $olderThanDays = 30): array
    {
        $stats = ['deleted' => 0, 'errors' => 0];
        
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM documents 
                WHERE deleted_at IS NOT NULL 
                AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$olderThanDays]);
            $documents = $stmt->fetchAll();
            
            foreach ($documents as $doc) {
                if ($this->deletePermanently($doc['id'])) {
                    $stats['deleted']++;
                } else {
                    $stats['errors']++;
                }
            }
            
        } catch (\Exception $e) {
            error_log("Erreur vidage corbeille: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Liste les documents dans la corbeille
     */
    public function getTrashedDocuments(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   dt.label as document_type_label,
                   c.name as correspondent_name,
                   u.username as deleted_by_username
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN users u ON d.deleted_by = u.id
            WHERE d.deleted_at IS NOT NULL
            ORDER BY d.deleted_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Compte les documents dans la corbeille
     */
    public function countTrashedDocuments(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NOT NULL");
        return (int)$stmt->fetchColumn();
    }
}
