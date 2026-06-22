<?php
/**
 * K-Docs - Service de Classification IA
 * Utilise Claude pour classifier automatiquement les documents
 *
 * @deprecated since B0.12 (2026-06-22) — Ne plus instancier directement pour l'ingest.
 *             Point d'entrée canonique : {@see \KDocs\Services\Classifiers\UnifiedClassifier}
 *             via {@see \KDocs\Services\Classification\IngestClassificationService}.
 *             Ce service reste utilisé en interne par GedNativeClassifierAdapter (sans rupture).
 *             Voir docs/DEPRECATED-AI-CLASSIFIER.md
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Services\OCRService;

class AIClassifierService
{
    private ClaudeService $claude;
    private AIProviderService $aiProvider;
    
    public function __construct()
    {
        $this->claude = new ClaudeService();
        $this->aiProvider = new AIProviderService();
    }
    
    /**
     * Vérifie si la classification IA est disponible (Claude OU Ollama)
     */
    public function isAvailable(): bool
    {
        return $this->aiProvider->isAIAvailable();
    }
    
    /**
     * Classifier un document avec l'IA
     * 
     * @param int $documentId ID du document à classifier
     * @return array|null Suggestions de classification ou null en cas d'erreur
     */
    public function classify(int $documentId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return null;
        }
        
        // Utiliser le contenu OCR ou le titre comme texte à analyser
        $documentText = trim($document['content'] ?? $document['ocr_text'] ?? '');
        
        // Si pas de contenu texte significatif, essayer avec le fichier directement
        if (empty($documentText) || strlen($documentText) < 10) {
            // Si pas de contenu OCR, utiliser le titre et le nom de fichier comme dernier recours
            $documentText = trim(($document['title'] ?? '') . ' ' . ($document['original_filename'] ?? ''));
            
            // Si toujours vide ou très court, essayer avec le fichier directement (Claude peut lire PDF/images)
            if (empty($documentText) || strlen($documentText) < 10) {
                $filePath = $document['file_path'] ?? null;
                if ($filePath && file_exists($filePath)) {
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    // Si c'est un PDF ou une image, utiliser classifyWithFile qui envoie le fichier directement
                    if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                        error_log("AIClassifierService: No text content, using file-based classification for document {$documentId}");
                        return $this->classifyWithFile($documentId);
                    }
                }
                // Si pas de fichier ou format non supporté, retourner null
                error_log("AIClassifierService: No text content and cannot use file-based classification for document {$documentId}");
                return null;
            }
        }
        
        // Récupérer les entités existantes pour le contexte
        $tags = [];
        $correspondents = [];
        $types = [];
        
        try {
            $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll();
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
            $types = $db->query("SELECT id, label as name FROM document_types ORDER BY label")->fetchAll();
        } catch (\Exception $e) {
            // Tables peuvent ne pas exister encore
            error_log("AIClassifierService: Error fetching entities: " . $e->getMessage());
        }
        
        $tagList = !empty($tags) ? implode(', ', array_column($tags, 'name')) : 'Aucun';
        $corrList = !empty($correspondents) ? implode(', ', array_column($correspondents, 'name')) : 'Aucun';
        $typeList = !empty($types) ? implode(', ', array_column($types, 'name')) : 'Aucun';
        
        // Récupérer les champs de classification existants pour les exclure
        $classificationFields = [];
        try {
            $classificationFields = $db->query("SELECT field_code, field_name FROM classification_fields WHERE is_active = TRUE")->fetchAll();
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }
        
        $existingFieldCodes = array_column($classificationFields, 'field_code');
        $existingFieldNames = array_column($classificationFields, 'field_name');
        $excludedFields = array_merge($existingFieldCodes, $existingFieldNames);
        $excludedFieldsList = !empty($excludedFields) ? implode(', ', $excludedFields) : 'Aucun';
        
        // Construire le prompt système
        $systemPrompt = <<<PROMPT
Tu es un assistant spécialisé dans la classification de documents.
Tu dois analyser le contenu d'un document et suggérer :
- Un correspondant (expéditeur/émetteur du document)
- Un type de document
- Des tags pertinents
- La date du document (si visible)
- Un montant (si c'est une facture)
- Une synthèse du document : un résumé concis (2-4 phrases) des grandes lignes et points clés du document
- Des catégories supplémentaires : identifie les principales catégories/thèmes du document qui ne sont PAS déjà dans les champs de classification existants (mais exclut les dates). Ces catégories doivent être des concepts métier pertinents (ex: "droit", "tribunal", "décision juridique", "contrat", "assurance", etc.)

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
    "correspondent": "nom suggéré ou null",
    "document_type": "type suggéré ou null", 
    "tags": ["tag1", "tag2"],
    "document_date": "YYYY-MM-DD ou null",
    "amount": 123.45 ou null,
    "title_suggestion": "titre suggéré",
    "summary": "Synthèse concise du document en 2-4 phrases décrivant les grandes lignes et points clés",
    "confidence": 0.0 à 1.0,
    "additional_categories": ["catégorie1", "catégorie2", "catégorie3"]
}

La "summary" doit être une synthèse concise et structurée qui permet de comprendre rapidement les grandes lignes du document sans avoir à le lire en entier.
Les "additional_categories" doivent être des concepts métier pertinents extraits du document, mais qui ne correspondent pas aux champs de classification existants. Ne pas inclure de dates dans cette liste.
PROMPT;

        // Construire le prompt utilisateur
        $prompt = <<<PROMPT
Analyse ce document et classifie-le.

CORRESPONDANTS EXISTANTS : $corrList
TYPES EXISTANTS : $typeList  
TAGS EXISTANTS : $tagList
CHAMPS DE CLASSIFICATION EXISTANTS (à exclure des catégories supplémentaires) : $excludedFieldsList

CONTENU DU DOCUMENT :
$documentText

IMPORTANT : 
- Pour "summary", fournis une synthèse concise (2-4 phrases) qui résume les grandes lignes et points clés du document. Cette synthèse doit permettre de comprendre rapidement le contenu sans avoir à lire le document en entier.
- Pour "additional_categories", identifie 3 à 5 catégories/thèmes principaux du document qui ne sont PAS déjà dans les champs de classification existants. Ces catégories doivent être des concepts métier pertinents (ex: domaine juridique, type de procédure, secteur d'activité, etc.). N'inclus PAS les dates dans cette liste.
- Pour "tags", propose des tags pertinents basés sur le contenu du document. Utilise les tags existants si possible, sinon propose de nouveaux tags pertinents.

Réponds uniquement en JSON, sans texte supplémentaire.
PROMPT;

        // Utiliser AIProviderService qui gère automatiquement la cascade Claude > Ollama
        // Construire le prompt complet avec system prompt
        $fullPrompt = $prompt;
        if (!empty($systemPrompt)) {
            $fullPrompt = $systemPrompt . "\n\n" . $prompt;
        }
        
        $aiResponse = $this->aiProvider->complete($fullPrompt, ['max_tokens' => 2048]);
        
        if (!$aiResponse || empty($aiResponse['text'])) {
            error_log("AIClassifierService: All AI providers failed (Claude and Ollama)");
            return null;
        }
        
        $text = $aiResponse['text'];
        $usedProvider = $aiResponse['provider'] ?? 'unknown';
        
        // Logger le provider utilisé
        if ($usedProvider === 'ollama') {
            error_log("AIClassifierService: Classification successful via Ollama (Claude unavailable)");
        } else {
            error_log("AIClassifierService: Classification successful via " . $usedProvider);
        }
        
        // Parser le JSON de la réponse
        // Nettoyer le texte (enlever ```json si présent)
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);
        
        $result = json_decode($text, true);
        if (!$result || json_last_error() !== JSON_ERROR_NONE) {
            error_log("AIClassifierService: Invalid JSON response: $text");
            error_log("AIClassifierService: Raw response text: " . substr($text, 0, 500));
            return null;
        }
        
        // Debug: logger la réponse complète pour analyse
        error_log("AIClassifierService: Claude response - " . json_encode($result, JSON_UNESCAPED_UNICODE));
        
        // Matcher avec les entités existantes
        $result['matched'] = $this->matchWithExisting($result, $tags, $correspondents, $types);
        
        return $result;
    }
    
    /**
     * Classifier un document avec l'IA en utilisant directement le fichier (sans OCR préalable)
     * 
     * @param int $documentId ID du document à classifier
     * @return array|null Suggestions de classification ou null en cas d'erreur
     */
    public function classifyWithFile(int $documentId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return null;
        }
        
        $filePath = $document['file_path'] ?? null;
        if (!$filePath || !file_exists($filePath)) {
            error_log("AIClassifierService: File not found for document $documentId");
            return null;
        }
        
        // Vérifier que c'est un fichier supporté (PDF ou image)
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            error_log("AIClassifierService: Unsupported file type: $ext");
            return null;
        }
        
        // Récupérer les entités existantes pour le contexte
        $tags = [];
        $correspondents = [];
        $types = [];
        
        try {
            $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll();
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
            $types = $db->query("SELECT id, label as name FROM document_types ORDER BY label")->fetchAll();
        } catch (\Exception $e) {
            error_log("AIClassifierService: Error fetching entities: " . $e->getMessage());
        }
        
        $tagList = !empty($tags) ? implode(', ', array_column($tags, 'name')) : 'Aucun';
        $corrList = !empty($correspondents) ? implode(', ', array_column($correspondents, 'name')) : 'Aucun';
        $typeList = !empty($types) ? implode(', ', array_column($types, 'name')) : 'Aucun';
        
        // Récupérer les champs de classification existants
        $classificationFields = [];
        try {
            $classificationFields = $db->query("SELECT field_code, field_name FROM classification_fields WHERE is_active = TRUE")->fetchAll();
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }
        
        $existingFieldCodes = array_column($classificationFields, 'field_code');
        $existingFieldNames = array_column($classificationFields, 'field_name');
        $excludedFields = array_merge($existingFieldCodes, $existingFieldNames);
        $excludedFieldsList = !empty($excludedFields) ? implode(', ', $excludedFields) : 'Aucun';
        
        // Construire le prompt système (identique à classify)
        $systemPrompt = <<<PROMPT
Tu es un assistant spécialisé dans la classification de documents.
Tu dois analyser le contenu d'un document et suggérer :
- Un correspondant (expéditeur/émetteur du document)
- Un type de document
- Des tags pertinents
- La date du document (si visible)
- Un montant (si c'est une facture)
- Une synthèse du document : un résumé concis (2-4 phrases) des grandes lignes et points clés du document
- Des catégories supplémentaires : identifie les principales catégories/thèmes du document qui ne sont PAS déjà dans les champs de classification existants (mais exclut les dates). Ces catégories doivent être des concepts métier pertinents (ex: "droit", "tribunal", "décision juridique", "contrat", "assurance", etc.)

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
    "correspondent": "nom suggéré ou null",
    "document_type": "type suggéré ou null", 
    "tags": ["tag1", "tag2"],
    "document_date": "YYYY-MM-DD ou null",
    "amount": 123.45 ou null,
    "title_suggestion": "titre suggéré",
    "summary": "Synthèse concise du document en 2-4 phrases décrivant les grandes lignes et points clés",
    "confidence": 0.0 à 1.0,
    "additional_categories": ["catégorie1", "catégorie2", "catégorie3"]
}

La "summary" doit être une synthèse concise et structurée qui permet de comprendre rapidement les grandes lignes du document sans avoir à le lire en entier.
Les "additional_categories" doivent être des concepts métier pertinents extraits du document, mais qui ne correspondent pas aux champs de classification existants. Ne pas inclure de dates dans cette liste.
PROMPT;

        // Construire le prompt utilisateur
        $prompt = <<<PROMPT
Analyse ce document (fichier joint) et classifie-le.

CORRESPONDANTS EXISTANTS : $corrList
TYPES EXISTANTS : $typeList  
TAGS EXISTANTS : $tagList
CHAMPS DE CLASSIFICATION EXISTANTS (à exclure des catégories supplémentaires) : $excludedFieldsList

IMPORTANT : 
- Pour "summary", fournis une synthèse concise (2-4 phrases) qui résume les grandes lignes et points clés du document. Cette synthèse doit permettre de comprendre rapidement le contenu sans avoir à lire le document en entier.
- Pour "additional_categories", identifie 3 à 5 catégories/thèmes principaux du document qui ne sont PAS déjà dans les champs de classification existants. Ces catégories doivent être des concepts métier pertinents (ex: domaine juridique, type de procédure, secteur d'activité, etc.). N'inclus PAS les dates dans cette liste.
- Pour "tags", propose des tags pertinents basés sur le contenu du document. Utilise les tags existants si possible, sinon propose de nouveaux tags pertinents.

Réponds uniquement en JSON, sans texte supplémentaire.
PROMPT;

        // Envoyer le fichier directement à Claude
        $response = $this->claude->sendMessageWithFile($prompt, $filePath, $systemPrompt);
        
        // Vérifier si Claude a retourné une erreur structurée
        if (!$response || (isset($response['error']) && $response['error'] === true)) {
            $shouldFallback = isset($response['should_fallback']) && $response['should_fallback'] === true;
            $httpCode = $response['http_code'] ?? 0;
            $errorMsg = $response['message'] ?? 'Unknown error';
            
            if ($shouldFallback) {
                error_log("AIClassifierService: Claude file upload failed (HTTP $httpCode: $errorMsg), trying text extraction + Ollama");
            } else {
                error_log("AIClassifierService: Claude file upload failed (HTTP $httpCode: $errorMsg)");
            }
            
            // Si fallback recommandé ou si Claude a échoué, essayer avec Ollama
            if ($shouldFallback || !$response) {
                // Essayer d'extraire le texte du fichier pour utiliser Ollama
                $ocrService = new OCRService();
                $extractedText = $ocrService->extractText($filePath);
                
                if (!empty($extractedText) && strlen(trim($extractedText)) > 10) {
                    // Utiliser le texte extrait avec Ollama via AIProviderService
                    $fullPrompt = $prompt . "\n\nCONTENU DU FICHIER:\n" . substr($extractedText, 0, 8000);
                    if (!empty($systemPrompt)) {
                        $fullPrompt = $systemPrompt . "\n\n" . $fullPrompt;
                    }
                    $aiResponse = $this->aiProvider->complete($fullPrompt, ['max_tokens' => 2048]);
                    if ($aiResponse && !empty($aiResponse['text'])) {
                        $text = $aiResponse['text'];
                        $provider = $aiResponse['provider'] ?? 'unknown';
                        error_log("AIClassifierService: Successfully using $provider provider with extracted text");
                    } else {
                        error_log("AIClassifierService: Ollama fallback also failed");
                        return null;
                    }
                } else {
                    error_log("AIClassifierService: Cannot extract text from file for Ollama fallback");
                    return null;
                }
            } else {
                return null;
            }
        } else {
            $text = $this->claude->extractText($response);
            if (empty($text)) {
                error_log("AIClassifierService: Claude response empty, trying Ollama fallback");
                // Essayer Ollama avec extraction texte
                $ocrService = new OCRService();
                $extractedText = $ocrService->extractText($filePath);
                if (!empty($extractedText) && strlen(trim($extractedText)) > 10) {
                    $fullPrompt = $prompt . "\n\nCONTENU DU FICHIER:\n" . substr($extractedText, 0, 8000);
                    if (!empty($systemPrompt)) {
                        $fullPrompt = $systemPrompt . "\n\n" . $fullPrompt;
                    }
                    $aiResponse = $this->aiProvider->complete($fullPrompt, ['max_tokens' => 2048]);
                    if ($aiResponse && !empty($aiResponse['text'])) {
                        $text = $aiResponse['text'];
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            }
        }
        
        // Parser le JSON de la réponse
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);
        
        $result = json_decode($text, true);
        if (!$result || json_last_error() !== JSON_ERROR_NONE) {
            error_log("AIClassifierService: Invalid JSON response: $text");
            error_log("AIClassifierService: Raw response text: " . substr($text, 0, 500));
            return null;
        }
        
        error_log("AIClassifierService: AI response (with file) - " . json_encode($result, JSON_UNESCAPED_UNICODE));
        
        // Matcher avec les entités existantes
        $result['matched'] = $this->matchWithExisting($result, $tags, $correspondents, $types);
        
        return $result;
    }
    
    /**
     * Analyser un document complexe avec l'IA en utilisant directement le fichier
     * Cette méthode effectue une analyse plus approfondie pour les documents complexes
     * 
     * @param int $documentId ID du document à classifier
     * @return array|null Suggestions de classification ou null en cas d'erreur
     */
    public function classifyComplexWithFile(int $documentId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return null;
        }
        
        $filePath = $document['file_path'] ?? null;
        if (!$filePath || !file_exists($filePath)) {
            error_log("AIClassifierService: File not found for document $documentId");
            return null;
        }
        
        // Vérifier que c'est un fichier supporté (PDF ou image)
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            error_log("AIClassifierService: Unsupported file type: $ext");
            return null;
        }
        
        // Récupérer les entités existantes pour le contexte
        $tags = [];
        $correspondents = [];
        $types = [];
        
        try {
            $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll();
            $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();
            $types = $db->query("SELECT id, label as name FROM document_types ORDER BY label")->fetchAll();
        } catch (\Exception $e) {
            error_log("AIClassifierService: Error fetching entities: " . $e->getMessage());
        }
        
        $tagList = !empty($tags) ? implode(', ', array_column($tags, 'name')) : 'Aucun';
        $corrList = !empty($correspondents) ? implode(', ', array_column($correspondents, 'name')) : 'Aucun';
        $typeList = !empty($types) ? implode(', ', array_column($types, 'name')) : 'Aucun';
        
        // Récupérer les champs de classification existants
        $classificationFields = [];
        try {
            $classificationFields = $db->query("SELECT field_code, field_name FROM classification_fields WHERE is_active = TRUE")->fetchAll();
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }
        
        $existingFieldCodes = array_column($classificationFields, 'field_code');
        $existingFieldNames = array_column($classificationFields, 'field_name');
        $excludedFields = array_merge($existingFieldCodes, $existingFieldNames);
        $excludedFieldsList = !empty($excludedFields) ? implode(', ', $excludedFields) : 'Aucun';
        
        // Construire le prompt système pour analyse complexe (plus détaillé)
        $systemPrompt = <<<PROMPT
Tu es un expert en analyse de documents complexes. Tu dois effectuer une analyse approfondie et détaillée du document fourni.

Pour les documents complexes (juridiques, techniques, administratifs, etc.), tu dois :
1. Analyser en profondeur le contexte et la structure du document
2. Identifier tous les éléments pertinents même s'ils sont implicites ou dispersés
3. Extraire des informations détaillées sur le correspondant, le type, les tags, la date, le montant
4. Fournir une synthèse complète et structurée qui capture tous les aspects importants du document
5. Identifier des catégories supplémentaires pertinentes qui reflètent la complexité du document
6. Extraire le texte complet du document si possible (pour les documents où l'OCR a échoué ou est incomplet)
7. Calculer un taux de confiance précis basé sur la qualité et la complétude de l'analyse

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
    "correspondent": "nom suggéré ou null",
    "document_type": "type suggéré ou null", 
    "tags": ["tag1", "tag2"],
    "document_date": "YYYY-MM-DD ou null",
    "amount": 123.45 ou null,
    "title_suggestion": "titre suggéré",
    "summary": "Synthèse détaillée et complète du document (4-8 phrases) qui capture tous les aspects importants, le contexte, les décisions prises, les parties impliquées, et les points clés",
    "confidence": 0.0 à 1.0 (basé sur la qualité de l'analyse),
    "additional_categories": ["catégorie1", "catégorie2", "catégorie3"],
    "extracted_text": "Texte complet extrait du document (si l'OCR a échoué ou est incomplet, fournis le texte complet du document ici)"
}

La "summary" doit être une synthèse détaillée et structurée qui permet de comprendre complètement le document sans avoir à le lire. Elle doit inclure le contexte, les décisions, les parties impliquées, et tous les points clés.
Les "additional_categories" doivent être des concepts métier pertinents qui reflètent la complexité et la nature du document.
Le "extracted_text" doit contenir le texte complet du document si l'OCR a échoué ou si le texte OCR est incomplet. Si le texte OCR est déjà complet, tu peux mettre null.
Le "confidence" doit être calculé en fonction de la qualité et de la complétude de l'analyse (plus l'analyse est détaillée et précise, plus le taux de confiance doit être élevé).
PROMPT;

        // Construire le prompt utilisateur pour analyse complexe
        $prompt = <<<PROMPT
Analyse ce document complexe (fichier joint) en profondeur et classifie-le de manière détaillée.

CORRESPONDANTS EXISTANTS : $corrList
TYPES EXISTANTS : $typeList  
TAGS EXISTANTS : $tagList
CHAMPS DE CLASSIFICATION EXISTANTS (à exclure des catégories supplémentaires) : $excludedFieldsList

INSTRUCTIONS POUR L'ANALYSE COMPLEXE :
- Analyse TOUS les aspects du document : contexte, structure, contenu, implications
- Identifie les informations même si elles sont implicites ou dispersées dans le document
- Pour "summary", fournis une synthèse détaillée et complète (4-8 phrases) qui capture :
  * Le contexte général du document
  * Les parties impliquées
  * Les décisions, actions ou conclusions importantes
  * Les points clés et détails pertinents
  * Les implications ou conséquences si applicable
- Pour "additional_categories", identifie 5 à 8 catégories/thèmes principaux qui reflètent la complexité du document
- Pour "tags", propose des tags pertinents et détaillés basés sur une analyse approfondie
- Pour "extracted_text", si le document contient du texte lisible mais que l'OCR a échoué ou est incomplet, extrais TOUT le texte du document de manière complète et fidèle. Si le texte OCR est déjà complet, mets null.
- Pour "confidence", calcule un taux de confiance précis basé sur la qualité et la complétude de ton analyse (0.0 à 1.0)

Réponds uniquement en JSON, sans texte supplémentaire.
PROMPT;

        // Envoyer le fichier directement à Claude
        $response = $this->claude->sendMessageWithFile($prompt, $filePath, $systemPrompt);
        
        // Vérifier si Claude a retourné une erreur structurée
        if (!$response || (isset($response['error']) && $response['error'] === true)) {
            $shouldFallback = isset($response['should_fallback']) && $response['should_fallback'] === true;
            $httpCode = $response['http_code'] ?? 0;
            $errorMsg = $response['message'] ?? 'Unknown error';
            
            if ($shouldFallback) {
                error_log("AIClassifierService: Claude complex file upload failed (HTTP $httpCode: $errorMsg), trying text extraction + Ollama");
            } else {
                error_log("AIClassifierService: Claude complex file upload failed (HTTP $httpCode: $errorMsg)");
            }
            
            // Si fallback recommandé ou si Claude a échoué, essayer avec Ollama
            if ($shouldFallback || !$response) {
                // Essayer d'extraire le texte du fichier pour utiliser Ollama
                $ocrService = new OCRService();
                $extractedText = $ocrService->extractText($filePath);
                
                if (!empty($extractedText) && strlen(trim($extractedText)) > 10) {
                    // Utiliser le texte extrait avec Ollama via AIProviderService
                    $fullPrompt = $prompt . "\n\nCONTENU DU FICHIER:\n" . substr($extractedText, 0, 12000);
                    if (!empty($systemPrompt)) {
                        $fullPrompt = $systemPrompt . "\n\n" . $fullPrompt;
                    }
                    $aiResponse = $this->aiProvider->complete($fullPrompt, ['max_tokens' => 4096]);
                    if ($aiResponse && !empty($aiResponse['text'])) {
                        $text = $aiResponse['text'];
                        $provider = $aiResponse['provider'] ?? 'unknown';
                        error_log("AIClassifierService: Successfully using $provider provider for complex analysis");
                    } else {
                        error_log("AIClassifierService: Ollama fallback also failed for complex analysis");
                        return null;
                    }
                } else {
                    error_log("AIClassifierService: Cannot extract text from file for Ollama complex analysis fallback");
                    return null;
                }
            } else {
                return null;
            }
        } else {
            $text = $this->claude->extractText($response);
            if (empty($text)) {
                error_log("AIClassifierService: Claude complex response empty, trying Ollama fallback");
                // Essayer Ollama avec extraction texte
                $ocrService = new OCRService();
                $extractedText = $ocrService->extractText($filePath);
                if (!empty($extractedText) && strlen(trim($extractedText)) > 10) {
                    $fullPrompt = $prompt . "\n\nCONTENU DU FICHIER:\n" . substr($extractedText, 0, 12000);
                    if (!empty($systemPrompt)) {
                        $fullPrompt = $systemPrompt . "\n\n" . $fullPrompt;
                    }
                    $aiResponse = $this->aiProvider->complete($fullPrompt, ['max_tokens' => 4096]);
                    if ($aiResponse && !empty($aiResponse['text'])) {
                        $text = $aiResponse['text'];
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            }
        }
        
        // Parser le JSON de la réponse
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);
        
        $result = json_decode($text, true);
        if (!$result || json_last_error() !== JSON_ERROR_NONE) {
            error_log("AIClassifierService: Invalid JSON response (complex): $text");
            error_log("AIClassifierService: Raw response text: " . substr($text, 0, 500));
            return null;
        }
        
        error_log("AIClassifierService: AI response (complex analysis) - " . json_encode($result, JSON_UNESCAPED_UNICODE));
        
        // Matcher avec les entités existantes
        $result['matched'] = $this->matchWithExisting($result, $tags, $correspondents, $types);
        
        return $result;
    }
    
    /**
     * Matcher les suggestions avec les entités existantes
     * 
     * @param array $result Résultat de l'IA
     * @param array $tags Tags existants
     * @param array $correspondents Correspondants existants
     * @param array $types Types existants
     * @return array IDs correspondants
     */
    private function matchWithExisting(array $result, array $tags, array $correspondents, array $types): array
    {
        $matched = [
            'correspondent_id' => null,
            'document_type_id' => null,
            'tag_ids' => []
        ];
        
        // Matcher correspondent avec matching amélioré
        if (!empty($result['correspondent'])) {
            $suggestedCorr = mb_strtolower(trim($result['correspondent']));
            
            // Normaliser les noms (supprimer accents, espaces multiples, etc.)
            $normalize = function($str) {
                $str = mb_strtolower($str);
                $str = preg_replace('/\s+/', ' ', $str); // Espaces multiples → un seul
                $str = trim($str);
                return $str;
            };
            
            $normalizedSuggested = $normalize($suggestedCorr);
            
            // Essayer plusieurs stratégies de matching
            foreach ($correspondents as $corr) {
                $corrName = mb_strtolower(trim($corr['name']));
                $normalizedCorr = $normalize($corrName);
                
                // 1. Match exact (normalisé)
                if ($normalizedSuggested === $normalizedCorr) {
                    $matched['correspondent_id'] = $corr['id'];
                    break;
                }
                
                // 2. Match partiel (un contient l'autre)
                if (stripos($corrName, $suggestedCorr) !== false ||
                    stripos($suggestedCorr, $corrName) !== false) {
                    $matched['correspondent_id'] = $corr['id'];
                    break;
                }
                
                // 3. Match par mots-clés (si "Tribunal cantonal" suggéré, matcher "Tribunal" ou "cantonal")
                $suggestedWords = array_filter(explode(' ', $normalizedSuggested), fn($w) => mb_strlen($w) > 3);
                $corrWords = array_filter(explode(' ', $normalizedCorr), fn($w) => mb_strlen($w) > 3);
                
                if (!empty($suggestedWords) && !empty($corrWords)) {
                    $commonWords = array_intersect($suggestedWords, $corrWords);
                    // Si au moins 2 mots en commun ou 1 mot long (>5 chars)
                    if (count($commonWords) >= 2 || 
                        (count($commonWords) >= 1 && max(array_map('mb_strlen', $commonWords)) > 5)) {
                        $matched['correspondent_id'] = $corr['id'];
                        break;
                    }
                }
            }
            
            // Log si aucun match trouvé
            if (!$matched['correspondent_id']) {
                error_log("AIClassifierService: Aucun correspondant trouvé pour suggestion '{$result['correspondent']}'");
            }
        }
        
        // Matcher type
        if (!empty($result['document_type'])) {
            foreach ($types as $type) {
                if (stripos($type['name'], $result['document_type']) !== false ||
                    stripos($result['document_type'], $type['name']) !== false) {
                    $matched['document_type_id'] = $type['id'];
                    break;
                }
            }
        }
        
        // Matcher tags
        if (!empty($result['tags']) && is_array($result['tags'])) {
            foreach ($result['tags'] as $suggestedTag) {
                foreach ($tags as $tag) {
                    if (stripos($tag['name'], $suggestedTag) !== false ||
                        stripos($suggestedTag, $tag['name']) !== false) {
                        if (!in_array($tag['id'], $matched['tag_ids'])) {
                            $matched['tag_ids'][] = $tag['id'];
                        }
                        break;
                    }
                }
            }
        }
        
        return $matched;
    }
    
    /**
     * Appliquer les suggestions de l'IA à un document
     * 
     * @param int $documentId ID du document
     * @param array $suggestions Suggestions de l'IA
     * @return bool Succès de l'opération
     */
    public function applySuggestions(int $documentId, array $suggestions): bool
    {
        $db = Database::getInstance();
        
        $updates = [];
        $params = [];
        
        if (!empty($suggestions['matched']['correspondent_id'])) {
            $updates[] = "correspondent_id = ?";
            $params[] = $suggestions['matched']['correspondent_id'];
        }
        
        if (!empty($suggestions['matched']['document_type_id'])) {
            $updates[] = "document_type_id = ?";
            $params[] = $suggestions['matched']['document_type_id'];
        }
        
        if (!empty($suggestions['document_date'])) {
            $updates[] = "document_date = ?";
            $params[] = $suggestions['document_date'];
        }
        
        if (!empty($suggestions['amount']) && is_numeric($suggestions['amount'])) {
            $updates[] = "amount = ?";
            $params[] = (float)$suggestions['amount'];
        }
        
        if (!empty($suggestions['title_suggestion'])) {
            $updates[] = "title = COALESCE(?, title)";
            $params[] = $suggestions['title_suggestion'];
        }
        
        if (!empty($updates)) {
            $params[] = $documentId;
            $sql = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = ?";
            try {
                $db->prepare($sql)->execute($params);
            } catch (\Exception $e) {
                error_log("AIClassifierService: Error applying updates: " . $e->getMessage());
                return false;
            }
        }
        
        // Ajouter les tags
        if (!empty($suggestions['matched']['tag_ids'])) {
            foreach ($suggestions['matched']['tag_ids'] as $tagId) {
                try {
                    $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                       ->execute([$documentId, $tagId]);
                } catch (\Exception $e) {
                    // Ignorer les erreurs de tags (peut déjà exister)
                }
            }
        }
        
        return true;
    }
}
