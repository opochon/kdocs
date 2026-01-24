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
        $document = Document::findById($id);
        
        if (!$document || $document['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }
        
        // Récupérer les tags
        $db = Database::getInstance();
        $tags = [];
        try {
            $tagStmt = $db->prepare("SELECT t.id, t.name, t.color FROM tags t INNER JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
            $tagStmt->execute([$id]);
            $tags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {}
        
        $formatted = $this->formatDocument($document);
        $formatted['tags'] = $tags;
        
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
     * Formate un document pour l'API
     */
    private function formatDocument(array $document): array
    {
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
        ];
    }
}
