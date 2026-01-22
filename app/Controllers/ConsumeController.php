<?php
namespace KDocs\Controllers;

use KDocs\Services\ConsumeFolderService;
use KDocs\Services\ClassificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsumeController
{
    public function index(Request $request, Response $response): Response
    {
        $service = new ConsumeFolderService();
        $classifier = new ClassificationService();
        
        $pending = $service->getPendingDocuments();
        $consumePath = $service->getConsumePath();
        $filesCount = 0;
        if (is_dir($consumePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($consumePath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) $filesCount++;
            }
        }
        
        // Récupérer les correspondants et types pour les formulaires
        $db = \KDocs\Core\Database::getInstance();
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        $documentTypes = $db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);
        $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        
        // Récupérer les storage paths existants
        $storagePaths = [];
        try {
            $storagePaths = $db->query("SELECT id, name, path FROM storage_paths ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }
        
        // Enrichir les documents avec contenu OCR et suggestions de chemin
        foreach ($pending as &$doc) {
            // Récupérer le contenu OCR
            $doc['content_preview'] = substr($doc['content'] ?? $doc['ocr_text'] ?? '', 0, 500);
            
            // Générer suggestion de chemin de stockage
            $suggestions = json_decode($doc['classification_suggestions'] ?? '{}', true);
            $final = $suggestions['final'] ?? [];
            
            $year = !empty($final['doc_date']) ? date('Y', strtotime($final['doc_date'])) : date('Y');
            $typeName = $final['document_type_name'] ?? 'Divers';
            $corrName = $final['correspondent_name'] ?? 'Inconnu';
            
            $doc['suggested_path'] = $year . '/' . $typeName . '/' . $corrName;
            
            // Récupérer la miniature si disponible
            if (!empty($doc['thumbnail_path'])) {
                $doc['thumbnail_url'] = url('/documents/' . $doc['id'] . '/thumbnail');
            }
        }
        unset($doc);
        
        $user = $request->getAttribute('user');
        $pageTitle = 'Validation des Documents';
        
        ob_start();
        include __DIR__ . '/../../templates/admin/consume.php';
        $content = ob_get_clean();
        
        ob_start();
        include __DIR__ . '/../../templates/layouts/main.php';
        $html = ob_get_clean();
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function scan(Request $request, Response $response): Response
    {
        $results = (new ConsumeFolderService())->scan();
        
        $_SESSION['flash'] = [
            'type' => empty($results['errors']) ? 'success' : 'warning',
            'message' => sprintf('Scan: %d scannés, %d importés, %d ignorés', 
                $results['scanned'], $results['imported'], $results['skipped'])
        ];
        
        return $response->withHeader('Location', url('/admin/consume'))->withStatus(302);
    }
    
    public function validate(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody();
        $service = new ConsumeFolderService();
        
        // Gérer le chemin de stockage personnalisé
        if (!empty($data['storage_path_custom'])) {
            $this->createStoragePathIfNeeded($data['storage_path_custom'], $data);
        }
        
        $service->validateDocument((int)$args['id'], $data);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Document validé'];
        return $response->withHeader('Location', url('/admin/consume'))->withStatus(302);
    }
    
    private function createStoragePathIfNeeded(string $customPath, array $data): void
    {
        $db = \KDocs\Core\Database::getInstance();
        
        // Vérifier si le chemin existe déjà
        $existing = $db->prepare("SELECT id FROM storage_paths WHERE path = ?");
        $existing->execute([$customPath]);
        if ($existing->fetch()) {
            return; // Existe déjà
        }
        
        // Créer le chemin de stockage
        $db->prepare("INSERT INTO storage_paths (name, path) VALUES (?, ?)")
            ->execute([basename($customPath), $customPath]);
        
        // Créer le dossier physique
        $config = \KDocs\Core\Config::load();
        $basePath = $config['storage']['documents'] ?? __DIR__ . '/../../storage/documents';
        $fullPath = $basePath . '/' . $customPath;
        if (!is_dir($fullPath)) {
            @mkdir($fullPath, 0755, true);
        }
    }
}
