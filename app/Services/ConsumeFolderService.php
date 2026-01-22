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
        $results = ['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => [], 'documents' => []];
        
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
            $stmt = $this->db->prepare("SELECT id FROM documents WHERE checksum = ?");
            $stmt->execute([$checksum]);
            if ($stmt->fetch()) {
                $results['skipped']++;
                $this->moveToProcessed($path);
                continue;
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
        $dest = $this->documentsPath . '/' . $unique;
        
        if (!copy($path, $dest)) throw new \Exception("Copie impossible");
        
        $stmt = $this->db->prepare("
            INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, consume_subfolder, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
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
        $this->moveToProcessed($path);
        
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
            $result['status'] = $classification['auto_applied'] ? 'auto_validated' : ($classification['should_review'] ? 'needs_review' : 'processed');
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
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
        $sets = ['status = ?']; $params = ['validated'];
        foreach (['title', 'correspondent_id', 'document_type_id', 'doc_date', 'amount'] as $f) {
            if (isset($data[$f])) { $sets[] = "$f = ?"; $params[] = $data[$f] ?: null; }
        }
        
        // Gérer le chemin de stockage
        $storagePathOption = $data['storage_path_option'] ?? 'suggested';
        
        if ($storagePathOption === 'custom' && !empty($data['storage_path_custom'])) {
            // Créer le chemin personnalisé et déplacer le fichier
            $this->createAndMoveToPath($id, $data['storage_path_custom']);
        } elseif ($storagePathOption === 'existing' && !empty($data['storage_path_id'])) {
            $sets[] = 'storage_path_id = ?';
            $params[] = (int)$data['storage_path_id'];
        } elseif ($storagePathOption === 'suggested') {
            // Générer le chemin suggéré et déplacer le fichier
            $doc = $this->getDocument($id);
            if ($doc) {
                $year = !empty($data['doc_date']) ? date('Y', strtotime($data['doc_date'])) : date('Y');
                $typeName = 'Divers';
                $corrName = 'Inconnu';
                
                if (!empty($data['document_type_id'])) {
                    $typeStmt = $this->db->prepare("SELECT label FROM document_types WHERE id = ?");
                    $typeStmt->execute([$data['document_type_id']]);
                    $type = $typeStmt->fetch();
                    if ($type) $typeName = $type['label'];
                }
                
                if (!empty($data['correspondent_id'])) {
                    $corrStmt = $this->db->prepare("SELECT name FROM correspondents WHERE id = ?");
                    $corrStmt->execute([$data['correspondent_id']]);
                    $corr = $corrStmt->fetch();
                    if ($corr) $corrName = $corr['name'];
                }
                
                $suggestedPath = $year . '/' . $this->sanitizePath($typeName) . '/' . $this->sanitizePath($corrName);
                $this->createAndMoveToPath($id, $suggestedPath);
            }
        }
        
        $params[] = $id;
        $this->db->prepare("UPDATE documents SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        
        if (isset($data['tags']) && is_array($data['tags'])) {
            $this->db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$id]);
            foreach ($data['tags'] as $tid) {
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$id, $tid]);
            }
        }
        return true;
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
