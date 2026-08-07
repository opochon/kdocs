<?php
/**
 * K-Docs - Service de gestion de la corbeille
 * Les documents ne sont jamais supprimés définitivement, ils vont dans le trash
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Exceptions\HardDeleteForbiddenException;
use KDocs\Jobs\EmbedDocumentJob;

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

            // Supprimer l'embedding du document dans Qdrant
            try {
                EmbedDocumentJob::dispatchDelete($documentId);
            } catch (\Exception $e) {
                // Ne pas bloquer si la suppression embedding échoue
                error_log("TrashService: Erreur suppression embedding: " . $e->getMessage());
            }

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
            $result = $stmt->execute([$documentId]);

            if ($result) {
                // Ré-indexer l'embedding du document restauré
                try {
                    $embeddingsEnabled = Config::get('embeddings.enabled', false);
                    if ($embeddingsEnabled) {
                        EmbedDocumentJob::dispatch($documentId);
                    }
                } catch (\Exception $e) {
                    error_log("restoreFromTrash: Erreur ré-indexation embedding: " . $e->getMessage());
                }
            }

            return $result;
        } catch (\Exception $e) {
            error_log("Erreur restauration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * DESACTIVEE — la suppression definitive n'existe plus dans K-Docs.
     *
     * Cette methode detruisait la ligne documents ET le fichier physique, sans
     * consulter legal_sealed ni retention_until, et sans ecrire de ligne d'audit.
     * Elle rompait la chaine de tracabilite : c'est precisement ce que l'invariant
     * de conception interdit.
     *
     * Un document supprime reste en corbeille indefiniment, marque par deleted_at.
     * La base est indexee : l'exclure des listings ne coute rien en performance.
     *
     * @throws HardDeleteForbiddenException toujours
     */
    public function deletePermanently(int $documentId): bool
    {
        throw HardDeleteForbiddenException::forRecord('documents', $documentId);
    }
    
    /**
     * DESACTIVEE — le vidage de corbeille n'existe plus dans K-Docs.
     *
     * Purgeait en masse tout document en corbeille depuis plus de N jours, via
     * deletePermanently(). Aucun garde-fou : ni legal_sealed, ni retention_until,
     * ni audit. La corbeille est desormais un etat durable, pas une antichambre
     * de la destruction.
     *
     * @throws HardDeleteForbiddenException toujours
     */
    public function emptyTrash(int $olderThanDays = 30): array
    {
        throw HardDeleteForbiddenException::forPurge('TrashService::emptyTrash');
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
