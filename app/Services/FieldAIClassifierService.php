<?php
/**
 * Service de classification IA pour champs individuels
 * Utilise Claude avec des prompts personnalisés par champ
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\ClassificationField;

class FieldAIClassifierService
{
    private ClaudeService $claude;
    private $db;
    
    public function __construct()
    {
        $this->claude = new ClaudeService();
        $this->db = Database::getInstance();
    }
    
    /**
     * Vérifie si l'IA est disponible
     */
    public function isAvailable(): bool
    {
        return $this->claude->isConfigured();
    }
    
    /**
     * Classifie un champ spécifique avec l'IA
     * 
     * @param int $documentId ID du document
     * @param array $field Configuration du champ de classification
     * @param string $documentText Texte du document à analyser
     * @return string|null Valeur extraite ou null
     */
    public function classifyField(int $documentId, array $field, string $documentText): ?string
    {
        if (!$this->isAvailable() || empty($field['use_ai']) || empty($field['ai_prompt'])) {
            return null;
        }
        
        // Construire le prompt personnalisé
        $prompt = $this->buildPrompt($field, $documentText);
        
        if (empty($prompt)) {
            return null;
        }
        
        // Envoyer à Claude (sans system prompt pour simplifier)
        $response = $this->claude->sendMessage($prompt);
        if (!$response) {
            return null;
        }
        
        // Extraire le texte de la réponse
        $text = $this->claude->extractText($response);
        if (empty($text)) {
            return null;
        }
        
        // Nettoyer la réponse (enlever markdown, JSON, etc.)
        $text = $this->cleanResponse($text);
        
        return !empty($text) ? trim($text) : null;
    }
    
    /**
     * Construit le prompt pour l'IA
     */
    private function buildPrompt(array $field, string $documentText): string
    {
        $fieldName = $field['field_name'] ?? 'champ';
        $fieldType = $field['field_type'] ?? 'custom';
        $customPrompt = $field['ai_prompt'] ?? '';
        
        // Si un prompt personnalisé est fourni, l'utiliser
        if (!empty($customPrompt)) {
            // Remplacer les variables
            $prompt = str_replace('{field_name}', $fieldName, $customPrompt);
            $prompt = str_replace('{field_type}', $fieldType, $prompt);
            
            // Ajouter le texte du document
            return $prompt . "\n\nTEXTE DU DOCUMENT:\n" . substr($documentText, 0, 8000);
        }
        
        // Prompt par défaut selon le type de champ
        $defaultPrompts = [
            'year' => "Extrais l'année du document depuis le texte. Réponds uniquement avec l'année (ex: 2026), sans explication.",
            'supplier' => "Extrais le nom du fournisseur ou correspondant depuis le texte. Réponds uniquement avec le nom, sans explication.",
            'type' => "Extrais le type de document depuis le texte (ex: Facture, Note de crédit, Contrat, Courrier). Réponds uniquement avec le type, sans explication.",
            'amount' => "Extrais le montant depuis le texte. Réponds uniquement avec le nombre (ex: 123.45), sans explication ni symbole.",
            'date' => "Extrais la date du document depuis le texte. Réponds uniquement avec la date au format YYYY-MM-DD, sans explication.",
        ];
        
        $basePrompt = $defaultPrompts[$fieldType] ?? "Extrais le {$fieldName} depuis le texte. Réponds uniquement avec la valeur, sans explication.";
        
        return $basePrompt . "\n\nTEXTE DU DOCUMENT:\n" . substr($documentText, 0, 8000);
    }
    
    /**
     * Nettoie la réponse de Claude
     */
    private function cleanResponse(string $text): string
    {
        // Enlever markdown code blocks
        $text = preg_replace('/^```[a-z]*\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        
        // Enlever JSON wrapper si présent
        $text = preg_replace('/^\{[^}]*"value"\s*:\s*"?([^"]+)"?\s*[^}]*\}/i', '$1', $text);
        
        // Enlever guillemets
        $text = trim($text, '"\'');
        
        // Enlever explications (lignes après la première réponse)
        $lines = explode("\n", $text);
        $firstLine = trim($lines[0]);
        
        // Si la première ligne semble être une réponse complète, la retourner
        if (!empty($firstLine) && strlen($firstLine) < 200) {
            return $firstLine;
        }
        
        return trim($text);
    }
    
    /**
     * Classifie tous les champs configurés avec IA pour un document
     * 
     * @param int $documentId ID du document
     * @return array Résultats par champ_code => valeur
     */
    public function classifyAllFields(int $documentId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        
        // Récupérer le document
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return [];
        }
        
        // Construire le texte du document
        $documentText = implode(' ', array_filter([
            $document['content'] ?? '',
            $document['ocr_text'] ?? '',
            $document['title'] ?? '',
            $document['original_filename'] ?? ''
        ]));
        
        if (empty($documentText)) {
            return [];
        }
        
        // Récupérer les champs configurés avec IA
        $fields = ClassificationField::getActive();
        $results = [];
        
        foreach ($fields as $field) {
            if (!empty($field['use_ai']) && !empty($field['ai_prompt'])) {
                $value = $this->classifyField($documentId, $field, $documentText);
                if ($value) {
                    $results[$field['field_code']] = $value;
                }
            }
        }
        
        return $results;
    }
}
