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

        // Isolation multi-mandant (GAP-041) — inactif par défaut (MULTI_TENANT_ENABLED),
        // et neutralisé si la migration tenant_id n'est pas appliquée.
        $tenantScope = $this->makeTenantScopeService();
        $currentUser = $request->getAttribute('user');
        if ($tenantScope->isEnabled() && is_array($currentUser) && $this->tenantColumnExists($db)) {
            $scope = $tenantScope->scopeSql('d', $currentUser);
            if ($scope['sql'] !== '') {
                $where[] = $scope['sql'];
                $params = array_merge($params, $scope['params']);
            }
        }

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

        // Isolation multi-mandant (GAP-041) : 404 (pas 403) pour ne pas révéler
        // l'existence d'un document d'un autre mandant.
        $tenantScope = $this->makeTenantScopeService();
        if ($tenantScope->isEnabled() && is_array($user) && !$tenantScope->canSee($user, $document)) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        // ACL par dossier (securite-acl) : 404 comme ci-dessus, pour ne pas
        // revéler l'existence d'un document qu'on n'a pas le droit de lire.
        if (!$this->peutAccederAuDocument($user, $document, 'read')) {
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

        // ACL par dossier (securite-acl) — droit d'ecriture.
        if (!$this->peutAccederAuDocument($request->getAttribute('user'), $document, 'write')) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        if ($sealed = $this->guardNotSealed($id, $response)) {
            return $sealed;
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
                // Tronquer le texte si nécessaire avant insertion (limite TEXT MySQL: 65,535)
                $ocrText = $data['ocr_text'];
                if (mb_strlen($ocrText) > 65000) {
                    $originalLength = mb_strlen($ocrText);
                    $ocrText = mb_substr($ocrText, 0, 65000);
                    error_log("DocumentsApiController: OCR text tronqué de {$originalLength} à 65000 caractères pour document {$id}");
                }
                
                $updateFields[] = 'ocr_text = ?';
                $updateFields[] = 'content = ?';
                $updateParams[] = $ocrText;
                $updateParams[] = $ocrText;
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
            // Auto-apprentissage : enregistre la correction de classification (type/correspondent).
            $this->recordClassificationCorrection($id, $document, $updated);
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

        // ACL par dossier (securite-acl) — mettre a la corbeille demande le
        // droit de suppression sur le dossier du document.
        $document = Document::findById($id);
        if ($document && !$this->peutAccederAuDocument($user, $document, 'delete')) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        if ($sealed = $this->guardNotSealed($id, $response)) {
            return $sealed;
        }

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
            return $this->errorResponse($response, 'Aucun service IA disponible (Claude ou Ollama). Vérifiez la configuration.');
        }
        
        // Vérifier le statut des providers avant classification
        $aiProvider = new \KDocs\Services\AIProviderService();
        $aiStatus = $aiProvider->getStatus();
        $usingFallback = $aiStatus['fallback_active'] ?? false;
        
        // FAIRE L'OCR AVANT L'ANALYSE IA si le contenu est vide ou insuffisant
        // Vérifier aussi si le contenu OCR contient une erreur (commence par "OCR échoué" ou "Erreur OCR")
        $hasContent = !empty($document['content']) && strlen(trim($document['content'])) > 10;
        $hasOcrText = !empty($document['ocr_text']) && strlen(trim($document['ocr_text'])) > 10;
        $hasOcrError = !empty($document['ocr_text']) && (
            strpos($document['ocr_text'], 'OCR échoué') === 0 ||
            strpos($document['ocr_text'], 'Erreur OCR') === 0
        );
        
        // Faire OCR si : pas de contenu OU contenu trop court OU erreur OCR précédente
        $needsOcr = (!$hasContent && !$hasOcrText) || $hasOcrError || 
                    ($hasOcrText && strlen(trim($document['ocr_text'])) <= 10);
        
        if ($needsOcr) {
            $filePath = $document['file_path'] ?? null;
            if ($filePath && file_exists($filePath)) {
                $fileSize = filesize($filePath);
                error_log("DocumentsApiController::classifyWithAI: OCR requis pour document {$id} (taille fichier: " . number_format($fileSize) . " bytes, contenu actuel: " . ($hasContent ? strlen($document['content']) : 0) . " chars)");
                
                try {
                    // Timeout pour éviter de bloquer trop longtemps (30 secondes max)
                    set_time_limit(30);
                    
                    $ocrService = new \KDocs\Services\OCRService();
                    $startTime = microtime(true);
                    $ocrText = $ocrService->extractText($filePath);
                    $duration = round(microtime(true) - $startTime, 2);
                    
                    if (!empty($ocrText) && strlen(trim($ocrText)) > 10) {
                        // Nettoyer le texte OCR
                        $ocrText = mb_convert_encoding($ocrText, 'UTF-8', 'UTF-8');
                        $ocrText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $ocrText);
                        
                        // Tronquer le texte si nécessaire avant insertion
                        $originalLength = mb_strlen($ocrText);
                        if ($originalLength > 65000) {
                            $ocrText = mb_substr($ocrText, 0, 65000);
                            error_log("DocumentsApiController::classifyWithAI: OCR text tronqué de {$originalLength} à 65000 caractères pour document {$id}");
                        }
                        
                        // Mettre à jour le document avec le nouveau contenu OCR
                        $db = Database::getInstance();
                        $updateStmt = $db->prepare("UPDATE documents SET ocr_text = ?, content = ? WHERE id = ?");
                        $updateStmt->execute([$ocrText, $ocrText, $id]);
                        
                        // Recharger le document avec le nouveau contenu
                        $document = Document::findById($id);
                        error_log("DocumentsApiController::classifyWithAI: OCR réussi pour document {$id} ({$duration}s, " . strlen($ocrText) . " caractères extraits, fichier: " . number_format($fileSize) . " bytes)");
                    } else {
                        $errorMsg = "OCR n'a pas extrait de texte significatif (durée: {$duration}s, fichier: " . number_format($fileSize) . " bytes)";
                        error_log("DocumentsApiController::classifyWithAI: {$errorMsg} pour document {$id}");
                        
                        // Marquer l'erreur dans ocr_text pour éviter de réessayer à chaque fois
                        $db = Database::getInstance();
                        $updateStmt = $db->prepare("UPDATE documents SET ocr_text = ? WHERE id = ?");
                        $updateStmt->execute(["OCR échoué: {$errorMsg}", $id]);
                    }
                } catch (\Exception $e) {
                    $errorMsg = "Erreur OCR: " . $e->getMessage();
                    error_log("DocumentsApiController::classifyWithAI: {$errorMsg} pour document {$id}");
                    error_log("DocumentsApiController::classifyWithAI: Stack trace: " . $e->getTraceAsString());
                    
                    // Marquer l'erreur dans ocr_text pour éviter de réessayer à chaque fois
                    try {
                        $db = Database::getInstance();
                        $updateStmt = $db->prepare("UPDATE documents SET ocr_text = ? WHERE id = ?");
                        $updateStmt->execute(["Erreur OCR: " . $e->getMessage(), $id]);
                    } catch (\Exception $e2) {
                        // Ignorer si la mise à jour échoue
                        error_log("DocumentsApiController::classifyWithAI: Impossible de marquer l'erreur OCR: " . $e2->getMessage());
                    }
                    
                    // Continuer quand même avec l'analyse IA (peut utiliser le fichier directement)
                } finally {
                    // Restaurer le timeout par défaut
                    set_time_limit(ini_get('max_execution_time'));
                }
            } else {
                error_log("DocumentsApiController::classifyWithAI: Fichier non trouvé pour document {$id}: " . ($filePath ?? 'null'));
            }
        } else {
            error_log("DocumentsApiController::classifyWithAI: OCR non requis pour document {$id} (contenu existant: " . strlen($document['content'] ?? '') . " chars)");
        }
        
        // --- Pré-suggestion heuristique (OCR > règles > IA) ---
        // L'IA est lente (~17-60s) ; on produit d'abord une suggestion heuristique
        // rapide (regex + mots-clés BDD, SANS IA) avec confidence. Si elle est assez
        // confiante ET trouve un type, on skippe l'IA (« mieux vaut OCR > IA »).
        // Sinon on appelle l'IA et on fusionne (l'IA l'emporte, l'heuristique bouche
        // les trous). La logique heuristique est couverte par AutoClassifierService::classifyRules.
        $auto = new \KDocs\Services\AutoClassifierService();
        $heuristic = $auto->classifyRules($id);
        $heuristicThreshold = (float) \env('CLASSIFY_HEURISTIC_THRESHOLD', 0.6);
        $skipAi = !empty($heuristic['document_type_id'])
            && !isset($heuristic['error'])
            && $heuristic['confidence'] >= $heuristicThreshold;

        $suggestions = null;
        if ($skipAi) {
            $suggestions = $this->heuristicToSuggestion($heuristic);
            error_log("DocumentsApiController::classifyWithAI: heuristic suffisante (confidence={$heuristic['confidence']}, type={$heuristic['document_type_name']}) — IA skippée pour doc {$id}");
        } else {
            $suggestions = $classifier->classify($id);
            if ($suggestions) {
                $suggestions = $this->mergeHeuristicIntoAi($suggestions, $heuristic);
            } else {
                // IA échouée : fallback sur la suggestion heuristique si elle a du contenu
                $suggestions = $this->heuristicToSuggestion($heuristic);
                $suggestions['_ai_failed'] = true;
                error_log("DocumentsApiController::classifyWithAI: IA échouée, fallback heuristique pour doc {$id}");
            }
        }

        // Vérifier qu'on a au moins un champ exploitable (sinon erreur claire)
        if (!$this->suggestionHasContent($suggestions, $document, $id)) {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT content, ocr_text, file_path FROM documents WHERE id = ?");
            $stmt->execute([$id]);
            $doc = $stmt->fetch();
            $hasContent = !empty($doc['content']) || !empty($doc['ocr_text']);
            $hasFile = !empty($doc['file_path']) && file_exists($doc['file_path']);

            if (!$hasContent && !$hasFile) {
                return $this->errorResponse($response, 'Impossible de classifier le document. Vérifiez que le document contient du texte.');
            }
            $errorMsg = 'Impossible de classifier le document. ';
            if (!empty($suggestions['_ai_failed'])) {
                $errorMsg .= $usingFallback
                    ? 'Claude indisponible et Ollama a échoué. Vérifiez que Ollama est démarré (ollama serve).'
                    : 'Les services IA ont échoué et l\'heuristique n\'a rien extrait. Vérifiez les logs.';
            } else {
                $errorMsg .= 'Aucune suggestion exploitable extraite du document.';
            }
            return $this->errorResponse($response, $errorMsg);
        }

        // Ajouter une info sur le provider/méthode utilisé dans la réponse
        $suggestions['_provider'] = $aiStatus['active_provider'] ?? ($suggestions['_method'] ?? 'unknown');
        if ($usingFallback && empty($suggestions['_method'])) {
            $suggestions['_fallback_used'] = true;
            $suggestions['_message'] = 'Classification effectuée via Ollama (Claude indisponible)';
        }

        // Confidence normalisée 0..1 + pourcentage (affichage UI)
        $conf = $this->extractConfidence($suggestions);
        $suggestions['confidence'] = $conf;
        $suggestions['confidence_pct'] = (int) round($conf * 100);

        // Persistance en BDD (plus seulement session) : JSON de suggestion + colonne
        // classification_confidence + métadonnées de classification (migration 023).
        $currentUser = $request->getAttribute('user');
        $this->persistClassification($id, $suggestions, $document, $currentUser['id'] ?? null);

        // Stocker temporairement les suggestions en session (pour apply-ai-suggestions)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['ai_suggestions_' . $id] = $suggestions;

        return $this->successResponse($response, [
            'suggestions' => $suggestions
        ], 'Classification réussie');
    }

    /**
     * Convertit un résultat heuristique AutoClassifier en structure de suggestion
     * compatible avec le front (champs IA + sous-objet `matched` d'IDs).
     */
    private function heuristicToSuggestion(array $h): array
    {
        return [
            'correspondent' => $h['correspondent_name'] ?? null,
            'document_type' => $h['document_type_name'] ?? null,
            'tags' => $h['tag_names'] ?? [],
            'document_date' => $h['doc_date'] ?? null,
            'amount' => $h['amount'] ?? null,
            'currency' => $h['currency'] ?? null,
            'title_suggestion' => null,
            'summary' => null,
            'confidence' => (float) ($h['confidence'] ?? 0),
            'additional_categories' => [],
            'matched' => [
                'correspondent_id' => $h['correspondent_id'] ?? null,
                'document_type_id' => $h['document_type_id'] ?? null,
                'tag_ids' => $h['tag_ids'] ?? [],
            ],
            '_method' => 'rules',
            '_skipped_ai' => !empty($h['document_type_id']),
        ];
    }

    /**
     * Fusionne la suggestion heuristique dans la suggestion IA : l'IA l'emporte,
     * l'heuristique bouche les trous (champs non résolus par l'IA).
     */
    private function mergeHeuristicIntoAi(array $ai, array $h): array
    {
        if (!isset($ai['matched']) || !is_array($ai['matched'])) {
            $ai['matched'] = ['correspondent_id' => null, 'document_type_id' => null, 'tag_ids' => []];
        }
        if (empty($ai['matched']['document_type_id']) && !empty($h['document_type_id'])) {
            $ai['matched']['document_type_id'] = $h['document_type_id'];
            $ai['document_type'] = $ai['document_type'] ?? $h['document_type_name'];
        }
        if (empty($ai['matched']['correspondent_id']) && !empty($h['correspondent_id'])) {
            $ai['matched']['correspondent_id'] = $h['correspondent_id'];
            $ai['correspondent'] = $ai['correspondent'] ?? $h['correspondent_name'];
        }
        if (empty($ai['matched']['tag_ids']) && !empty($h['tag_ids'])) {
            $ai['matched']['tag_ids'] = $h['tag_ids'];
            if (empty($ai['tags'])) {
                $ai['tags'] = $h['tag_names'];
            }
        }
        if (empty($ai['document_date']) && !empty($h['doc_date'])) {
            $ai['document_date'] = $h['doc_date'];
        }
        if (empty($ai['amount']) && !empty($h['amount'])) {
            $ai['amount'] = $h['amount'];
            $ai['currency'] = $ai['currency'] ?? ($h['currency'] ?? null);
        }
        $ai['_method'] = 'ai+rules';
        $ai['_heuristic_confidence'] = (float) ($h['confidence'] ?? 0);
        return $ai;
    }

    /**
     * Extrait une confidence normalisée 0..1 depuis une suggestion (IA ou heuristique).
     */
    private function extractConfidence(array $s): float
    {
        $c = $s['confidence'] ?? $s['_heuristic_confidence'] ?? 0;
        $c = (float) $c;
        if ($c < 0) $c = 0;
        if ($c > 1) $c = $c > 1 ? $c / 100 : 1; // tolère 0..100 → 0..1
        return $c;
    }

    /**
     * Une suggestion a-t-elle au moins un champ exploitable (type/correspondent/tags/date/mount/title) ?
     */
    private function suggestionHasContent(array $s, array $document, int $id): bool
    {
        $matched = $s['matched'] ?? [];
        if (!empty($matched['document_type_id']) || !empty($matched['correspondent_id'])
            || !empty($matched['tag_ids'])) {
            return true;
        }
        if (!empty($s['document_date']) || !empty($s['amount']) || !empty($s['title_suggestion'])) {
            return true;
        }
        // Si le doc a déjà un type appliqué, la suggestion n'est pas « vide » pour autant
        return false;
    }

    /**
     * Persiste la suggestion de classification en BDD :
     *  - documents.classification_suggestions (JSON)
     *  - documents.classification_confidence (DECIMAL 3,2)
     *  - documents.needs_review (si confidence faible)
     *  - documents.last_classified_at / last_classified_by (migration 023)
     *
     * Résilient : si une colonne n'existe pas sur la base, l'échec est loggé sans casser.
     */
    private function persistClassification(int $id, array $s, array $document, ?int $userId): void
    {
        try {
            $db = Database::getInstance();
            $conf = (float) ($s['confidence'] ?? 0);
            $needsReview = $conf < (float) \env('CLASSIFY_REVIEW_THRESHOLD', 0.6) ? 1 : 0;
            $payload = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Update défensif : on construit le SET selon les colonnes présentes.
            $sets = ['classification_suggestions = ?'];
            $params = [$payload];

            // Colonnes optionnelles (migration 023) — détectées via le schéma.
            $cols = $this->documentsColumns($db);
            if (in_array('classification_confidence', $cols, true)) {
                $sets[] = 'classification_confidence = ?';
                $params[] = round($conf, 2);
            }
            if (in_array('needs_review', $cols, true)) {
                $sets[] = 'needs_review = ?';
                $params[] = $needsReview;
            }
            if (in_array('last_classified_at', $cols, true)) {
                $sets[] = 'last_classified_at = NOW()';
            }
            if (in_array('last_classified_by', $cols, true)) {
                $sets[] = 'last_classified_by = ?';
                $params[] = $userId;
            }

            $params[] = $id;
            $db->prepare('UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($params);
        } catch (\Exception $e) {
            error_log("DocumentsApiController::persistClassification: {$e->getMessage()} pour doc {$id}");
        }
    }

    /** Liste des colonnes de la table documents (pour SET défensif). */
    private function documentsColumns(\PDO $db): array
    {
        try {
            $rows = $db->query("SHOW COLUMNS FROM documents")->fetchAll(\PDO::FETCH_ASSOC);
            return array_map(static fn($r) => $r['Field'], $rows);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Auto-apprentissage (Lot 3) : enregistre la correction utilisateur d'une
     * classification (changement de type et/ou correspondant) dans TrainingService,
     * pour que l'IA (AIProviderService::classifyDocument réutilise getTrainedClassification)
     * et l'AutoClassifier (Lot 4) réutilisent ce signal.
     *
     * Jamais bloquant : un échec/absence d'apprentissage ne doit pas casser la MAJ.
     * Désactivé quand CLASSIFY_LEARNING_ENABLED != 'true' (tests hermétiques).
     */
    private function recordClassificationCorrection(int $id, array $before, array $after): void
    {
        if (strtolower((string) \env('CLASSIFY_LEARNING_ENABLED', 'true')) !== 'true') {
            return;
        }
        try {
            $beforeType = $before['document_type_id'] ?? null;
            $afterType = $after['document_type_id'] ?? null;
            $beforeCorr = $before['correspondent_id'] ?? null;
            $afterCorr = $after['correspondent_id'] ?? null;

            $typeChanged = ((string)$beforeType !== (string)$afterType);
            $corrChanged = ((string)$beforeCorr !== (string)$afterCorr);
            if (!$typeChanged && !$corrChanged) return;
            if (!$afterType) return; // pas de classement final -> rien à apprendre

            $text = trim($before['content'] ?? $before['ocr_text'] ?? '');
            if ($text === '') return; // rien à apprendre sans texte OCR

            $suggestedType = $this->resolveDocumentTypeLabel($beforeType) ?? '';
            $correctedType = $this->resolveDocumentTypeLabel($afterType) ?? '';
            $correctedFields = [
                'correspondent' => $this->resolveCorrespondentName($afterCorr),
                'tags' => $this->resolveDocumentTagNames($id),
                'correction_kind' => $typeChanged ? 'type' : 'correspondent',
            ];

            $training = $this->makeTrainingService();
            $ok = $training->storeCorrection($text, $suggestedType, $correctedType, $correctedFields, $id);
            error_log("DocumentsApiController::recordClassificationCorrection: doc {$id} type '{$suggestedType}' -> '{$correctedType}' (recorded=" . ($ok ? '1' : '0') . ")");
        } catch (\Exception $e) {
            error_log("DocumentsApiController::recordClassificationCorrection: {$e->getMessage()} pour doc {$id}");
        }
    }

    /** Factory TrainingService (overridable en test pour injection d'un mock). */
    protected function makeTrainingService(): \KDocs\Services\TrainingService
    {
        return new \KDocs\Services\TrainingService();
    }

    /** Factory LegalArchiveService (overridable en test pour injection d'un mock). */
    protected function makeLegalArchiveService(): \KDocs\Services\Compliance\LegalArchiveService
    {
        return new \KDocs\Services\Compliance\LegalArchiveService();
    }

    /** Factory ESignatureService (overridable en test pour injection d'un mock — GAP-043). */
    protected function makeESignatureService(): \KDocs\Services\Compliance\ESignatureService
    {
        return new \KDocs\Services\Compliance\ESignatureService();
    }

    /**
     * POST /api/documents/{id}/sign — signe électroniquement un document (GAP-043).
     *
     * Retourne HTTP 200 avec la signature JSON ; 404 si le document est inconnu.
     * Idempotent : signer deux fois le même (document, user) retourne la signature
     * existante avec already_signed=true.
     */
    public function sign(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $user = $request->getAttribute('user');

        try {
            $service = $this->makeESignatureService();
            $result  = $service->sign($id, (int) ($user['id'] ?? 0));

            return $this->successResponse($response, $result, 'Document signé');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 404);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    /**
     * Fabrique surchargeable en test (GAP-041).
     */
    /**
     * Garde ACL par dossier — secteur securite-acl.
     *
     * FolderPermissionService est ouvert par defaut : sans regle sur la chaine
     * des dossiers, il autorise. Le brancher ne change donc rien au
     * comportement actuel (folder_permissions est vide) et ferme la porte des
     * qu'une regle est posee. Avant le 2026-08-08 il n'etait appele par aucune
     * ligne applicative : les droits n'existaient pas cote serveur.
     *
     * @see \Tests\Feature\FolderPermissionServerSideTest
     */
    protected function peutAccederAuDocument($user, array $document, string $action): bool
    {
        if (!is_array($user)) {
            return true; // pas d'utilisateur resolu : l'authentification a deja tranche
        }

        try {
            return $this->makeFolderPermissionService()->can($user, $document, $action);
        } catch (\Throwable $e) {
            // Table absente ou schema partiel : ouvert, comme le service lui-meme.
            error_log('FolderPermission: ' . $e->getMessage());
            return true;
        }
    }

    protected function makeFolderPermissionService(): \KDocs\Services\FolderPermissionService
    {
        return new \KDocs\Services\FolderPermissionService();
    }

    protected function makeTenantScopeService(): \KDocs\Services\TenantScopeService
    {
        return new \KDocs\Services\TenantScopeService();
    }

    /**
     * La colonne documents.tenant_id existe-t-elle ? (migration add_tenant_columns)
     * Absente → le scope multi-mandant est neutralisé plutôt que de casser le listing.
     */
    protected function tenantColumnExists(PDO $db): bool
    {
        try {
            $db->query('SELECT tenant_id FROM documents LIMIT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Garde WORM (GAP-024) : 403 si le document est scellé légalement.
     * Retourne la réponse 403 à renvoyer, ou null si l'écriture est permise.
     */
    protected function guardNotSealed(int $documentId, Response $response): ?Response
    {
        try {
            $this->makeLegalArchiveService()->assertWritable($documentId);
        } catch (\KDocs\Services\Compliance\LegalSealedException $e) {
            return $this->errorResponse($response, $e->getMessage(), 403);
        } catch (\Exception $e) {
            // Colonne absente (migration non appliquée) : ne pas bloquer l'écriture.
        }

        return null;
    }

    /**
     * POST /api/documents/{id}/legal-seal — scelle le document (WORM, P2).
     */
    public function legalSeal(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $document = Document::findById($id);

        if (!$document || $document['deleted_at']) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        $user = $request->getAttribute('user');

        try {
            $result = $this->makeLegalArchiveService()->seal($id, (int) ($user['id'] ?? 0) ?: null);

            return $this->successResponse($response, $result, 'Document scellé légalement', $result['already_sealed'] ? 200 : 201);
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    protected function resolveDocumentTypeLabel(?int $typeId): ?string
    {
        if (!$typeId) return null;
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT label FROM document_types WHERE id = ?");
            $stmt->execute([$typeId]);
            return ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) ? $r['label'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function resolveCorrespondentName(?int $corrId): ?string
    {
        if (!$corrId) return null;
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT name FROM correspondents WHERE id = ?");
            $stmt->execute([$corrId]);
            return ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) ? $r['name'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function resolveDocumentTagNames(int $id): array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT t.name FROM document_tags dt JOIN tags t ON t.id = dt.tag_id WHERE dt.document_id = ?");
            $stmt->execute([$id]);
            return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'name');
        } catch (\Exception $e) {
            return [];
        }
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
                        
                        // Tronquer le texte si nécessaire avant insertion
                        if (mb_strlen($ocrText) > 65000) {
                            $originalLength = mb_strlen($ocrText);
                            $ocrText = mb_substr($ocrText, 0, 65000);
                            error_log("DocumentsApiController: OCR text tronqué de {$originalLength} à 65000 caractères pour document {$documentId}");
                        }
                        
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

        $stmt = $db->prepare("SELECT id, folder_id, ocr_text, content FROM documents WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            return $this->errorResponse($response, 'Document non trouvé', 404);
        }

        // ACL par dossier (securite-acl) — le contenu OCR est du contenu.
        if (!$this->peutAccederAuDocument($request->getAttribute('user'), $doc, 'read')) {
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

        // ACL par dossier (securite-acl) — telecharger, c'est lire.
        if (!$this->peutAccederAuDocument($request->getAttribute('user'), $doc, 'read')) {
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
                // Tronquer le texte si nécessaire avant insertion
                if (mb_strlen($text) > 65000) {
                    $originalLength = mb_strlen($text);
                    $text = mb_substr($text, 0, 65000);
                    error_log("DocumentsApiController::ocr: Texte tronqué de {$originalLength} à 65000 caractères pour document {$id}");
                }
                
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

        if ($sealed = $this->guardNotSealed($id, $response)) {
            return $sealed;
        }

        $db = Database::getInstance();
        $before = Document::findById($id);

        try {
            $stmt = $db->prepare("UPDATE documents SET document_type_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$typeId ?: null, $id]);

            $after = Document::findById($id);
            $this->recordClassificationCorrection($id, $before ?: [], $after ?: []);
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

        if ($sealed = $this->guardNotSealed($id, $response)) {
            return $sealed;
        }

        $db = Database::getInstance();
        $before = Document::findById($id);

        try {
            $stmt = $db->prepare("UPDATE documents SET correspondent_id = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$correspondentId ?: null, $id]);

            $after = Document::findById($id);
            $this->recordClassificationCorrection($id, $before ?: [], $after ?: []);
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

        if ($sealed = $this->guardNotSealed($id, $response)) {
            return $sealed;
        }

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
            $async = filter_var($data['async'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $ingest = new \KDocs\Services\Classification\IngestClassificationService();

            if ($async) {
                $queued = $ingest->queue($id);
                return $this->successResponse($response, [
                    'document_id' => $id,
                    'queued' => $queued,
                    'pending_classification' => true,
                    'message' => $queued
                        ? 'Classification UnifiedClassifier enfilée'
                        : 'Échec enqueue classification',
                ]);
            }

            $result = $ingest->classify($id);

            if ($apply && !empty($result['classification']['category'])) {
                $db = Database::getInstance();
                $typeStmt = $db->prepare('SELECT id FROM document_types WHERE label LIKE ? LIMIT 1');
                $typeStmt->execute(['%' . $result['classification']['category'] . '%']);
                $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
                if ($type) {
                    $db->prepare('UPDATE documents SET document_type_id = ? WHERE id = ?')
                        ->execute([$type['id'], $id]);
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
            'is_indexed' => !empty($document['is_indexed']),
            'indexed_at' => $document['indexed_at'] ?? null,
            'embedding_status' => $document['embedding_status'] ?? null,
            'vector_updated_at' => $document['vector_updated_at'] ?? null,
        ];
    }
}
