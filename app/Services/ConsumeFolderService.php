<?php
namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;

class ConsumeFolderService
{
    private string $consumePath;
    private string $processedPath;
    private string $documentsPath;
    private $db;
    
    public function __construct()
    {
        $config = Config::load();
        $base = dirname(__DIR__, 2) . '/storage';
        
        $this->consumePath = $config['storage']['consume'] ?? $base . '/consume';
        $this->processedPath = $config['storage']['processed'] ?? $base . '/processed';
        $this->documentsPath = $config['storage']['documents'] ?? $base . '/documents';
        $this->db = Database::getInstance();
        
        foreach ([$this->consumePath, $this->processedPath, $this->documentsPath] as $p) {
            if (!is_dir($p)) @mkdir($p, 0755, true);
        }
    }
    
    public function scan(): array
    {
        $results = ['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'reprocessed' => 0, 'errors' => [], 'documents' => []];
        
        if (!is_dir($this->consumePath)) {
            $results['errors'][] = "Dossier inexistant: {$this->consumePath}";
            return $results;
        }
        
        $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'gif', 'webp'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->consumePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            $results['scanned']++;
            
            $path = $file->getPathname();
            $ext = strtolower($file->getExtension());
            
            if (!in_array($ext, $allowed)) { $results['skipped']++; continue; }
            
            $checksum = md5_file($path);
            
            // Vérifier si le document existe déjà
            $stmt = $this->db->prepare("SELECT id, status FROM documents WHERE checksum = ?");
            $stmt->execute([$checksum]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Si le document est déjà validé, ignorer et déplacer vers processed
                if ($existing['status'] === 'validated') {
                    $results['skipped']++;
                    $this->moveToProcessed($path);
                    continue;
                }
                
                // Si le document existe mais n'est pas validé, le retraiter
                // Supprimer l'ancien document et réimporter
                try {
                    $this->db->prepare("DELETE FROM documents WHERE id = ?")->execute([$existing['id']]);
                    $this->db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$existing['id']]);
                    $results['reprocessed']++;
                } catch (\Exception $e) {
                    error_log("Erreur suppression document existant: " . $e->getMessage());
                }
            }
            
            try {
                $subfolder = trim(str_replace($this->consumePath, '', dirname($path)), '/\\') ?: null;
                $doc = $this->importFile($path, $subfolder);
                $results['imported']++;
                $results['documents'][] = $doc;
            } catch (\Exception $e) {
                $results['errors'][] = basename($path) . ": " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    private function importFile(string $path, ?string $subfolder): array
    {
        $filename = basename($path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $unique = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        
        // Créer le dossier "toclassify" pour les fichiers non triés
        $toclassifyPath = $this->documentsPath . '/toclassify';
        if (!is_dir($toclassifyPath)) {
            @mkdir($toclassifyPath, 0755, true);
        }
        
        // Placer le fichier dans toclassify en attendant la validation
        $dest = $toclassifyPath . '/' . $unique;
        
        if (!copy($path, $dest)) throw new \Exception("Copie impossible");
        
        $stmt = $this->db->prepare("
            INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, consume_subfolder, uploaded_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            pathinfo($filename, PATHINFO_FILENAME),
            $unique, $filename, $dest,
            filesize($dest),
            mime_content_type($dest) ?: 'application/octet-stream',
            md5_file($dest),
            $subfolder
        ]);
        
        $docId = $this->db->lastInsertId();
        
        // NE PAS déplacer vers processed ici - seulement après validation
        // Le fichier reste dans consume jusqu'à validation
        
        $result = ['id' => $docId, 'filename' => $filename, 'status' => 'imported'];
        
        try {
            // OCR
            $processor = new DocumentProcessor();
            $processor->process($docId);
            
            // Classification (selon mode configuré)
            $classifier = new ClassificationService();
            $classification = $classifier->classify($docId);
            
            // Sauvegarder suggestions
            $this->db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?")
                ->execute([json_encode($classification), $docId]);
            
            $result['classification'] = $classification;
            $result['status'] = $classification['auto_applied'] ? 'auto_validated' : ($classification['should_review'] ? 'needs_review' : 'pending');
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            error_log("Erreur traitement document {$docId}: " . $e->getMessage());
        }
        
        return $result;
    }
    
    private function moveToProcessed(string $path): bool
    {
        return @rename($path, $this->processedPath . '/' . date('Ymd_His') . '_' . basename($path));
    }
    
    public function getPendingDocuments(): array
    {
        return $this->db->query("
            SELECT d.*, c.name as correspondent_name, dt.label as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.status IN ('pending', 'needs_review')
            ORDER BY d.created_at DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function validateDocument(int $id, array $data): bool
    {
        $doc = $this->getDocument($id);
        if (!$doc) {
            throw new \Exception("Document introuvable: {$id}");
        }
        
        $sets = ['status = ?']; $params = ['validated'];
        foreach (['title', 'correspondent_id', 'document_type_id', 'doc_date', 'amount'] as $f) {
            if (isset($data[$f])) { $sets[] = "$f = ?"; $params[] = $data[$f] ?: null; }
        }
        
        // Gérer le chemin de stockage
        $storagePathOption = $data['storage_path_option'] ?? 'suggested';
        $targetPath = null;
        
        if ($storagePathOption === 'custom' && !empty($data['storage_path_custom'])) {
            // Créer le chemin personnalisé
            $targetPath = $data['storage_path_custom'];
            $this->createAndMoveToPath($id, $targetPath);
        } elseif ($storagePathOption === 'existing' && !empty($data['storage_path_id'])) {
            $sets[] = 'storage_path_id = ?';
            $params[] = (int)$data['storage_path_id'];
            
            // Récupérer le chemin du storage_path
            $stmt = $this->db->prepare("SELECT path FROM storage_paths WHERE id = ?");
            $stmt->execute([$data['storage_path_id']]);
            $sp = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($sp) {
                $targetPath = $sp['path'];
                $this->createAndMoveToPath($id, $targetPath);
            }
        } elseif ($storagePathOption === 'suggested') {
            // Générer le chemin suggéré avec StoragePathGenerator
            // Fusionner les données du document avec les données du formulaire
            $docData = array_merge($doc, $data);
            $docData['uploaded_at'] = $doc['created_at'] ?? null;
            
            $pathGenerator = new \KDocs\Services\StoragePathGenerator();
            $targetPath = $pathGenerator->generatePath($docData);
            
            if (!empty($targetPath)) {
                $this->createAndMoveToPath($id, $targetPath);
            }
        }
        
        // Si aucun chemin n'a été défini, créer un dossier "Unsorted" ou "Non triés"
        if (empty($targetPath) && strpos($doc['file_path'], '/toclassify/') !== false) {
            $targetPath = 'Unsorted';
            $this->createAndMoveToPath($id, $targetPath);
        }
        
        $params[] = $id;
        $this->db->prepare("UPDATE documents SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        
        if (isset($data['tags']) && is_array($data['tags'])) {
            $this->db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$id]);
            foreach ($data['tags'] as $tid) {
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$id, $tid]);
            }
        }
        
        // Déplacer le fichier original de consume vers processed APRÈS validation
        if (!empty($doc['original_filename'])) {
            $this->moveOriginalToProcessed($doc['original_filename']);
        }
        
        return true;
    }
    
    /**
     * Déplace le fichier original de consume vers processed après validation
     */
    private function moveOriginalToProcessed(string $originalFilename): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->consumePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $originalFilename) {
                $this->moveToProcessed($file->getPathname());
                break;
            }
        }
    }
    
    private function createAndMoveToPath(int $documentId, string $relativePath): void
    {
        $doc = $this->getDocument($documentId);
        if (!$doc || empty($doc['file_path'])) return;
        
        // Créer le dossier si nécessaire
        $fullPath = $this->documentsPath . '/' . $relativePath;
        if (!is_dir($fullPath)) {
            @mkdir($fullPath, 0755, true);
        }
        
        // Déplacer le fichier
        $newFilePath = $fullPath . '/' . basename($doc['file_path']);
        if (@rename($doc['file_path'], $newFilePath)) {
            $this->db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")
                ->execute([$newFilePath, $documentId]);
        }
    }
    
    private function sanitizePath(string $path): string
    {
        // Nettoyer le chemin pour éviter les caractères interdits
        $path = preg_replace('/[<>:"|?*]/', '_', $path);
        $path = trim($path, '/\\');
        return $path ?: 'Divers';
    }
    
    private function getDocument(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    public function getConsumePath(): string { return $this->consumePath; }
}
