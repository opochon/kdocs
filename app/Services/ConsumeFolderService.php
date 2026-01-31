<?php
namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\PDFSplitterService;

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
    
    /**
     * Vérifie rapidement s'il y a au moins un fichier dans le dossier consume
     */
    public function hasFiles(): bool
    {
        if (!is_dir($this->consumePath)) {
            return false;
        }
        
        $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'gif', 'webp'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->consumePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $allowed)) {
                    return true; // Au moins un fichier trouvé, on s'arrête ici
                }
            }
        }
        
        return false;
    }
    
    /**
     * Obtient le chemin du fichier de verrouillage
     */
    private function getLockPath(): string
    {
        $base = dirname(__DIR__, 2) . '/storage';
        // S'assurer que le répertoire existe
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base . '/.consume_scan.lock';
    }
    
    private $lockHandle = null;
    
    /**
     * Acquiert un verrou pour éviter les scans simultanés
     * Retourne true si le verrou a été acquis, false sinon
     */
    public function acquireLock(): bool
    {
        $lockPath = $this->getLockPath();
        
        // Vérifier si un lock existe déjà
        if (file_exists($lockPath)) {
            // Vérifier si le processus est toujours actif (timeout de 10 minutes)
            $lockTime = filemtime($lockPath);
            if (time() - $lockTime < 600) {
                return false; // Lock toujours actif
            }
            // Lock expiré, le supprimer
            @unlink($lockPath);
        }
        
        // Créer le lock avec un fichier exclusif
        $this->lockHandle = @fopen($lockPath, 'w');
        if (!$this->lockHandle) {
            return false;
        }
        
        // Essayer d'acquérir un verrou exclusif non-bloquant
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false; // Impossible d'acquérir le lock (scan déjà en cours)
        }
        
        // Écrire le PID et le timestamp
        fwrite($this->lockHandle, getmypid() . "\n" . time());
        fflush($this->lockHandle);
        
        return true;
    }
    
    /**
     * Libère le verrou
     */
    public function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
        
        $lockPath = $this->getLockPath();
        if (file_exists($lockPath)) {
            @unlink($lockPath);
        }
    }
    
    public function scan(): array
    {
        $results = ['scanned' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => [], 'documents' => []];
        
        // Acquérir le verrou pour éviter les scans simultanés
        if (!$this->acquireLock()) {
            $results['errors'][] = "Scan déjà en cours";
            return $results;
        }
        
        try {
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
                    
                    // Si le document existe mais n'est pas validé, l'ignorer
                    // Le fichier reste dans consume jusqu'à validation manuelle
                    // On ne retraite pas les fichiers déjà importés
                    $results['skipped']++;
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
        } finally {
            // Toujours libérer le verrou, même en cas d'erreur
            $this->releaseLock();
        }
        
        return $results;
    }
    
    private function importFile(string $path, ?string $subfolder): array
    {
        $filename = basename($path);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $unique = date('Ymd_His') . '_' . uniqid() . '.' . $ext;

        // Utiliser directement le fichier de consume/ jusqu'à validation
        // Plus besoin de copier vers toclassify/ - c'est redondant
        // Le fichier sera déplacé directement vers son chemin final après validation
        $filePath = $path; // Utiliser directement le chemin du fichier dans consume/

        // Détection MIME avec fallback par extension
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        if ($mimeType === 'application/octet-stream') {
            $mimeMap = [
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'odt' => 'application/vnd.oasis.opendocument.text',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
                'rtf' => 'application/rtf',
                'pdf' => 'application/pdf',
            ];
            $mimeType = $mimeMap[$ext] ?? $mimeType;
        }

        $stmt = $this->db->prepare("
            INSERT INTO documents (title, filename, original_filename, file_path, file_size, mime_type, checksum, status, consume_subfolder, uploaded_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            pathinfo($filename, PATHINFO_FILENAME),
            $unique, $filename, $filePath,
            filesize($filePath),
            $mimeType,
            md5_file($filePath),
            $subfolder
        ]);
        
        $docId = $this->db->lastInsertId();
        
        // Le fichier reste dans consume/ jusqu'à validation
        // Après validation, il sera déplacé directement vers son chemin final dans documents/
        
        $result = ['id' => $docId, 'filename' => $filename, 'status' => 'imported'];
        
        try {
            // OCR
            $processor = new DocumentProcessor();
            $processor->process($docId);
            
            // Après l'OCR, mettre à jour le titre avec extractTitle()
            $docData = $this->getDocument($docId);
            $ocrContent = $docData['content'] ?? $docData['ocr_text'] ?? null;
            $smartTitle = $this->extractTitle($filename, $ocrContent);
            $this->db->prepare("UPDATE documents SET title = ? WHERE id = ?")->execute([$smartTitle, $docId]);
            
            // Vérifier si c'est un PDF multi-pages à séparer (mode AI uniquement)
            $config = Config::load();
            $classificationMode = $config['classification']['method'] ?? 'auto';
            $splitEnabled = $config['classification']['ai_split_enabled'] ?? false;
            
            if ($splitEnabled && ($classificationMode === 'ai' || $classificationMode === 'auto')) {
                try {
                    $splitter = new PDFSplitterService();
                    $splitResult = $splitter->analyzeAndSplit($docId);
                    
                    if ($splitResult && !empty($splitResult['documents'])) {
                        // Le PDF a été séparé, retourner les nouveaux documents
                        $result['split'] = true;
                        $result['split_count'] = $splitResult['split_count'];
                        $result['split_documents'] = array_map(function($doc) {
                            return $doc['id'];
                        }, $splitResult['documents']);
                        
                        // Traiter chaque document séparé
                        foreach ($splitResult['documents'] as $splitDoc) {
                            try {
                                $processor->process($splitDoc['id']);
                                $classifier = new ClassificationService();
                                $classification = $classifier->classify($splitDoc['id']);
                                $this->db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?")
                                    ->execute([json_encode($classification), $splitDoc['id']]);
                            } catch (\Exception $e) {
                                error_log("Erreur traitement document séparé {$splitDoc['id']}: " . $e->getMessage());
                            }
                        }
                        
                        // Le document parent n'a pas besoin de classification puisqu'il a été splité
                        $result['status'] = 'split';
                        return $result;
                    }
                    // Si $splitResult est null, c'est normal (pas de séparation nécessaire ou API non disponible)
                    // Le traitement continue normalement ci-dessous
                } catch (\Exception $e) {
                    // Erreur lors de la séparation (API non disponible, timeout, etc.)
                    // Logger mais continuer avec le traitement normal du document
                    error_log("Erreur séparation PDF document {$docId}: " . $e->getMessage() . " - Traitement normal du document");
                    // Ne pas retourner ici, continuer avec la classification normale
                }
            }
            
            // Classification normale (selon mode configuré)
            $classifier = new ClassificationService();
            $classification = $classifier->classify($docId);
            
            // Sauvegarder suggestions
            $this->db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?")
                ->execute([json_encode($classification), $docId]);
            
            // Sauvegarder les catégories supplémentaires extraites par IA
            $additionalCategories = [];
            if (!empty($classification['ai_result']['additional_categories'])) {
                $additionalCategories = $classification['ai_result']['additional_categories'];
            } elseif (!empty($classification['final']['additional_categories'])) {
                $additionalCategories = $classification['final']['additional_categories'];
            }
            
            if (!empty($additionalCategories)) {
                $this->db->prepare("UPDATE documents SET ai_additional_categories = ? WHERE id = ?")
                    ->execute([json_encode($additionalCategories), $docId]);
            }
            
            $result['classification'] = $classification;
            $result['additional_categories'] = $additionalCategories;
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
        
        // Sauvegarder la synthèse dans classification_suggestions si fournie
        if (!empty($data['summary'])) {
            $currentSuggestions = json_decode($doc['classification_suggestions'] ?? '{}', true);
            if (!isset($currentSuggestions['final'])) {
                $currentSuggestions['final'] = [];
            }
            $currentSuggestions['final']['summary'] = $data['summary'];
            $sets[] = 'classification_suggestions = ?';
            $params[] = json_encode($currentSuggestions);
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
        if (empty($targetPath)) {
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
        
        // Le fichier a déjà été déplacé vers son chemin final dans createAndMoveToPath()
        // Plus besoin de déplacer vers processed/ puisque le fichier est maintenant dans documents/
        
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
        
        // Déterminer le nom de fichier final (utiliser le filename unique du document)
        $finalFilename = $doc['filename'] ?? basename($doc['file_path']);
        $newFilePath = $fullPath . '/' . $finalFilename;
        
        // Déplacer le fichier vers son chemin final dans documents/
        // Le fichier peut venir de consume/ (fichiers importés) ou de pending/ (fichiers séparés)
        if (file_exists($doc['file_path'])) {
            if (@rename($doc['file_path'], $newFilePath)) {
                $this->db->prepare("UPDATE documents SET file_path = ? WHERE id = ?")
                    ->execute([$newFilePath, $documentId]);
            }
        } elseif (file_exists($newFilePath)) {
            // Le fichier est déjà au bon endroit, juste mettre à jour le chemin en base
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
    
    /**
     * Extrait un titre intelligent depuis le contenu OCR ou le nom de fichier
     */
    public function extractTitle(string $filename, ?string $ocrContent): string
    {
        // 1. D'abord essayer depuis le nom de fichier (sans extension)
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        
        // Nettoyer le nom (remplacer _ et - par espaces)
        $cleanName = preg_replace('/[_-]+/', ' ', $nameWithoutExt);
        $cleanName = preg_replace('/\s+/', ' ', trim($cleanName));
        
        // Si le nom est informatif (pas juste des chiffres ou "scan", "doc", etc.)
        if (strlen($cleanName) > 5 && !preg_match('/^(scan|doc|document|img|image|file|toclassify|\d+)$/i', $cleanName)) {
            return ucfirst($cleanName);
        }
        
        // 2. Sinon, extraire depuis le contenu OCR
        if ($ocrContent && strlen($ocrContent) > 10) {
            $lines = array_filter(array_map('trim', explode("\n", $ocrContent)));
            $lines = array_slice($lines, 0, 5);
            
            foreach ($lines as $line) {
                if (strlen($line) < 10 || strlen($line) > 100) continue;
                
                // Patterns courants pour documents juridiques/administratifs
                if (preg_match('/^(Arrêt|Jugement|Décision|Contrat|Convention|Facture|Devis|Attestation|Certificat|Ordonnance|Bundesgericht)/iu', $line)) {
                    return $line;
                }
            }
            
            // Sinon prendre la première ligne significative
            foreach ($lines as $line) {
                if (strlen($line) >= 15 && strlen($line) <= 80) {
                    return $line;
                }
            }
        }
        
        // 3. Fallback
        return $cleanName ?: 'Document sans titre';
    }
    
    /**
     * Réinitialise les checksums MD5 des documents pour permettre leur re-traitement
     * @param array|null $documentIds Si null, réinitialise tous les documents. Sinon, seulement ceux spécifiés
     * @return array ['reset' => nombre de documents réinitialisés]
     */
    public function resetChecksums(?array $documentIds = null): array
    {
        if ($documentIds === null) {
            // Réinitialiser tous les documents non supprimés
            $stmt = $this->db->prepare("UPDATE documents SET checksum = NULL WHERE deleted_at IS NULL");
            $stmt->execute();
            $reset = $stmt->rowCount();
        } else {
            // Réinitialiser seulement les documents spécifiés
            $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
            $stmt = $this->db->prepare("UPDATE documents SET checksum = NULL WHERE id IN ($placeholders) AND deleted_at IS NULL");
            $stmt->execute($documentIds);
            $reset = $stmt->rowCount();
        }
        
        return ['reset' => $reset];
    }
    
    /**
     * Re-scanne les documents existants qui n'ont pas de checksum
     * Cela permet de re-traiter les documents après ajout de tags/champs de classification
     * @return array ['processed' => nombre de documents retraités, 'errors' => []]
     */
    public function rescanDocuments(): array
    {
        $results = ['processed' => 0, 'errors' => []];
        
        // Trouver tous les documents sans checksum et non supprimés
        $stmt = $this->db->query("
            SELECT id, file_path, original_filename 
            FROM documents 
            WHERE checksum IS NULL 
            AND deleted_at IS NULL
            AND file_path IS NOT NULL
            ORDER BY id DESC
        ");
        $documents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($documents as $doc) {
            try {
                // Vérifier que le fichier existe toujours
                if (!file_exists($doc['file_path'])) {
                    $results['errors'][] = "Fichier introuvable pour document {$doc['id']}: {$doc['file_path']}";
                    continue;
                }
                
                // Recalculer le checksum
                $checksum = md5_file($doc['file_path']);
                
                // Mettre à jour le checksum
                $updateStmt = $this->db->prepare("UPDATE documents SET checksum = ? WHERE id = ?");
                $updateStmt->execute([$checksum, $doc['id']]);
                
                // Re-traiter le document (OCR, classification)
                $processor = new DocumentProcessor();
                $processor->process($doc['id']);
                
                // Re-classifier avec les nouveaux tags/champs
                $classifier = new ClassificationService();
                $classification = $classifier->classify($doc['id']);
                
                // Sauvegarder les nouvelles suggestions
                $this->db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?")
                    ->execute([json_encode($classification), $doc['id']]);
                
                // Sauvegarder les catégories supplémentaires extraites par IA
                $additionalCategories = [];
                if (!empty($classification['ai_result']['additional_categories'])) {
                    $additionalCategories = $classification['ai_result']['additional_categories'];
                } elseif (!empty($classification['final']['additional_categories'])) {
                    $additionalCategories = $classification['final']['additional_categories'];
                }
                
                if (!empty($additionalCategories)) {
                    $this->db->prepare("UPDATE documents SET ai_additional_categories = ? WHERE id = ?")
                        ->execute([json_encode($additionalCategories), $doc['id']]);
                }
                
                $results['processed']++;
                
            } catch (\Exception $e) {
                $results['errors'][] = "Document {$doc['id']}: " . $e->getMessage();
                error_log("Erreur re-scan document {$doc['id']}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
}
