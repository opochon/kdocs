<?php
namespace KDocs\Controllers;

use KDocs\Services\ConsumeFolderService;
use KDocs\Services\ClassificationService;
use KDocs\Services\CategoryMappingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ConsumeController
{
    public function index(Request $request, Response $response): Response
    {
        $service = new ConsumeFolderService();
        $classifier = new ClassificationService();
        
        // Scanner automatiquement si des fichiers sont présents dans consume
        // (uniquement si aucun document n'est déjà en attente, pour éviter les scans répétés)
        $pending = $service->getPendingDocuments();
        $consumePath = $service->getConsumePath();
        $filesCount = 0;
        
        if (is_dir($consumePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($consumePath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $ext = strtolower($file->getExtension());
                    if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'gif', 'webp'])) {
                        $filesCount++;
                    }
                }
            }
        }
        
        // Si des fichiers sont présents dans consume ET qu'aucun document n'est en attente, scanner automatiquement
        if ($filesCount > 0 && empty($pending) && $service->hasFiles()) {
            try {
                // Lancer le scan automatiquement (acquireLock est géré dans scan())
                $scanResults = $service->scan();
                
                // Afficher un message flash si des documents ont été importés
                if ($scanResults['imported'] > 0) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => sprintf('Scan automatique: %d fichier(s) importé(s), %d ignoré(s)', 
                            $scanResults['imported'], $scanResults['skipped'])
                    ];
                    
                    // Recharger la liste des documents en attente
                    $pending = $service->getPendingDocuments();
                } elseif (!empty($scanResults['errors'])) {
                    $_SESSION['flash'] = [
                        'type' => 'warning',
                        'message' => 'Erreurs lors du scan: ' . implode(', ', $scanResults['errors'])
                    ];
                } elseif ($scanResults['scanned'] > 0 && $scanResults['imported'] === 0) {
                    // Fichiers scannés mais aucun importé (peut-être déjà importés)
                    $_SESSION['flash'] = [
                        'type' => 'info',
                        'message' => sprintf('%d fichier(s) scanné(s), tous déjà importés', $scanResults['scanned'])
                    ];
                }
            } catch (\Exception $e) {
                // Erreur lors du scan automatique, continuer quand même
                error_log("Erreur scan automatique consume: " . $e->getMessage());
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' => 'Erreur lors du scan automatique: ' . $e->getMessage()
                ];
            }
        }
        
        // Récupérer les correspondants et types pour les formulaires
        $db = \KDocs\Core\Database::getInstance();
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        $documentTypes = $db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);
        $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        
        // Récupérer les champs de classification actifs (pour générer dynamiquement le formulaire)
        $classificationFields = \KDocs\Models\ClassificationField::getActive();
        
        // Récupérer les storage paths existants
        $storagePaths = [];
        try {
            $storagePaths = $db->query("SELECT id, name, path FROM storage_paths ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }
        
        // Enrichir les documents avec contenu OCR et suggestions de chemin
        $pathGenerator = new \KDocs\Services\StoragePathGenerator();
        
        foreach ($pending as &$doc) {
            // Récupérer le contenu OCR
            $doc['content_preview'] = substr($doc['content'] ?? $doc['ocr_text'] ?? '', 0, 500);
            
            // Générer suggestion de chemin de stockage avec StoragePathGenerator
            $suggestions = json_decode($doc['classification_suggestions'] ?? '{}', true);
            $final = $suggestions['final'] ?? [];
            
            // Préparer les données pour le générateur
            $docData = array_merge($doc, [
                'correspondent_id' => $final['correspondent_id'] ?? $doc['correspondent_id'] ?? null,
                'document_type_id' => $final['document_type_id'] ?? $doc['document_type_id'] ?? null,
                'doc_date' => $final['doc_date'] ?? $doc['doc_date'] ?? null,
                'uploaded_at' => $doc['created_at'] ?? null,
            ]);
            
            $doc['suggested_path'] = $pathGenerator->generatePath($docData);
            
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
    
    /**
     * Réinitialise les checksums MD5 et re-scanne les documents
     * Permet de re-traiter les documents après ajout de tags/champs de classification
     */
    public function rescan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $service = new ConsumeFolderService();
        
        try {
            // Optionnel: réinitialiser seulement certains documents
            $documentIds = null;
            if (!empty($data['document_ids']) && is_array($data['document_ids'])) {
                $documentIds = array_map('intval', $data['document_ids']);
            }
            
            // Réinitialiser les checksums
            $resetResult = $service->resetChecksums($documentIds);
            
            // Re-scanner les documents
            $rescanResult = $service->rescanDocuments();
            
            $_SESSION['flash'] = [
                'type' => empty($rescanResult['errors']) ? 'success' : 'warning',
                'message' => sprintf(
                    'Re-scan terminé: %d checksum(s) réinitialisé(s), %d document(s) retraité(s)',
                    $resetResult['reset'],
                    $rescanResult['processed']
                ) . (!empty($rescanResult['errors']) ? ' (' . count($rescanResult['errors']) . ' erreur(s))' : '')
            ];
            
            if (!empty($rescanResult['errors'])) {
                error_log("Erreurs re-scan: " . implode(', ', $rescanResult['errors']));
            }
            
        } catch (\Exception $e) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => 'Erreur lors du re-scan: ' . $e->getMessage()
            ];
            error_log("Erreur re-scan: " . $e->getMessage());
        }
        
        return $response->withHeader('Location', url('/admin/consume'))->withStatus(302);
    }
    
    /**
     * Récupérer uniquement la fiche d'un document (pour rechargement AJAX)
     * GET /admin/consume/document-card/{id}
     */
    public function documentCard(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $service = new ConsumeFolderService();
        $classifier = new ClassificationService();
        
        // Récupérer uniquement ce document
        $pending = $service->getPendingDocuments();
        $doc = null;
        foreach ($pending as $d) {
            if ((int)$d['id'] === $documentId) {
                $doc = $d;
                break;
            }
        }
        
        if (!$doc) {
            // Si le document n'est plus en attente, essayer de le récupérer directement
            $db = \KDocs\Core\Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$documentId]);
            $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        
        if (!$doc) {
            $response->getBody()->write('<div class="p-4 text-red-600">Document non trouvé</div>');
            return $response->withStatus(404);
        }
        
        // Préparer les données comme dans index()
        $db = \KDocs\Core\Database::getInstance();
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        $documentTypes = $db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);
        $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        $classificationFields = \KDocs\Models\ClassificationField::getActive();
        
        $storagePaths = [];
        try {
            $storagePaths = $db->query("SELECT id, name, path FROM storage_paths ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }
        
        // Enrichir le document
        $pathGenerator = new \KDocs\Services\StoragePathGenerator();
        $doc['content_preview'] = substr($doc['content'] ?? $doc['ocr_text'] ?? '', 0, 500);
        $suggestions = json_decode($doc['classification_suggestions'] ?? '{}', true);
        $final = $suggestions['final'] ?? [];
        $docData = array_merge($doc, [
            'correspondent_id' => $final['correspondent_id'] ?? $doc['correspondent_id'] ?? null,
            'document_type_id' => $final['document_type_id'] ?? $doc['document_type_id'] ?? null,
            'doc_date' => $final['doc_date'] ?? $doc['doc_date'] ?? null,
            'uploaded_at' => $doc['created_at'] ?? null,
        ]);
        $doc['suggested_path'] = $pathGenerator->generatePath($docData);
        if (!empty($doc['thumbnail_path'])) {
            $doc['thumbnail_url'] = url('/documents/' . $doc['id'] . '/thumbnail');
        }
        
        // Préparer les variables pour le template (comme dans index())
        $suggestions = json_decode($doc['classification_suggestions'] ?? '{}', true);
        $final = $suggestions['final'] ?? [];
        $hasThumbnail = !empty($doc['thumbnail_url']);
        $pending = [$doc]; // Un seul document pour le rechargement AJAX
        
        // Rendre uniquement la fiche du document en réutilisant le template
        ob_start();
        // Inclure uniquement la partie de la boucle foreach depuis consume.php
        foreach ($pending as $doc): 
            $suggestions = json_decode($doc['classification_suggestions'] ?? '{}', true);
            $final = $suggestions['final'] ?? [];
            $hasThumbnail = !empty($doc['thumbnail_url']);
        ?>
        <div class="bg-white rounded-lg shadow-lg border border-gray-200" id="document-card-<?= $doc['id'] ?>">
            <?php include __DIR__ . '/../../templates/admin/consume_card.php'; ?>
        </div>
        <?php endforeach; 
        $html = ob_get_clean();
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}
