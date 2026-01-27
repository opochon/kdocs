<?php
/**
 * K-Docs - Service de gestion des dossiers filesystem
 * Gère renommage, déplacement, suppression (vers trash) avec traçabilité
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;

class FolderService
{
    private $db;
    private string $basePath;
    private string $trashPath;
    private AuditService $auditService;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        
        $fsReader = new FilesystemReader();
        $this->basePath = $fsReader->getBasePath();
        
        // Dossier trash pour les dossiers supprimés
        $this->trashPath = dirname($this->basePath) . DIRECTORY_SEPARATOR . 'trash';
        if (!is_dir($this->trashPath)) {
            @mkdir($this->trashPath, 0755, true);
        }
        
        $this->auditService = new AuditService();
    }
    
    /**
     * Renomme un dossier
     * Met à jour tous les documents en DB qui référencent ce chemin
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @param string $newName Nouveau nom du dossier
     * @param int $userId ID de l'utilisateur
     * @return array Résultat avec success, message, etc.
     */
    public function rename(string $relativePath, string $newName, int $userId): array
    {
        $relativePath = trim($relativePath, '/');
        $newName = trim($newName);
        
        // Validation
        if (empty($relativePath)) {
            return ['success' => false, 'error' => 'Impossible de renommer la racine'];
        }
        
        if (empty($newName) || preg_match('/[\/\\\\:*?"<>|]/', $newName)) {
            return ['success' => false, 'error' => 'Nom de dossier invalide'];
        }
        
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        
        if (!is_dir($fullPath)) {
            return ['success' => false, 'error' => 'Dossier inexistant'];
        }
        
        // Calculer le nouveau chemin
        $parentPath = dirname($relativePath);
        $parentPath = ($parentPath === '.' || $parentPath === '') ? '' : $parentPath;
        $newRelativePath = $parentPath ? $parentPath . '/' . $newName : $newName;
        $newFullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRelativePath);
        
        // Vérifier que le nouveau nom n'existe pas déjà
        if (file_exists($newFullPath)) {
            return ['success' => false, 'error' => 'Un dossier avec ce nom existe déjà'];
        }
        
        $oldName = basename($relativePath);
        
        try {
            $this->db->beginTransaction();
            
            // Renommer le dossier physique
            if (!@rename($fullPath, $newFullPath)) {
                throw new \Exception('Impossible de renommer le dossier');
            }
            
            // Mettre à jour tous les documents qui référencent ce chemin
            $documentsUpdated = $this->updateDocumentPaths($relativePath, $newRelativePath);
            
            // Log d'audit
            $this->auditService->log('folder_rename', [
                'old_path' => $relativePath,
                'new_path' => $newRelativePath,
                'old_name' => $oldName,
                'new_name' => $newName,
                'documents_updated' => $documentsUpdated,
                'user_id' => $userId
            ], $userId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Dossier renommé: $oldName → $newName",
                'old_path' => $relativePath,
                'new_path' => $newRelativePath,
                'documents_updated' => $documentsUpdated
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            // Essayer de restaurer le nom original si le rename physique a réussi
            if (is_dir($newFullPath) && !is_dir($fullPath)) {
                @rename($newFullPath, $fullPath);
            }
            
            error_log("FolderService::rename error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Déplace un dossier vers un autre emplacement
     * 
     * @param string $sourcePath Chemin relatif source
     * @param string $targetPath Chemin relatif cible (dossier parent de destination)
     * @param int $userId ID de l'utilisateur
     * @return array Résultat
     */
    public function move(string $sourcePath, string $targetPath, int $userId): array
    {
        $sourcePath = trim($sourcePath, '/');
        $targetPath = trim($targetPath, '/');
        
        if (empty($sourcePath)) {
            return ['success' => false, 'error' => 'Impossible de déplacer la racine'];
        }
        
        $sourceFullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourcePath);
        $targetFullPath = $this->basePath . ($targetPath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetPath) : '');
        
        if (!is_dir($sourceFullPath)) {
            return ['success' => false, 'error' => 'Dossier source inexistant'];
        }
        
        if (!is_dir($targetFullPath)) {
            return ['success' => false, 'error' => 'Dossier cible inexistant'];
        }
        
        // Empêcher le déplacement dans soi-même ou un sous-dossier
        if ($targetPath === $sourcePath || strpos($targetPath . '/', $sourcePath . '/') === 0) {
            return ['success' => false, 'error' => 'Impossible de déplacer un dossier dans lui-même'];
        }
        
        $folderName = basename($sourcePath);
        $newRelativePath = $targetPath ? $targetPath . '/' . $folderName : $folderName;
        $newFullPath = $targetFullPath . DIRECTORY_SEPARATOR . $folderName;
        
        // Vérifier que la destination n'existe pas
        if (file_exists($newFullPath)) {
            return ['success' => false, 'error' => 'Un dossier avec ce nom existe déjà à la destination'];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Déplacer le dossier physique
            if (!@rename($sourceFullPath, $newFullPath)) {
                throw new \Exception('Impossible de déplacer le dossier');
            }
            
            // Mettre à jour tous les documents
            $documentsUpdated = $this->updateDocumentPaths($sourcePath, $newRelativePath);
            
            // Log d'audit
            $this->auditService->log('folder_move', [
                'source_path' => $sourcePath,
                'target_path' => $targetPath,
                'new_path' => $newRelativePath,
                'documents_updated' => $documentsUpdated,
                'user_id' => $userId
            ], $userId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Dossier déplacé vers: $targetPath",
                'old_path' => $sourcePath,
                'new_path' => $newRelativePath,
                'documents_updated' => $documentsUpdated
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            // Restaurer si possible
            if (is_dir($newFullPath) && !is_dir($sourceFullPath)) {
                @rename($newFullPath, $sourceFullPath);
            }
            
            error_log("FolderService::move error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Supprime un dossier (déplace vers trash)
     * AUCUNE suppression définitive - tout va dans le trash
     * 
     * @param string $relativePath Chemin relatif du dossier
     * @param int $userId ID de l'utilisateur
     * @return array Résultat
     */
    public function moveToTrash(string $relativePath, int $userId): array
    {
        $relativePath = trim($relativePath, '/');
        
        if (empty($relativePath)) {
            return ['success' => false, 'error' => 'Impossible de supprimer la racine'];
        }
        
        $fullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        
        if (!is_dir($fullPath)) {
            return ['success' => false, 'error' => 'Dossier inexistant'];
        }
        
        // Compter les fichiers et sous-dossiers
        $stats = $this->countFolderContents($fullPath);
        
        try {
            $this->db->beginTransaction();
            
            // Créer le chemin dans le trash avec timestamp pour éviter les conflits
            $timestamp = date('Ymd_His');
            $trashRelativePath = $relativePath . '_deleted_' . $timestamp;
            $trashFullPath = $this->trashPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $trashRelativePath);
            
            // S'assurer que le dossier parent existe dans le trash
            $trashParent = dirname($trashFullPath);
            if (!is_dir($trashParent)) {
                @mkdir($trashParent, 0755, true);
            }
            
            // Déplacer le dossier vers le trash
            if (!@rename($fullPath, $trashFullPath)) {
                throw new \Exception('Impossible de déplacer le dossier vers la corbeille');
            }
            
            // Marquer les documents comme supprimés dans la DB
            $documentsMarked = $this->markDocumentsAsDeleted($relativePath, $userId, $trashRelativePath);
            
            // Enregistrer dans la table de trash pour les dossiers
            $this->recordFolderTrash($relativePath, $trashRelativePath, $userId, $stats);
            
            // Log d'audit
            $this->auditService->log('folder_trash', [
                'original_path' => $relativePath,
                'trash_path' => $trashRelativePath,
                'file_count' => $stats['files'],
                'folder_count' => $stats['folders'],
                'documents_marked' => $documentsMarked,
                'user_id' => $userId
            ], $userId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Dossier déplacé vers la corbeille",
                'original_path' => $relativePath,
                'trash_path' => $trashRelativePath,
                'stats' => $stats,
                'documents_affected' => $documentsMarked
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            // Restaurer si possible
            if (isset($trashFullPath) && is_dir($trashFullPath) && !is_dir($fullPath)) {
                @rename($trashFullPath, $fullPath);
            }
            
            error_log("FolderService::moveToTrash error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Restaure un dossier depuis le trash
     */
    public function restoreFromTrash(int $trashId, int $userId): array
    {
        try {
            // Récupérer les infos du trash
            $stmt = $this->db->prepare("SELECT * FROM folder_trash WHERE id = ? AND restored_at IS NULL");
            $stmt->execute([$trashId]);
            $trash = $stmt->fetch();
            
            if (!$trash) {
                return ['success' => false, 'error' => 'Dossier non trouvé dans la corbeille'];
            }
            
            $trashFullPath = $this->trashPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $trash['trash_path']);
            $originalFullPath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $trash['original_path']);
            
            if (!is_dir($trashFullPath)) {
                return ['success' => false, 'error' => 'Dossier physique non trouvé dans la corbeille'];
            }
            
            // Vérifier que la destination n'existe pas
            if (file_exists($originalFullPath)) {
                return ['success' => false, 'error' => 'Un dossier existe déjà à l\'emplacement original'];
            }
            
            $this->db->beginTransaction();
            
            // S'assurer que le dossier parent existe
            $parentPath = dirname($originalFullPath);
            if (!is_dir($parentPath)) {
                @mkdir($parentPath, 0755, true);
            }
            
            // Restaurer le dossier physique
            if (!@rename($trashFullPath, $originalFullPath)) {
                throw new \Exception('Impossible de restaurer le dossier');
            }
            
            // Restaurer les documents dans la DB
            $this->restoreDocumentsFromTrash($trash['original_path'], $trash['trash_path']);
            
            // Marquer comme restauré
            $stmt = $this->db->prepare("UPDATE folder_trash SET restored_at = NOW(), restored_by = ? WHERE id = ?");
            $stmt->execute([$userId, $trashId]);
            
            // Log d'audit
            $this->auditService->log('folder_restore', [
                'original_path' => $trash['original_path'],
                'trash_path' => $trash['trash_path'],
                'user_id' => $userId
            ], $userId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Dossier restauré',
                'restored_path' => $trash['original_path']
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("FolderService::restoreFromTrash error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Liste les dossiers dans le trash
     */
    public function getTrashedFolders(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT ft.*, u.username as deleted_by_username
            FROM folder_trash ft
            LEFT JOIN users u ON ft.deleted_by = u.id
            WHERE ft.restored_at IS NULL
            ORDER BY ft.deleted_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Compte les dossiers dans le trash
     */
    public function countTrashedFolders(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM folder_trash WHERE restored_at IS NULL");
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Crée un nouveau dossier
     */
    public function create(string $parentPath, string $name, int $userId): array
    {
        $parentPath = trim($parentPath, '/');
        $name = trim($name);
        
        if (empty($name) || preg_match('/[\/\\\\:*?"<>|]/', $name)) {
            return ['success' => false, 'error' => 'Nom de dossier invalide'];
        }
        
        $parentFullPath = $this->basePath . ($parentPath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $parentPath) : '');
        
        if (!is_dir($parentFullPath)) {
            return ['success' => false, 'error' => 'Dossier parent inexistant'];
        }
        
        $newPath = $parentPath ? $parentPath . '/' . $name : $name;
        $newFullPath = $parentFullPath . DIRECTORY_SEPARATOR . $name;
        
        if (file_exists($newFullPath)) {
            return ['success' => false, 'error' => 'Un dossier avec ce nom existe déjà'];
        }
        
        if (!@mkdir($newFullPath, 0755)) {
            return ['success' => false, 'error' => 'Impossible de créer le dossier'];
        }
        
        // Log d'audit
        $this->auditService->log('folder_create', [
            'path' => $newPath,
            'name' => $name,
            'user_id' => $userId
        ], $userId);
        
        return [
            'success' => true,
            'message' => "Dossier créé: $name",
            'path' => $newPath
        ];
    }
    
    /**
     * Liste tous les dossiers (pour le sélecteur de destination)
     */
    public function getAllFolders(string $excludePath = ''): array
    {
        $folders = [];
        $this->scanFoldersRecursive($this->basePath, '', $folders, $excludePath);
        return $folders;
    }
    
    // === Méthodes privées ===
    
    /**
     * Met à jour les chemins des documents après renommage/déplacement
     */
    private function updateDocumentPaths(string $oldPath, string $newPath): int
    {
        $count = 0;
        
        // Mettre à jour relative_path
        $oldPrefix = $oldPath . '/';
        $newPrefix = $newPath . '/';
        
        // Documents directement dans le dossier
        $stmt = $this->db->prepare("
            UPDATE documents 
            SET relative_path = ?,
                file_path = REPLACE(file_path, ?, ?),
                updated_at = NOW()
            WHERE relative_path = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$newPath, str_replace('/', DIRECTORY_SEPARATOR, $oldPath), str_replace('/', DIRECTORY_SEPARATOR, $newPath), $oldPath]);
        $count += $stmt->rowCount();
        
        // Documents dans les sous-dossiers
        $stmt = $this->db->prepare("
            UPDATE documents 
            SET relative_path = CONCAT(?, SUBSTRING(relative_path, ?)),
                file_path = REPLACE(file_path, ?, ?),
                updated_at = NOW()
            WHERE relative_path LIKE ? AND deleted_at IS NULL
        ");
        $stmt->execute([
            $newPrefix, 
            strlen($oldPrefix) + 1,
            str_replace('/', DIRECTORY_SEPARATOR, $oldPath),
            str_replace('/', DIRECTORY_SEPARATOR, $newPath),
            $oldPrefix . '%'
        ]);
        $count += $stmt->rowCount();
        
        return $count;
    }
    
    /**
     * Marque les documents comme supprimés
     */
    private function markDocumentsAsDeleted(string $relativePath, int $userId, string $trashPath): int
    {
        $stmt = $this->db->prepare("
            UPDATE documents 
            SET deleted_at = NOW(), 
                deleted_by = ?,
                relative_path = CONCAT('_trash_/', relative_path)
            WHERE (relative_path = ? OR relative_path LIKE ?) 
            AND deleted_at IS NULL
        ");
        $stmt->execute([$userId, $relativePath, $relativePath . '/%']);
        return $stmt->rowCount();
    }
    
    /**
     * Restaure les documents depuis le trash
     */
    private function restoreDocumentsFromTrash(string $originalPath, string $trashPath): int
    {
        $stmt = $this->db->prepare("
            UPDATE documents 
            SET deleted_at = NULL, 
                deleted_by = NULL,
                relative_path = REPLACE(relative_path, '_trash_/', '')
            WHERE relative_path LIKE ? AND deleted_at IS NOT NULL
        ");
        $stmt->execute(['_trash_/' . $originalPath . '%']);
        return $stmt->rowCount();
    }
    
    /**
     * Enregistre le dossier dans la table folder_trash
     */
    private function recordFolderTrash(string $originalPath, string $trashPath, int $userId, array $stats): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO folder_trash (original_path, trash_path, deleted_by, deleted_at, file_count, folder_count)
            VALUES (?, ?, ?, NOW(), ?, ?)
        ");
        $stmt->execute([$originalPath, $trashPath, $userId, $stats['files'], $stats['folders']]);
    }
    
    /**
     * Compte les fichiers et sous-dossiers d'un dossier
     */
    private function countFolderContents(string $fullPath): array
    {
        $files = 0;
        $folders = 0;
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $folders++;
            } else {
                $files++;
            }
        }
        
        return ['files' => $files, 'folders' => $folders];
    }
    
    /**
     * Scanne les dossiers récursivement
     */
    private function scanFoldersRecursive(string $basePath, string $relativePath, array &$folders, string $excludePath, int $depth = 0): void
    {
        if ($depth > 10) return;
        
        $fullPath = $basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        // Exclure le dossier spécifié et ses enfants
        if ($excludePath && ($relativePath === $excludePath || strpos($relativePath . '/', $excludePath . '/') === 0)) {
            return;
        }
        
        $folders[] = [
            'path' => $relativePath ?: '/',
            'name' => $relativePath ? basename($relativePath) : 'Racine',
            'depth' => $depth
        ];
        
        $items = @scandir($fullPath);
        if ($items === false) return;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') continue;
            
            $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $subPath = $relativePath ? $relativePath . '/' . $item : $item;
                $this->scanFoldersRecursive($basePath, $subPath, $folders, $excludePath, $depth + 1);
            }
        }
    }
}
