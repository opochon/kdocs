<?php
/**
 * K-Docs - DocumentAddedTrigger
 * Déclenche le workflow quand un document est ajouté au système
 */

namespace KDocs\Workflow\Nodes\Triggers;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class DocumentAddedTrigger extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        // Un trigger valide toujours si on arrive ici
        // Le filtrage se fait dans shouldTrigger()
        return ExecutionResult::success([
            'trigger_type' => 'document_added',
            'document_id' => $context->documentId
        ]);
    }
    
    /**
     * Vérifie si ce trigger doit déclencher le workflow
     * Appelé par WorkflowEngine lors d'un événement
     */
    public static function shouldTrigger(array $config, int $documentId, array $eventContext = []): bool
    {
        $db = Database::getInstance();
        
        // Récupérer les infos du document
        $stmt = $db->prepare("
            SELECT d.*, dt.code as document_type_code, dt.label as document_type_label
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$document) {
            return false;
        }
        
        // Filtre par type de document
        if (!empty($config['filter_document_type_ids'])) {
            if (!in_array($document['document_type_id'], $config['filter_document_type_ids'])) {
                return false;
            }
        }
        
        // Filtre par code de type de document
        if (!empty($config['filter_document_type_codes'])) {
            if (!in_array($document['document_type_code'], $config['filter_document_type_codes'])) {
                return false;
            }
        }
        
        // Filtre par correspondant
        if (!empty($config['filter_correspondent_ids'])) {
            if (!in_array($document['correspondent_id'], $config['filter_correspondent_ids'])) {
                return false;
            }
        }
        
        // Filtre par montant minimum
        if (isset($config['filter_min_amount']) && $config['filter_min_amount'] !== null) {
            $amount = (float)($document['amount'] ?? 0);
            if ($amount < (float)$config['filter_min_amount']) {
                return false;
            }
        }
        
        // Filtre par montant maximum
        if (isset($config['filter_max_amount']) && $config['filter_max_amount'] !== null) {
            $amount = (float)($document['amount'] ?? 0);
            if ($amount > (float)$config['filter_max_amount']) {
                return false;
            }
        }
        
        // Filtre par pattern de nom de fichier
        if (!empty($config['filter_filename_pattern'])) {
            $filename = $document['original_filename'] ?? '';
            $pattern = $config['filter_filename_pattern'];
            // Convertir glob en regex
            $regex = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
            if (!preg_match('/^' . $regex . '$/i', $filename)) {
                return false;
            }
        }
        
        // Filtre par tags (le document doit avoir au moins un de ces tags)
        if (!empty($config['filter_tag_ids'])) {
            $stmt = $db->prepare("SELECT tag_id FROM document_tags WHERE document_id = ?");
            $stmt->execute([$documentId]);
            $documentTagIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            if (empty(array_intersect($documentTagIds, $config['filter_tag_ids']))) {
                return false;
            }
        }
        
        // Filtre par source (consume, upload, api)
        if (!empty($config['filter_source'])) {
            $source = $eventContext['source'] ?? $document['consume_subfolder'] ? 'consume' : 'upload';
            if ($source !== $config['filter_source']) {
                return false;
            }
        }
        
        return true;
    }
    
    public function getConfigSchema(): array
    {
        return [
            'filter_document_type_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Filtrer par IDs de types de document (déclenche si le document est d\'un de ces types)',
            ],
            'filter_document_type_codes' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Filtrer par codes de types (ex: FACTURE, CONTRAT)',
            ],
            'filter_correspondent_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Filtrer par IDs de correspondants',
            ],
            'filter_min_amount' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Montant minimum pour déclencher',
            ],
            'filter_max_amount' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Montant maximum pour déclencher',
            ],
            'filter_filename_pattern' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Pattern de nom de fichier (glob: *, ?)',
            ],
            'filter_tag_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Filtrer par tags (déclenche si le document a au moins un de ces tags)',
            ],
            'filter_source' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Source du document: consume, upload, api',
                'enum' => ['consume', 'upload', 'api']
            ],
        ];
    }
}
