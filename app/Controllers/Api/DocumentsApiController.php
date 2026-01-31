<?php
/**
 * K-Docs - API REST pour Documents
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\Document;
use KDocs\Core\Database;
use KDocs\Services\TrashService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class DocumentsApiController extends ApiController
{
    /**
     * Liste des documents (GET /api/documents)
     */
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $pagination = $this->getPaginationParams($queryParams);
        
        $db = Database::getInstance();
        $where = ['d.deleted_at IS NULL'];
        // Exclure les documents en attente de validation (pending) de l'API
        $where[] = "(d.status IS NULL OR d.status != 'pending')";
        $params = [];
        
        // Filtres
        if (!empty($queryParams['search'])) {
            $where[] = "(d.title LIKE ? OR d.original_filename LIKE ? OR d.filename LIKE ? OR d.ocr_text LIKE ?)";
            $searchParam = '%' . $queryParams['search'] . '%';
            $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
        }
        
        if (!empty($queryParams['document_type_id'])) {
            $where[] = "d.document_type_id = ?";
            $params[] = (int)$queryParams['document_type_id'];
        }
        
        if (!empty($queryParams['correspondent_id'])) {
            $where[] = "d.correspondent_id = ?";
            $params[] = (int)$queryParams['correspondent_id'];
        }
        
        if (!empty($queryParams['tag_id'])) {
            $where[] = "EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id AND dt.tag_id = ?)";
            $params[] = (int)$queryParams['tag_id'];
        }
        
        $whereClause = 'WHERE ' . implode(' AND ', $where);
        
        // Tri
        $orderBy = $queryParams['order_by'] ?? 'created_at';
        $order = strtoupper($queryParams['order'] ?? 'DESC');
        $allowedOrderBy = ['id', 'title', 'created_at', 'updated_at', 'document_date', 'amount'];
        if (!in_array($orderBy, $allowedOrderBy)) {
            $orderBy = 'created_at';
        }
        if ($order !== 'ASC' && $order !== 'DESC') {
            $order = 'DESC';
        }
        
        // Compter le total
        $countSql = "SELECT COUNT(*) FROM documents d $whereClause";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        // Récupérer les documents
        $sql = "
            SELECT d.*, 
                   dt.label as document_type_label,
                   c.name as correspondent_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            $whereClause
            ORDER BY d.$orderBy $order
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $db->prepare($sql);
        
        // Bind les paramètres de filtres d'abord
        $bindIndex = 1;
        foreach ($params as $value) {
            $stmt->bindValue($bindIndex++, $value);
        }
        
        // Puis les paramètres de pagination
        $stmt->bindValue($bindIndex++, $pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue($bindIndex++, $pagination['offset'], PDO::PARAM_INT);
        
        $stmt->execute();
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formater les documents pour l'API
        $formatted = array_map(function($doc) {
            return $this->formatDocument($doc);
        }, $documents);
        
        return $this->paginatedResponse($response, $formatted, $pagination['page'], $pagination['per_page'], $total);
    }

    /**
     * Détails d'un document (GET /api/documents/{id})
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $user = $request->getAttribute('user');

        $db = Database::getInstance();

        // Récupérer le document avec toutes les infos
        $stmt = $db->prepare("
            SELECT d.*,
                   dt.label as document_type_label,
                   c.name as correspondent_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.id = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        // Récupérer les tags du document
        $tags = [];
        try {
            $tagStmt = $db->prepare("SELECT t.id, t.name, t.color FROM tags t INNER JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
            $tagStmt->execute([$id]);
            $tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {}

        // Récupérer les notes du document
        $notes = [];
        try {
            $notes = \KDocs\Models\DocumentNote::allForDocument($id);
        } catch (\Exception $e) {}

        // Récupérer les listes de référence pour les formulaires
        $correspondents = [];
        $documentTypes = [];
        $allTags = [];
        try {
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
            $allTags = $db->query("SELECT id, name, color FROM tags ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {}

        // Vérifier si l'utilisateur peut valider
        $canValidate = true;
        try {
            if ($user) {
                $result = \KDocs\Models\Role::canUserValidateDocument($user['id'], $document);
                $canValidate = $result['can_validate'] ?? true;
            }
        } catch (\Exception $e) {}

        // Vérifier si l'IA est disponible
        $aiAvailable = false;
        try {
            $aiClassifier = new \KDocs\Services\AIClassifierService();
            $aiAvailable = $aiClassifier->isAvailable();
        } catch (\Exception $e) {}

        $formatted = $this->formatDocument($document);
        $formatted['tags'] = $tags;
        $formatted['notes'] = $notes;
        $formatted['ocr_text'] = $document['ocr_text'] ?? '';
        $formatted['validation_status'] = $document['validation_status'] ?? null;
        $formatted['validated_at'] = $document['validated_at'] ?? null;
        $formatted['validation_comment'] = $document['validation_comment'] ?? null;
        $formatted['can_validate'] = $canValidate;
        $formatted['ai_available'] = $aiAvailable;

        // Récupérer les dossiers logiques
        $logicalFolders = [];
        try {
            $logicalFolders = \KDocs\Models\LogicalFolder::getAll();
        } catch (\Exception $e) {}

        // Récupérer les champs personnalisés
        $customFields = [];
        $customFieldValues = [];
        try {
            $customFields = \KDocs\Models\CustomField::all();
            $customFieldValues = \KDocs\Models\CustomField::getValuesForDocument($id);
        } catch (\Exception $e) {}

        $formatted['custom_field_values'] = $customFieldValues;
        $formatted['logical_folder_id'] = $document['logical_folder_id'] ?? null;
        $formatted['storage_path'] = $document['storage_path'] ?? $document['relative_path'] ?? null;

        // Ajouter les listes de référence
        $formatted['_meta'] = [
            'correspondents' => $correspondents,
            'document_types' => $documentTypes,
            'all_tags' => $allTags,
            'logical_folders' => $logicalFolders,
            'custom_fields' => $customFields
        ];

        return $this->successResponse($response, $formatted);
    }

    /**
     * Créer un document (POST /api/documents)
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $request->getAttribute('user');
        
        // Validation
        if (empty($data['filename']) || empty($data['file_path'])) {
            return $this->errorResponse($response, 'filename et file_path sont requis');
        }
        
        try {
            $documentId = Document::create([
                'title' => $data['title'] ?? null,
                'filename' => $data['filename'],
                'original_filename' => $data['original_filename'] ?? $data['filename'],
                'file_path' => $data['file_path'],
                'file_size' => $data['file_size'] ?? filesize($data['file_path']),
                'mime_type' => $data['mime_type'] ?? 'application/pdf',
                'document_type_id' => !empty($data['document_type_id']) ? (int)$data['document_type_id'] : null,
                'correspondent_id' => !empty($data['correspondent_id']) ? (int)$data['correspondent_id'] : null,
                'doc_date' => $data['document_date'] ?? null,
                'amount' => !empty($data['amount']) ? (float)$data['amount'] : null,
                'currency' => $data['currency'] ?? 'CHF',
                'created_by' => $user['id'],
            ]);
            
            $document = Document::findById($documentId);
            return $this->successResponse($response, $this->formatDocument($document), 'Document créé avec succès', 201);
            
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la création : ' . $e->getMessage(), 500);
        }
    }

    /**
     * Mettre à jour un document (PUT /api/documents/{id})
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $document = Document::findById($id);
        
        if (!$document || $document['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }
        
        $data = $request->getParsedBody();
        $db = Database::getInstance();
        
        try {
            $db->beginTransaction();
            
            $updateFields = [];
            $updateParams = [];
            
            if (isset($data['title'])) {
                $updateFields[] = 'title = ?';
                $updateParams[] = $data['title'];
            }
            
            if (isset($data['document_type_id'])) {
                $updateFields[] = 'document_type_id = ?';
                $updateParams[] = $data['document_type_id'] ? (int)$data['document_type_id'] : null;
            }
            
            if (isset($data['correspondent_id'])) {
                $updateFields[] = 'correspondent_id = ?';
                $updateParams[] = $data['correspondent_id'] ? (int)$data['correspondent_id'] : null;
            }
            
            if (isset($data['document_date'])) {
                $updateFields[] = 'document_date = ?';
                $updateParams[] = $data['document_date'] ?: null;
            }
            
            if (isset($data['amount'])) {
                $updateFields[] = 'amount = ?';
                $updateParams[] = $data['amount'] ? (float)$data['amount'] : null;
            }
            
            if (isset($data['currency'])) {
                $updateFields[] = 'currency = ?';
                $updateParams[] = $data['currency'];
            }

            if (isset($data['ocr_text'])) {
                $updateFields[] = 'ocr_text = ?';
                $updateFields[] = 'content = ?';
                $updateParams[] = $data['ocr_text'];
                $updateParams[] = $data['ocr_text'];
            }

            if (isset($data['logical_folder_id'])) {
                $updateFields[] = 'logical_folder_id = ?';
                $updateParams[] = $data['logical_folder_id'] ? (int)$data['logical_folder_id'] : null;
            }

            if (isset($data['storage_path'])) {
                $updateFields[] = 'storage_path = ?';
                $updateParams[] = $data['storage_path'] ?: null;
            }

            if (!empty($updateFields)) {
                $updateFields[] = 'updated_at = NOW()';
                $updateParams[] = $id;
                
                $sql = "UPDATE documents SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($updateParams);
            }
            
            // Gérer les tags
            if (isset($data['tags']) && is_array($data['tags'])) {
                $db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$id]);
                foreach ($data['tags'] as $tagId) {
                    $tagId = (int)$tagId;
                    if ($tagId > 0) {
                        $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$id, $tagId]);
                    }
                }
            }

            // Gérer les champs personnalisés
            if (isset($data['custom_field_values']) && is_array($data['custom_field_values'])) {
                foreach ($data['custom_field_values'] as $fieldId => $value) {
                    $fieldId = (int)$fieldId;
                    if ($fieldId > 0) {
                        \KDocs\Models\CustomField::setValue($id, $fieldId, $value);
                    }
                }
            }

            $db->commit();
            
            $updated = Document::findById($id);
            return $this->successResponse($response, $this->formatDocument($updated), 'Document mis à jour avec succès');
            
        } catch (\Exception $e) {
            $db->rollBack();
            return $this->errorResponse($response, 'Erreur lors de la mise à jour : ' . $e->getMessage(), 500);
        }
    }

    /**
     * Supprimer un document (DELETE /api/documents/{id})
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $user = $request->getAttribute('user');
        
        $trash = new TrashService();
        if ($trash->moveToTrash($id, $user['id'])) {
            return $this->successResponse($response, null, 'Document supprimé avec succès');
        }
        
        return $this->errorResponse($response, 'Erreur lors de la suppression', 500);
    }

    /**
     * Classifier un document avec l'IA (POST /api/documents/{id}/classify-ai)
     */
    public function classifyWithAI(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $document = Document::findById($id);
        
        if (!$document || $document['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }
        
        $classifier = new \KDocs\Services\AIClassifierService();
        
        if (!$classifier->isAvailable()) {
            return $this->errorResponse($response, 'Claude API non configurée');
        }
        
        $suggestions = $classifier->classify($id);
        
        if (!$suggestions) {
            return $this->errorResponse($response, 'Impossible de classifier le document. Vérifiez que le document contient du texte.');
        }
        
        // Stocker temporairement les suggestions en session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['ai_suggestions_' . $id] = $suggestions;
        
        return $this->successResponse($response, [
            'suggestions' => $suggestions
        ], 'Classification réussie');
    }

    /**
     * Appliquer les suggestions de l'IA (POST /api/documents/{id}/apply-ai-suggestions)
     */
    /**
     * Analyser un document avec l'IA (avec ou sans OCR préalable)
     * POST /api/documents/{id}/analyze-with-ai
     */
    public function analyzeWithAI(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        $ocrMode = $data['ocr_mode'] ?? 'local';
        $useFileDirectly = $data['use_file_directly'] ?? false;
        
        $db = Database::getInstance();
        
        // Récupérer le document
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$document) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }
        
        try {
            $classificationService = new \KDocs\Services\ClassificationService();
            
            // Si mode IA et qu'on veut utiliser le fichier directement
            if ($useFileDirectly && $ocrMode === 'ai') {
                // Utiliser AIClassifierService avec le fichier directement
                $aiClassifier = new \KDocs\Services\AIClassifierService();
                if ($aiClassifier->isAvailable()) {
                    // Analyser directement avec le fichier (sans OCR préalable)
                    $claudeService = new \KDocs\Services\ClaudeService();
                    $aiResult = $aiClassifier->classifyWithFile($documentId);
                    
                    // Récupérer la réponse brute de Claude pour le logging (si disponible)
                    // Note: On ne peut pas récupérer directement la réponse, donc on log après coup
                    
                    if ($aiResult) {
                        // Normaliser le résultat comme dans ClassificationService
                        $normalized = $this->normalizeAIResult($aiResult);
                        
                        // Mettre à jour les suggestions
                        $suggestions = json_decode($document['classification_suggestions'] ?? '{}', true);
                        $suggestions['ai_result'] = $normalized;
                        $suggestions['method_used'] = 'ai_direct';
                        $suggestions['final'] = $normalized;
                        // Mettre à jour le taux de confiance si pas déjà défini ou si la nouvelle valeur est meilleure
                        $newConfidence = $normalized['confidence'] ?? 0.7;
                        $existingConfidence = $suggestions['confidence'] ?? 0;
                        $suggestions['confidence'] = max($existingConfidence, $newConfidence);
                        
                        $updateStmt = $db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?");
                        $updateStmt->execute([json_encode($suggestions), $documentId]);
                        
                        return $this->successResponse($response, [
                            'tags_count' => count($normalized['tag_names'] ?? []),
                            'has_summary' => !empty($normalized['summary']),
                            'confidence' => $normalized['confidence'] ?? 0.7,
                            'message' => 'Analyse IA terminée avec succès'
                        ]);
                    }
                }
            }
            
            // Sinon, utiliser le processus normal (OCR local puis IA)
            // D'abord, faire l'OCR si nécessaire
            if (empty($document['ocr_text']) || strpos($document['ocr_text'], 'OCR échoué') !== false) {
                $ocrService = new \KDocs\Services\OCRService();
                $filePath = $document['file_path'] ?? null;
                
                if ($filePath && file_exists($filePath)) {
                    $ocrText = $ocrService->extractText($filePath);
                    
                    if (!empty($ocrText)) {
                        // Nettoyer le texte OCR
                        $ocrText = mb_convert_encoding($ocrText, 'UTF-8', 'UTF-8');
                        $ocrText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $ocrText);
                        
                        $updateStmt = $db->prepare("UPDATE documents SET ocr_text = ?, content = ? WHERE id = ?");
                        $updateStmt->execute([$ocrText, $ocrText, $documentId]);
                    }
                }
            }
            
            // Ensuite, analyser avec l'IA
            $result = $classificationService->classify($documentId);
            
            // Récupérer les suggestions existantes pour préserver le taux de confiance si meilleur
            $existingSuggestions = json_decode($document['classification_suggestions'] ?? '{}', true);
            $existingConfidence = $existingSuggestions['confidence'] ?? 0;
            $newConfidence = $result['confidence'] ?? 0;
            // Mettre à jour le taux de confiance si pas déjà défini ou si la nouvelle valeur est meilleure
            $result['confidence'] = max($existingConfidence, $newConfidence);
            
            // Mettre à jour les suggestions
            $updateStmt = $db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?");
            $updateStmt->execute([json_encode($result), $documentId]);
            
            $tagsCount = count($result['final']['tag_names'] ?? []);
            $hasSummary = !empty($result['final']['summary']) || !empty($result['ai_result']['summary']);
            
            return $this->successResponse($response, [
                'tags_count' => $tagsCount,
                'has_summary' => $hasSummary,
                'confidence' => $result['confidence'],
                'message' => 'Analyse IA terminée avec succès'
            ]);
            
        } catch (\Exception $e) {
            error_log("analyzeWithAI error: " . $e->getMessage());
            return $this->errorResponse($response, 'Erreur lors de l\'analyse: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Analyser un document complexe avec l'IA (analyse approfondie)
     * POST /api/documents/{id}/analyze-complex-with-ai
     */
    public function analyzeComplexWithAI(Request $request, Response $response, array $args): Response
    {
        $documentId = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        
        $db = Database::getInstance();
        
        // Récupérer le document
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$document) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }
        
        try {
            // Utiliser AIClassifierService pour l'analyse complexe
            $aiClassifier = new \KDocs\Services\AIClassifierService();
            if (!$aiClassifier->isAvailable()) {
                return $this->errorResponse($response, 'IA non disponible');
            }
            
            // Analyser avec la méthode complexe
            $aiResult = $aiClassifier->classifyComplexWithFile($documentId);
            if (!$aiResult) {
                return $this->errorResponse($response, 'Impossible d\'analyser le document complexe');
            }
            
            // Normaliser le résultat
            $normalized = $this->normalizeAIResult($aiResult);
            
            // Mettre à jour les suggestions
            $suggestions = json_decode($document['classification_suggestions'] ?? '{}', true);
            $suggestions['ai_result'] = $normalized;
            $suggestions['method_used'] = 'ai_complex';
            $suggestions['final'] = $normalized;
            // Mettre à jour le taux de confiance si pas déjà défini ou si la nouvelle valeur est meilleure
            $newConfidence = $normalized['confidence'] ?? 0.7;
            $existingConfidence = $suggestions['confidence'] ?? 0;
            $suggestions['confidence'] = max($existingConfidence, $newConfidence);
            
            $updateStmt = $db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?");
            $updateStmt->execute([json_encode($suggestions), $documentId]);
            
            return $this->successResponse($response, [
                'tags_count' => count($normalized['tag_names'] ?? []),
                'has_summary' => !empty($normalized['summary']),
                'confidence' => $suggestions['confidence'],
                'message' => 'Analyse complexe IA terminée avec succès'
            ]);
            
        } catch (\Exception $e) {
            error_log("analyzeComplexWithAI error: " . $e->getMessage());
            return $this->errorResponse($response, 'Erreur lors de l\'analyse complexe: ' . $e->getMessage(), 500);
        }
    }
    
    public function applyAISuggestions(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $document = Document::findById($id);
        
        if (!$document || $document['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $suggestions = $_SESSION['ai_suggestions_' . $id] ?? null;
        
        if (!$suggestions) {
            return $this->errorResponse($response, 'Pas de suggestions disponibles. Veuillez d\'abord classifier le document.');
        }
        
        $classifier = new \KDocs\Services\AIClassifierService();
        $success = $classifier->applySuggestions($id, $suggestions);
        
        if ($success) {
            unset($_SESSION['ai_suggestions_' . $id]);
            return $this->successResponse($response, null, 'Suggestions appliquées avec succès');
        } else {
            return $this->errorResponse($response, 'Erreur lors de l\'application des suggestions');
        }
    }

    /**
     * Normalise le résultat de l'IA pour correspondre au format attendu
     */
    private function normalizeAIResult(array $aiResult): array
    {
        $matched = $aiResult['matched'] ?? [];
        
        return [
            'method' => 'ai',
            'correspondent_id' => $matched['correspondent_id'] ?? null,
            'correspondent_name' => $aiResult['correspondent'] ?? null,
            'document_type_id' => $matched['document_type_id'] ?? null,
            'document_type_name' => $aiResult['document_type'] ?? null,
            'tag_ids' => $matched['tag_ids'] ?? [],
            'tag_names' => $aiResult['tags'] ?? [],
            'doc_date' => $aiResult['document_date'] ?? null,
            'amount' => $aiResult['amount'] ?? null,
            'currency' => null,
            'confidence' => $aiResult['confidence'] ?? 0.7,
            'summary' => $aiResult['summary'] ?? null,
            'additional_categories' => $aiResult['additional_categories'] ?? [],
        ];
    }
    
    /**
     * GET /api/documents/{id}/content
     * Get document text content (OCR)
     */
    public function content(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT id, ocr_text, content FROM documents WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        return $this->successResponse($response, [
            'document_id' => $id,
            'content' => $doc['ocr_text'] ?? $doc['content'] ?? '',
            'has_content' => !empty($doc['ocr_text']) || !empty($doc['content']),
        ]);
    }

    /**
     * GET /api/documents/{id}/thumbnail
     * Get document thumbnail URL or redirect
     */
    public function thumbnail(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $thumbPath = __DIR__ . '/../../../storage/thumbnails/' . $id . '_thumb.png';

        if (!file_exists($thumbPath)) {
            return $this->errorResponse($response, 'Thumbnail non trouvé', 404);
        }

        // Return the thumbnail file
        $response = $response->withHeader('Content-Type', 'image/png');
        $response = $response->withHeader('Cache-Control', 'public, max-age=86400');
        $response->getBody()->write(file_get_contents($thumbPath));

        return $response;
    }

    /**
     * GET /api/documents/{id}/download
     * Download document file
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $doc = Document::findById($id);

        if (!$doc || $doc['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        $filePath = $doc['file_path'];
        if (!file_exists($filePath)) {
            return $this->errorResponse($response, 'Fichier non trouvé', 404);
        }

        $filename = $doc['original_filename'] ?? basename($filePath);
        $mimeType = $doc['mime_type'] ?? mime_content_type($filePath) ?? 'application/octet-stream';

        $response = $response->withHeader('Content-Type', $mimeType);
        $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response = $response->withHeader('Content-Length', filesize($filePath));
        $response->getBody()->write(file_get_contents($filePath));

        return $response;
    }

    /**
     * POST /api/documents/{id}/ocr
     * Trigger OCR processing for a document
     */
    public function triggerOcr(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $doc = Document::findById($id);

        if (!$doc || $doc['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        $filePath = $doc['file_path'];
        if (!file_exists($filePath)) {
            return $this->errorResponse($response, 'Fichier non trouvé', 404);
        }

        try {
            $ocrService = new \KDocs\Services\OCRService();
            $text = $ocrService->extractText($filePath);

            if ($text) {
                $db = Database::getInstance();
                $stmt = $db->prepare("UPDATE documents SET ocr_text = ?, content = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$text, $text, $id]);

                return $this->successResponse($response, [
                    'document_id' => $id,
                    'text_length' => strlen($text),
                    'preview' => mb_substr($text, 0, 500) . (strlen($text) > 500 ? '...' : ''),
                ], 'OCR terminé avec succès');
            }

            return $this->errorResponse($response, 'OCR n\'a pas pu extraire de texte');

        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur OCR: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/documents/{id}/tags
     * Add tags to a document
     */
    public function addTags(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        $tagIds = $data['tag_ids'] ?? [];

        if (!is_array($tagIds) || empty($tagIds)) {
            return $this->errorResponse($response, 'tag_ids requis (array)');
        }

        $db = Database::getInstance();

        try {
            foreach ($tagIds as $tagId) {
                $tagId = (int)$tagId;
                if ($tagId > 0) {
                    $stmt = $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)");
                    $stmt->execute([$id, $tagId]);
                }
            }

            // Get updated tags
            $stmt = $db->prepare("SELECT t.id, t.name, t.color FROM tags t INNER JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
            $stmt->execute([$id]);
            $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->successResponse($response, ['tags' => $tags], 'Tags ajoutés');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/documents/{id}/tags/{tagId}
     * Remove a tag from a document
     */
    public function removeTag(Request $request, Response $response, array $args): Response
    {
        $docId = (int)$args['id'];
        $tagId = (int)$args['tagId'];

        $db = Database::getInstance();

        try {
            $stmt = $db->prepare("DELETE FROM document_tags WHERE document_id = ? AND tag_id = ?");
            $stmt->execute([$docId, $tagId]);

            return $this->successResponse($response, null, 'Tag retiré');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/documents/{id}/type
     * Update document type
     */
    public function updateType(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        $typeId = isset($data['document_type_id']) ? (int)$data['document_type_id'] : null;

        $db = Database::getInstance();

        try {
            $stmt = $db->prepare("UPDATE documents SET document_type_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$typeId ?: null, $id]);

            return $this->successResponse($response, ['document_type_id' => $typeId], 'Type mis à jour');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/documents/{id}/correspondent
     * Update document correspondent
     */
    public function updateCorrespondent(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        $correspondentId = isset($data['correspondent_id']) ? (int)$data['correspondent_id'] : null;

        $db = Database::getInstance();

        try {
            $stmt = $db->prepare("UPDATE documents SET correspondent_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$correspondentId ?: null, $id]);

            return $this->successResponse($response, ['correspondent_id' => $correspondentId], 'Correspondant mis à jour');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/documents/{id}/fields
     * Update multiple document fields at once
     */
    public function updateFields(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true);

        $allowedFields = ['title', 'document_type_id', 'correspondent_id', 'document_date', 'amount', 'currency'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $value = $data[$field];

                // Handle nullable integers
                if (in_array($field, ['document_type_id', 'correspondent_id'])) {
                    $value = $value ? (int)$value : null;
                }
                // Handle nullable floats
                if ($field === 'amount') {
                    $value = $value ? (float)$value : null;
                }

                $params[] = $value;
            }
        }

        if (empty($updates)) {
            return $this->errorResponse($response, 'Aucun champ à mettre à jour');
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;

        $db = Database::getInstance();

        try {
            $sql = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $updated = Document::findById($id);
            return $this->successResponse($response, $this->formatDocument($updated), 'Document mis à jour');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/documents/{id}/classify
     * Trigger classification for a document
     */
    public function classify(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = json_decode($request->getBody()->getContents(), true) ?? [];
        $apply = $data['apply'] ?? false;

        $doc = Document::findById($id);
        if (!$doc || $doc['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        try {
            $classificationService = new \KDocs\Services\ClassificationService();
            $result = $classificationService->classify($id);

            if ($apply && !empty($result['final'])) {
                // Apply classification
                $db = Database::getInstance();
                $updates = [];
                $params = [];

                if (!empty($result['final']['document_type_id'])) {
                    $updates[] = 'document_type_id = ?';
                    $params[] = $result['final']['document_type_id'];
                }
                if (!empty($result['final']['correspondent_id'])) {
                    $updates[] = 'correspondent_id = ?';
                    $params[] = $result['final']['correspondent_id'];
                }
                if (!empty($result['final']['doc_date'])) {
                    $updates[] = 'document_date = ?';
                    $params[] = $result['final']['doc_date'];
                }

                if (!empty($updates)) {
                    $updates[] = 'classification_suggestions = ?';
                    $params[] = json_encode($result);
                    $updates[] = 'updated_at = NOW()';
                    $params[] = $id;

                    $sql = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }

                // Add tags if any
                if (!empty($result['final']['tag_ids'])) {
                    foreach ($result['final']['tag_ids'] as $tagId) {
                        $stmt = $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)");
                        $stmt->execute([$id, $tagId]);
                    }
                }
            }

            return $this->successResponse($response, [
                'document_id' => $id,
                'classification' => $result,
                'applied' => $apply,
            ], $apply ? 'Classification appliquée' : 'Classification terminée');

        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur classification: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Formate un document pour l'API
     */
    private function formatDocument(array $document): array
    {
        $config = \KDocs\Core\Config::load();
        $basePath = rtrim($config['app']['url'] ?? '', '/');

        return [
            'id' => (int)$document['id'],
            'title' => $document['title'],
            'filename' => $document['filename'],
            'original_filename' => $document['original_filename'],
            'file_path' => $document['file_path'],
            'file_size' => (int)$document['file_size'],
            'mime_type' => $document['mime_type'],
            'document_type_id' => $document['document_type_id'] ? (int)$document['document_type_id'] : null,
            'document_type_label' => $document['document_type_label'] ?? null,
            'correspondent_id' => $document['correspondent_id'] ? (int)$document['correspondent_id'] : null,
            'correspondent_name' => $document['correspondent_name'] ?? null,
            'document_date' => $document['document_date'],
            'amount' => $document['amount'] ? (float)$document['amount'] : null,
            'currency' => $document['currency'],
            'created_at' => $document['created_at'],
            'updated_at' => $document['updated_at'],
            'asn' => $document['asn'] ? (int)$document['asn'] : null,
            'thumbnail_url' => $basePath . '/documents/' . $document['id'] . '/thumbnail',
            'view_url' => $basePath . '/documents/' . $document['id'] . '/view',
            'download_url' => $basePath . '/documents/' . $document['id'] . '/download',
        ];
    }
}
