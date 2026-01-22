# K-Docs - CONSUME FOLDER : Pipeline d'ingestion automatique

## 🎯 OBJECTIF

Implémenter le pipeline Paperless-ngx complet :
1. **Surveillance** du dossier `/storage/consume`
2. **Import automatique** des nouveaux fichiers
3. **OCR** du contenu
4. **Split intelligent** (si Claude détecte plusieurs documents)
5. **Classification IA** avec suggestions
6. **Page de validation** pour l'utilisateur

---

## 📁 FICHIERS À CRÉER

### 1. ConsumeFolderService.php

**Fichier** : `app/Services/ConsumeFolderService.php`

```php
<?php
namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Models\Document;

class ConsumeFolderService
{
    private string $consumePath;
    private string $processedPath;
    private string $documentsPath;
    private $db;
    private ?ClaudeService $claude = null;
    
    public function __construct()
    {
        $config = Config::load();
        $basePath = dirname(__DIR__, 2) . '/storage';
        
        $this->consumePath = $config['storage']['consume'] ?? $basePath . '/consume';
        $this->processedPath = $config['storage']['processed'] ?? $basePath . '/processed';
        $this->documentsPath = $config['storage']['documents'] ?? $basePath . '/documents';
        
        $this->db = Database::getInstance();
        
        // Initialiser Claude si disponible
        try {
            $this->claude = new ClaudeService();
            if (!$this->claude->isConfigured()) {
                $this->claude = null;
            }
        } catch (\Exception $e) {
            $this->claude = null;
        }
        
        // Créer les dossiers si nécessaire
        foreach ([$this->consumePath, $this->processedPath, $this->documentsPath] as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
        }
    }
    
    /**
     * Scanner le dossier consume et importer les nouveaux fichiers
     * 
     * @return array Résultats du scan
     */
    public function scan(): array
    {
        $results = [
            'scanned' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
            'documents' => []
        ];
        
        if (!is_dir($this->consumePath)) {
            $results['errors'][] = "Dossier consume inexistant: {$this->consumePath}";
            return $results;
        }
        
        $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'gif', 'webp'];
        
        $files = glob($this->consumePath . '/*');
        
        foreach ($files as $filePath) {
            if (is_dir($filePath)) continue;
            
            $results['scanned']++;
            $filename = basename($filePath);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Vérifier l'extension
            if (!in_array($ext, $allowedExtensions)) {
                $results['skipped']++;
                continue;
            }
            
            // Vérifier si déjà importé (par checksum)
            $checksum = md5_file($filePath);
            $existing = $this->db->prepare("SELECT id FROM documents WHERE checksum = ?");
            $existing->execute([$checksum]);
            if ($existing->fetch()) {
                $results['skipped']++;
                // Déplacer vers processed quand même
                $this->moveToProcessed($filePath);
                continue;
            }
            
            try {
                $docResult = $this->importFile($filePath);
                $results['imported']++;
                $results['documents'][] = $docResult;
            } catch (\Exception $e) {
                $results['errors'][] = "{$filename}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Importer un fichier
     */
    private function importFile(string $filePath): array
    {
        $filename = basename($filePath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $checksum = md5_file($filePath);
        $fileSize = filesize($filePath);
        
        // Déterminer le type MIME
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        
        // Générer un nom unique
        $uniqueName = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        $destPath = $this->documentsPath . '/' . $uniqueName;
        
        // Copier le fichier
        if (!copy($filePath, $destPath)) {
            throw new \Exception("Impossible de copier le fichier vers storage");
        }
        
        // Créer l'entrée en base
        $stmt = $this->db->prepare("
            INSERT INTO documents (
                title, filename, original_filename, file_path, 
                file_size, mime_type, checksum, status, 
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $stmt->execute([
            $title,
            $uniqueName,
            $filename,
            $destPath,
            $fileSize,
            $mimeType,
            $checksum
        ]);
        
        $documentId = $this->db->lastInsertId();
        
        // Déplacer le fichier original vers processed
        $this->moveToProcessed($filePath);
        
        // Lancer le traitement asynchrone
        $result = [
            'id' => $documentId,
            'filename' => $filename,
            'status' => 'imported'
        ];
        
        // Traitement OCR + Classification
        try {
            $processor = new DocumentProcessor();
            $processor->process($documentId);
            $result['status'] = 'processed';
            
            // Si Claude est disponible, analyser pour split potentiel
            if ($this->claude && $ext === 'pdf') {
                $splitResult = $this->analyzeForSplit($documentId, $destPath);
                if ($splitResult['should_split']) {
                    $result['split_suggestion'] = $splitResult;
                    $result['status'] = 'needs_review';
                }
            }
            
            // Classification IA
            if ($this->claude) {
                $classification = $this->classifyWithAI($documentId);
                if ($classification) {
                    $result['ai_suggestions'] = $classification;
                    $result['status'] = 'needs_review';
                }
            }
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Analyser un PDF pour déterminer s'il faut le splitter
     */
    private function analyzeForSplit(int $documentId, string $filePath): array
    {
        $result = [
            'should_split' => false,
            'pages' => [],
            'suggested_splits' => []
        ];
        
        if (!$this->claude) {
            return $result;
        }
        
        // Récupérer le contenu OCR
        $doc = Document::findById($documentId);
        $content = $doc['content'] ?? $doc['ocr_text'] ?? '';
        
        if (empty($content)) {
            return $result;
        }
        
        // Demander à Claude d'analyser
        $prompt = <<<PROMPT
Analyse ce document et détermine s'il contient plusieurs documents distincts qui devraient être séparés.

Contenu du document:
$content

Réponds en JSON avec cette structure:
{
    "contains_multiple_documents": true/false,
    "document_count": nombre,
    "documents": [
        {
            "title": "titre suggéré",
            "type": "facture/courrier/contrat/etc",
            "page_start": 1,
            "page_end": 2,
            "confidence": 0.0-1.0
        }
    ],
    "reasoning": "explication courte"
}

Si le document est cohérent et ne devrait pas être splitté, mets contains_multiple_documents à false.
PROMPT;

        try {
            $response = $this->claude->sendMessage($prompt);
            if ($response) {
                $text = $this->claude->extractText($response);
                $text = preg_replace('/^```json\s*/', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
                
                $analysis = json_decode($text, true);
                if ($analysis && isset($analysis['contains_multiple_documents'])) {
                    $result['should_split'] = $analysis['contains_multiple_documents'];
                    $result['suggested_splits'] = $analysis['documents'] ?? [];
                    $result['reasoning'] = $analysis['reasoning'] ?? '';
                }
            }
        } catch (\Exception $e) {
            error_log("Erreur analyse split: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Classification IA d'un document
     */
    private function classifyWithAI(int $documentId): ?array
    {
        try {
            $classifier = new AIClassifierService();
            if (!$classifier->isAvailable()) {
                return null;
            }
            return $classifier->classify($documentId);
        } catch (\Exception $e) {
            error_log("Erreur classification IA: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Déplacer un fichier vers le dossier processed
     */
    private function moveToProcessed(string $filePath): bool
    {
        $filename = basename($filePath);
        $destPath = $this->processedPath . '/' . date('Ymd_His') . '_' . $filename;
        
        return @rename($filePath, $destPath);
    }
    
    /**
     * Splitter un document PDF
     */
    public function splitDocument(int $documentId, array $splits): array
    {
        $results = [];
        
        $doc = Document::findById($documentId);
        if (!$doc) {
            throw new \Exception("Document non trouvé");
        }
        
        $filePath = $doc['file_path'];
        if (!file_exists($filePath)) {
            throw new \Exception("Fichier non trouvé");
        }
        
        // Utiliser une commande pour splitter (pdftk ou qpdf)
        $splitTool = $this->findSplitTool();
        
        foreach ($splits as $index => $split) {
            $pageStart = $split['page_start'];
            $pageEnd = $split['page_end'];
            $suggestedTitle = $split['title'] ?? "Part " . ($index + 1);
            
            $outputPath = $this->documentsPath . '/' . date('Ymd_His') . '_split_' . ($index + 1) . '.pdf';
            
            if ($splitTool === 'pdftk') {
                $cmd = sprintf(
                    'pdftk "%s" cat %d-%d output "%s"',
                    $filePath, $pageStart, $pageEnd, $outputPath
                );
            } elseif ($splitTool === 'qpdf') {
                $cmd = sprintf(
                    'qpdf "%s" --pages . %d-%d -- "%s"',
                    $filePath, $pageStart, $pageEnd, $outputPath
                );
            } elseif ($splitTool === 'gs') {
                $cmd = sprintf(
                    '"%s" -sDEVICE=pdfwrite -dNOPAUSE -dBATCH -dFirstPage=%d -dLastPage=%d -sOutputFile="%s" "%s"',
                    Config::get('tools.ghostscript', 'gs'),
                    $pageStart, $pageEnd, $outputPath, $filePath
                );
            } else {
                throw new \Exception("Aucun outil de split PDF disponible");
            }
            
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputPath)) {
                // Créer le nouveau document
                $newDocId = $this->createSplitDocument($outputPath, $suggestedTitle, $split);
                $results[] = [
                    'id' => $newDocId,
                    'title' => $suggestedTitle,
                    'pages' => "{$pageStart}-{$pageEnd}",
                    'status' => 'created'
                ];
            } else {
                $results[] = [
                    'title' => $suggestedTitle,
                    'pages' => "{$pageStart}-{$pageEnd}",
                    'status' => 'error',
                    'error' => 'Échec du split'
                ];
            }
        }
        
        // Marquer le document original comme splitté
        $this->db->prepare("UPDATE documents SET status = 'split' WHERE id = ?")
            ->execute([$documentId]);
        
        return $results;
    }
    
    private function findSplitTool(): ?string
    {
        // Vérifier Ghostscript (déjà configuré)
        $gs = Config::get('tools.ghostscript');
        if ($gs && file_exists($gs)) {
            return 'gs';
        }
        
        // Vérifier pdftk
        exec('pdftk --version 2>&1', $output, $code);
        if ($code === 0) return 'pdftk';
        
        // Vérifier qpdf
        exec('qpdf --version 2>&1', $output, $code);
        if ($code === 0) return 'qpdf';
        
        return null;
    }
    
    private function createSplitDocument(string $filePath, string $title, array $splitInfo): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO documents (
                title, filename, original_filename, file_path,
                file_size, mime_type, checksum, status,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'application/pdf', ?, 'pending', NOW(), NOW())
        ");
        
        $filename = basename($filePath);
        $stmt->execute([
            $title,
            $filename,
            $filename,
            $filePath,
            filesize($filePath),
            md5_file($filePath)
        ]);
        
        $docId = $this->db->lastInsertId();
        
        // Traiter le nouveau document
        try {
            $processor = new DocumentProcessor();
            $processor->process($docId);
        } catch (\Exception $e) {
            error_log("Erreur traitement split: " . $e->getMessage());
        }
        
        return $docId;
    }
    
    /**
     * Récupérer les documents en attente de validation
     */
    public function getPendingDocuments(): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   c.name as correspondent_name,
                   dt.label as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.status IN ('pending', 'needs_review')
            ORDER BY d.created_at DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Valider un document (appliquer les suggestions et marquer comme validé)
     */
    public function validateDocument(int $documentId, array $data): bool
    {
        $updates = ['status' => 'validated', 'updated_at' => date('Y-m-d H:i:s')];
        $params = [];
        
        if (isset($data['title'])) {
            $updates['title'] = $data['title'];
        }
        if (isset($data['correspondent_id'])) {
            $updates['correspondent_id'] = $data['correspondent_id'] ?: null;
        }
        if (isset($data['document_type_id'])) {
            $updates['document_type_id'] = $data['document_type_id'] ?: null;
        }
        if (isset($data['document_date'])) {
            $updates['document_date'] = $data['document_date'] ?: null;
        }
        
        $setClauses = [];
        foreach ($updates as $col => $val) {
            $setClauses[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $documentId;
        
        $sql = "UPDATE documents SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);
        
        // Gérer les tags
        if (isset($data['tags']) && is_array($data['tags'])) {
            $this->db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$documentId]);
            foreach ($data['tags'] as $tagId) {
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                    ->execute([$documentId, $tagId]);
            }
        }
        
        return true;
    }
}
```

---

### 2. ConsumeController.php

**Fichier** : `app/Controllers/ConsumeController.php`

```php
<?php
namespace KDocs\Controllers;

use KDocs\Services\ConsumeFolderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsumeController
{
    private function renderTemplate(string $path, array $data = []): string
    {
        extract($data);
        ob_start();
        include $path;
        return ob_get_clean();
    }
    
    /**
     * GET /admin/consume
     * Page de gestion du consume folder
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $consumeService = new ConsumeFolderService();
        
        // Documents en attente
        $pendingDocuments = $consumeService->getPendingDocuments();
        
        // Infos sur le dossier consume
        $config = \KDocs\Core\Config::load();
        $consumePath = $config['storage']['consume'] ?? dirname(__DIR__, 2) . '/storage/consume';
        $filesInConsume = is_dir($consumePath) ? count(glob($consumePath . '/*')) : 0;
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/consume.php', [
            'pendingDocuments' => $pendingDocuments,
            'consumePath' => $consumePath,
            'filesInConsume' => $filesInConsume,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Consume Folder - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Consume Folder',
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }
    
    /**
     * POST /admin/consume/scan
     * Lancer un scan manuel
     */
    public function scan(Request $request, Response $response): Response
    {
        $consumeService = new ConsumeFolderService();
        $results = $consumeService->scan();
        
        // Rediriger avec message flash
        $_SESSION['flash'] = [
            'type' => $results['errors'] ? 'warning' : 'success',
            'message' => sprintf(
                'Scan terminé: %d fichiers scannés, %d importés, %d ignorés',
                $results['scanned'],
                $results['imported'],
                $results['skipped']
            )
        ];
        
        return $response
            ->withHeader('Location', url('/admin/consume'))
            ->withStatus(302);
    }
    
    /**
     * POST /admin/consume/validate/{id}
     * Valider un document
     */
    public function validate(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        $consumeService = new ConsumeFolderService();
        $consumeService->validateDocument($documentId, $data);
        
        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'Document validé'
        ];
        
        return $response
            ->withHeader('Location', url('/admin/consume'))
            ->withStatus(302);
    }
    
    /**
     * POST /admin/consume/split/{id}
     * Splitter un document
     */
    public function split(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        $splits = json_decode($data['splits'] ?? '[]', true);
        
        if (empty($splits)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Aucune information de split'];
            return $response->withHeader('Location', url('/admin/consume'))->withStatus(302);
        }
        
        $consumeService = new ConsumeFolderService();
        $results = $consumeService->splitDocument($documentId, $splits);
        
        $created = count(array_filter($results, fn($r) => $r['status'] === 'created'));
        
        $_SESSION['flash'] = [
            'type' => $created > 0 ? 'success' : 'error',
            'message' => "Document splitté: $created nouveaux documents créés"
        ];
        
        return $response
            ->withHeader('Location', url('/admin/consume'))
            ->withStatus(302);
    }
}
```

---

### 3. Template consume.php

**Fichier** : `templates/admin/consume.php`

```php
<?php use KDocs\Core\Config; $base = Config::basePath(); ?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Consume Folder</h1>
            <p class="text-sm text-gray-500 mt-1">
                Dossier surveillé: <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($consumePath) ?></code>
            </p>
        </div>
        <div class="flex gap-2">
            <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                <?= $filesInConsume ?> fichier(s) en attente
            </span>
            <form method="POST" action="<?= url('/admin/consume/scan') ?>" class="inline">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                    🔄 Scanner maintenant
                </button>
            </form>
        </div>
    </div>
    
    <!-- Flash message -->
    <?php if (!empty($_SESSION['flash'])): ?>
    <div class="mb-4 p-4 rounded <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-100 text-green-800' : ($_SESSION['flash']['type'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>
    
    <!-- Documents en attente -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">
                Documents à valider (<?= count($pendingDocuments) ?>)
            </h2>
        </div>
        
        <?php if (empty($pendingDocuments)): ?>
        <div class="p-8 text-center text-gray-500">
            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p>Aucun document en attente de validation</p>
            <p class="text-sm mt-2">Déposez des fichiers dans le dossier consume ou cliquez sur "Scanner"</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-gray-200">
            <?php foreach ($pendingDocuments as $doc): ?>
            <div class="p-4 hover:bg-gray-50" id="doc-<?= $doc['id'] ?>">
                <form method="POST" action="<?= url('/admin/consume/validate/' . $doc['id']) ?>" class="space-y-4">
                    <div class="flex items-start gap-4">
                        <!-- Thumbnail -->
                        <div class="w-24 h-32 bg-gray-100 rounded flex-shrink-0 overflow-hidden">
                            <?php if ($doc['thumbnail_path']): ?>
                            <img src="<?= url('/documents/' . $doc['id'] . '/thumbnail') ?>" 
                                 alt="Thumbnail" class="w-full h-full object-cover">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-gray-400">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Infos & Formulaire -->
                        <div class="flex-1 grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Titre</label>
                                <input type="text" name="title" value="<?= htmlspecialchars($doc['title'] ?? '') ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                            </div>
                            
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Date document</label>
                                <input type="date" name="document_date" 
                                       value="<?= $doc['document_date'] ? date('Y-m-d', strtotime($doc['document_date'])) : '' ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                            </div>
                            
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Correspondant</label>
                                <select name="correspondent_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                    <option value="">-- Sélectionner --</option>
                                    <?php
                                    $correspondents = \KDocs\Models\Correspondent::all();
                                    foreach ($correspondents as $c):
                                    ?>
                                    <option value="<?= $c['id'] ?>" <?= ($doc['correspondent_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                                <select name="document_type_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                    <option value="">-- Sélectionner --</option>
                                    <?php
                                    $types = \KDocs\Models\DocumentType::all();
                                    foreach ($types as $t):
                                    ?>
                                    <option value="<?= $t['id'] ?>" <?= ($doc['document_type_id'] ?? 0) == $t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['label']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="flex flex-col gap-2">
                            <button type="submit" class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">
                                ✓ Valider
                            </button>
                            <a href="<?= url('/documents/' . $doc['id']) ?>" 
                               class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded hover:bg-gray-200 text-center">
                                Voir
                            </a>
                        </div>
                    </div>
                    
                    <!-- Statut et fichier original -->
                    <div class="flex items-center gap-4 text-xs text-gray-500">
                        <span class="px-2 py-1 rounded <?= $doc['status'] === 'needs_review' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100' ?>">
                            <?= $doc['status'] ?>
                        </span>
                        <span>Fichier: <?= htmlspecialchars($doc['original_filename'] ?? $doc['filename']) ?></span>
                        <span>Importé: <?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?></span>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
```

---

### 4. Routes à ajouter dans index.php

```php
// Consume Folder
$group->get('/admin/consume', [ConsumeController::class, 'index']);
$group->post('/admin/consume/scan', [ConsumeController::class, 'scan']);
$group->post('/admin/consume/validate/{id}', [ConsumeController::class, 'validate']);
$group->post('/admin/consume/split/{id}', [ConsumeController::class, 'split']);
```

---

### 5. API pour scan automatique (cron)

**Fichier** : `app/Controllers/Api/ConsumeApiController.php`

```php
<?php
namespace KDocs\Controllers\Api;

use KDocs\Services\ConsumeFolderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsumeApiController extends ApiController
{
    /**
     * POST /api/consume/scan
     * Endpoint pour cron job
     */
    public function scan(Request $request, Response $response): Response
    {
        $service = new ConsumeFolderService();
        $results = $service->scan();
        
        return $this->jsonResponse($response, [
            'success' => true,
            'results' => $results
        ]);
    }
    
    /**
     * GET /api/consume/pending
     * Liste des documents en attente
     */
    public function pending(Request $request, Response $response): Response
    {
        $service = new ConsumeFolderService();
        $documents = $service->getPendingDocuments();
        
        return $this->jsonResponse($response, [
            'success' => true,
            'count' => count($documents),
            'documents' => $documents
        ]);
    }
}
```

Route API:
```php
$group->post('/api/consume/scan', [ConsumeApiController::class, 'scan']);
$group->get('/api/consume/pending', [ConsumeApiController::class, 'pending']);
```

---

### 6. Cron/Task pour surveillance automatique

**Windows Task Scheduler** ou **cron Linux** :

```bash
# Toutes les 5 minutes
*/5 * * * * curl -X POST http://localhost/kdocs/api/consume/scan
```

Ou script PHP autonome `scan_consume.php` :

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

$service = new \KDocs\Services\ConsumeFolderService();
$results = $service->scan();

echo date('Y-m-d H:i:s') . " - Scan: {$results['imported']} importés, {$results['skipped']} ignorés\n";
```

---

## 📋 RÉSUMÉ

| Fichier | Description |
|---------|-------------|
| `app/Services/ConsumeFolderService.php` | Logique métier complète |
| `app/Controllers/ConsumeController.php` | Pages web |
| `app/Controllers/Api/ConsumeApiController.php` | API pour cron |
| `templates/admin/consume.php` | Interface de validation |
| `index.php` | Routes à ajouter |

---

## 🚀 COMMANDE CURSOR

```
Lis docs/CURSOR_CONSUME_FOLDER.md et implémente le pipeline consume folder complet :

1. Crée ConsumeFolderService.php avec scan, import, split, classification
2. Crée ConsumeController.php pour l'interface web
3. Crée le template consume.php
4. Ajoute les routes dans index.php
5. Crée ConsumeApiController.php pour le cron
6. Teste avec un fichier dans storage/consume
```
