<?php
/**
 * K-Docs - Service de Classification IA
 * Utilise Claude pour classifier automatiquement les documents
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class AIClassifierService
{
    private ClaudeService $claude;
    
    public function __construct()
    {
        $this->claude = new ClaudeService();
    }
    
    /**
     * Vérifie si la classification IA est disponible
     */
    public function isAvailable(): bool
    {
        return $this->claude->isConfigured();
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
        $documentText = $document['content'] ?? $document['ocr_text'] ?? '';
        if (empty($documentText)) {
            // Si pas de contenu OCR, utiliser le titre et le nom de fichier
            $documentText = ($document['title'] ?? '') . ' ' . ($document['original_filename'] ?? '');
        }
        
        if (empty($documentText)) {
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
            // Tables peuvent ne pas exister encore
            error_log("AIClassifierService: Error fetching entities: " . $e->getMessage());
        }
        
        $tagList = !empty($tags) ? implode(', ', array_column($tags, 'name')) : 'Aucun';
        $corrList = !empty($correspondents) ? implode(', ', array_column($correspondents, 'name')) : 'Aucun';
        $typeList = !empty($types) ? implode(', ', array_column($types, 'name')) : 'Aucun';
        
        // Construire le prompt système
        $systemPrompt = <<<PROMPT
Tu es un assistant spécialisé dans la classification de documents.
Tu dois analyser le contenu d'un document et suggérer :
- Un correspondant (expéditeur/émetteur du document)
- Un type de document
- Des tags pertinents
- La date du document (si visible)
- Un montant (si c'est une facture)

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
    "correspondent": "nom suggéré ou null",
    "document_type": "type suggéré ou null", 
    "tags": ["tag1", "tag2"],
    "document_date": "YYYY-MM-DD ou null",
    "amount": 123.45 ou null,
    "title_suggestion": "titre suggéré",
    "confidence": 0.0 à 1.0
}
PROMPT;

        // Construire le prompt utilisateur
        $prompt = <<<PROMPT
Analyse ce document et classifie-le.

CORRESPONDANTS EXISTANTS : $corrList
TYPES EXISTANTS : $typeList  
TAGS EXISTANTS : $tagList

CONTENU DU DOCUMENT :
$documentText

Réponds uniquement en JSON, sans texte supplémentaire.
PROMPT;

        $response = $this->claude->sendMessage($prompt, $systemPrompt);
        if (!$response) {
            return null;
        }
        
        $text = $this->claude->extractText($response);
        if (empty($text)) {
            return null;
        }
        
        // Parser le JSON de la réponse
        // Nettoyer le texte (enlever ```json si présent)
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);
        
        $result = json_decode($text, true);
        if (!$result || json_last_error() !== JSON_ERROR_NONE) {
            error_log("AIClassifierService: Invalid JSON response: $text");
            return null;
        }
        
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
        
        // Matcher correspondent
        if (!empty($result['correspondent'])) {
            foreach ($correspondents as $corr) {
                if (stripos($corr['name'], $result['correspondent']) !== false ||
                    stripos($result['correspondent'], $corr['name']) !== false) {
                    $matched['correspondent_id'] = $corr['id'];
                    break;
                }
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
