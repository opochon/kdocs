<?php
/**
 * K-Docs - AiExtractProcessor
 * Extrait les métadonnées avec IA (Claude/Ollama)
 */

namespace KDocs\Workflow\Nodes\Processing;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Services\AIClassifierService;
use KDocs\Core\Database;

class AiExtractProcessor extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$context->documentId]);
            $document = $stmt->fetch();
            
            if (!$document) {
                return ExecutionResult::failed('Document non trouvé');
            }
            
            $aiService = new AIClassifierService();
            $suggestions = $aiService->classifyDocument($context->documentId);
            
            // Ajouter les suggestions au contexte
            $context->set('ai_suggestions', $suggestions);
            
            return ExecutionResult::success([
                'suggestions' => $suggestions,
            ]);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur extraction IA: ' . $e->getMessage());
        }
    }
    
    public function getConfigSchema(): array
    {
        return [
            'provider' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Fournisseur IA (claude, ollama)',
                'default' => 'claude',
            ],
            'model' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Modèle à utiliser',
                'default' => 'claude-sonnet-4-20250514',
            ],
            'extract_fields' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Champs à extraire',
                'default' => ['categorie', 'expediteur', 'destinataire', 'montant', 'date'],
            ],
        ];
    }
}
