<?php
/**
 * K-Docs - NodeExecutorFactory
 * Factory pour instancier le bon executor selon le type de node
 * Version 2.0 - Style Alfresco complet
 */

namespace KDocs\Workflow\Nodes;

// Triggers
use KDocs\Workflow\Nodes\Triggers\ScanTrigger;
use KDocs\Workflow\Nodes\Triggers\UploadTrigger;
use KDocs\Workflow\Nodes\Triggers\ManualTrigger;
use KDocs\Workflow\Nodes\Triggers\DocumentAddedTrigger;
use KDocs\Workflow\Nodes\Triggers\TagAddedTrigger;
use KDocs\Workflow\Nodes\Triggers\ValidationStatusChangedTrigger;

// Processing
use KDocs\Workflow\Nodes\Processing\OcrProcessor;
use KDocs\Workflow\Nodes\Processing\AiExtractProcessor;
use KDocs\Workflow\Nodes\Processing\ClassifyProcessor;

// Conditions
use KDocs\Workflow\Nodes\Conditions\CategoryCondition;
use KDocs\Workflow\Nodes\Conditions\AmountCondition;
use KDocs\Workflow\Nodes\Conditions\TagCondition;
use KDocs\Workflow\Nodes\Conditions\FieldCondition;
use KDocs\Workflow\Nodes\Conditions\CorrespondentCondition;

// Actions
use KDocs\Workflow\Nodes\Actions\AssignUserAction;
use KDocs\Workflow\Nodes\Actions\AddTagAction;
use KDocs\Workflow\Nodes\Actions\SendEmailAction;
use KDocs\Workflow\Nodes\Actions\WebhookAction;
use KDocs\Workflow\Nodes\Actions\RequestApprovalAction;
use KDocs\Workflow\Nodes\Actions\CreateApprovalAction;
use KDocs\Workflow\Nodes\Actions\AssignToGroupAction;
use KDocs\Workflow\Nodes\Actions\SetValidationStatusAction;

// Waits
use KDocs\Workflow\Nodes\Waits\ApprovalWait;

// Timers
use KDocs\Workflow\Nodes\Timers\DelayTimer;

class NodeExecutorFactory
{
    /**
     * Catalogue complet des types de nodes disponibles
     */
    public const NODE_CATALOG = [
        // === DÉCLENCHEURS ===
        'triggers' => [
            'trigger_document_added' => [
                'class' => DocumentAddedTrigger::class,
                'name' => 'Document ajouté',
                'description' => 'Déclenche quand un document est ajouté au système',
                'icon' => 'document-add',
                'color' => '#3b82f6',
                'outputs' => ['default'],
            ],
            'trigger_tag_added' => [
                'class' => TagAddedTrigger::class,
                'name' => 'Tag ajouté',
                'description' => 'Déclenche quand un tag spécifique est ajouté',
                'icon' => 'tag',
                'color' => '#3b82f6',
                'outputs' => ['default'],
            ],
            'trigger_scan' => [
                'class' => ScanTrigger::class,
                'name' => 'Scan dossier',
                'description' => 'Déclenche quand un fichier est détecté dans le dossier consume',
                'icon' => 'folder-open',
                'color' => '#3b82f6',
                'outputs' => ['default'],
            ],
            'trigger_upload' => [
                'class' => UploadTrigger::class,
                'name' => 'Upload',
                'description' => 'Déclenche quand un fichier est uploadé via l\'interface',
                'icon' => 'upload',
                'color' => '#3b82f6',
                'outputs' => ['default'],
            ],
            'trigger_manual' => [
                'class' => ManualTrigger::class,
                'name' => 'Démarrage manuel',
                'description' => 'Workflow déclenché manuellement par un utilisateur',
                'icon' => 'play',
                'color' => '#3b82f6',
                'outputs' => ['default'],
            ],
            'trigger_validation_changed' => [
                'class' => ValidationStatusChangedTrigger::class,
                'name' => 'Validation changée',
                'description' => 'Déclenche quand le statut de validation d\'un document change',
                'icon' => 'check-circle',
                'color' => '#3b82f6',
                'outputs' => ['approved', 'rejected', 'default'],
            ],
        ],
        
        // === CONDITIONS ===
        'conditions' => [
            'condition_category' => [
                'class' => CategoryCondition::class,
                'name' => 'Type de document',
                'description' => 'Vérifie le type de document',
                'icon' => 'document-text',
                'color' => '#f59e0b',
                'outputs' => ['true', 'false'],
            ],
            'condition_amount' => [
                'class' => AmountCondition::class,
                'name' => 'Montant',
                'description' => 'Vérifie le montant du document (>, <, =, entre...)',
                'icon' => 'currency-dollar',
                'color' => '#f59e0b',
                'outputs' => ['true', 'false'],
            ],
            'condition_tag' => [
                'class' => TagCondition::class,
                'name' => 'Tag',
                'description' => 'Vérifie les tags du document',
                'icon' => 'tag',
                'color' => '#f59e0b',
                'outputs' => ['true', 'false'],
            ],
            'condition_field' => [
                'class' => FieldCondition::class,
                'name' => 'Champ personnalisé',
                'description' => 'Vérifie la valeur d\'un champ (standard, classification ou personnalisé)',
                'icon' => 'adjustments',
                'color' => '#f59e0b',
                'outputs' => ['true', 'false'],
            ],
            'condition_correspondent' => [
                'class' => CorrespondentCondition::class,
                'name' => 'Correspondant',
                'description' => 'Vérifie le correspondant du document',
                'icon' => 'user',
                'color' => '#f59e0b',
                'outputs' => ['true', 'false'],
            ],
        ],
        
        // === TRAITEMENT ===
        'processing' => [
            'process_ocr' => [
                'class' => OcrProcessor::class,
                'name' => 'OCR',
                'description' => 'Extraction du texte par OCR',
                'icon' => 'document-search',
                'color' => '#10b981',
                'outputs' => ['default'],
            ],
            'process_ai_extract' => [
                'class' => AiExtractProcessor::class,
                'name' => 'Extraction IA',
                'description' => 'Extraction intelligente des métadonnées via IA',
                'icon' => 'sparkles',
                'color' => '#10b981',
                'outputs' => ['default'],
            ],
            'process_classify' => [
                'class' => ClassifyProcessor::class,
                'name' => 'Classification',
                'description' => 'Classification automatique du document',
                'icon' => 'collection',
                'color' => '#10b981',
                'outputs' => ['default'],
            ],
        ],
        
        // === ACTIONS ===
        'actions' => [
            'action_create_approval' => [
                'class' => CreateApprovalAction::class,
                'name' => 'Créer approbation',
                'description' => 'Génère un token d\'approbation et expose {approval_link}, {reject_link} pour les nœuds suivants',
                'icon' => 'key',
                'color' => '#8b5cf6',
                'outputs' => ['default'],
                'output_variables' => ['approval_token', 'approval_link', 'reject_link', 'view_link', 'expires_at'],
            ],
            'action_request_approval' => [
                'class' => RequestApprovalAction::class,
                'name' => 'Demande d\'approbation (legacy)',
                'description' => 'Envoie une demande d\'approbation par email avec liens (mode monolithique)',
                'icon' => 'badge-check',
                'color' => '#8b5cf6',
                'outputs' => ['approved', 'rejected', 'timeout'],
            ],
            'action_assign_group' => [
                'class' => AssignToGroupAction::class,
                'name' => 'Assigner au groupe',
                'description' => 'Assigne le document à un groupe d\'utilisateurs',
                'icon' => 'user-group',
                'color' => '#8b5cf6',
                'outputs' => ['default'],
            ],
            'action_assign_user' => [
                'class' => AssignUserAction::class,
                'name' => 'Assigner utilisateur',
                'description' => 'Assigne le document à un utilisateur spécifique',
                'icon' => 'user',
                'color' => '#8b5cf6',
                'outputs' => ['default'],
            ],
            'action_add_tag' => [
                'class' => AddTagAction::class,
                'name' => 'Ajouter tag',
                'description' => 'Ajoute un ou plusieurs tags au document',
                'icon' => 'tag',
                'color' => '#8b5cf6',
                'outputs' => ['default'],
            ],
            'action_send_email' => [
                'class' => SendEmailAction::class,
                'name' => 'Envoyer email',
                'description' => 'Envoie un email avec support variables: {title}, {approval_link}, {nodeId.key}...',
                'icon' => 'mail',
                'color' => '#8b5cf6',
                'outputs' => ['default'],
                'input_variables' => ['approval_link', 'reject_link', 'view_link'],
            ],
            'action_webhook' => [
                'class' => WebhookAction::class,
                'name' => 'Webhook',
                'description' => 'Appelle une URL externe (API)',
                'icon' => 'link',
                'color' => '#8b5cf6',
                'outputs' => ['default'],
            ],
            'action_set_validation' => [
                'class' => SetValidationStatusAction::class,
                'name' => 'Marquer validé/rejeté',
                'description' => 'Définit le statut de validation du document (approuvé, rejeté, en attente)',
                'icon' => 'badge-check',
                'color' => '#10b981',
                'outputs' => ['approved', 'rejected', 'default'],
            ],
        ],
        
        // === ATTENTES ===
        'waits' => [
            'wait_approval' => [
                'class' => ApprovalWait::class,
                'name' => 'Attendre approbation',
                'description' => 'Attend une décision d\'approbation (utilise le token de CreateApproval ou mode standalone)',
                'icon' => 'clock',
                'color' => '#f97316',
                'outputs' => ['approved', 'rejected', 'timeout', 'cancelled'],
                'input_variables' => ['approval_token'],
            ],
        ],
        
        // === TEMPORISATEURS ===
        'timers' => [
            'timer_delay' => [
                'class' => DelayTimer::class,
                'name' => 'Délai',
                'description' => 'Attend un délai avant de continuer',
                'icon' => 'clock',
                'color' => '#06b6d4',
                'outputs' => ['default'],
            ],
        ],
    ];
    
    /**
     * Crée un executor pour un type de node donné
     */
    public static function create(string $nodeType): ?NodeExecutorInterface
    {
        // Rechercher dans le catalogue
        foreach (self::NODE_CATALOG as $category => $nodes) {
            if (isset($nodes[$nodeType])) {
                $className = $nodes[$nodeType]['class'];
                if (class_exists($className)) {
                    return new $className();
                }
            }
        }
        
        // Fallback pour compatibilité avec ancien format
        return match($nodeType) {
            // Triggers
            'trigger_scan' => new ScanTrigger(),
            'trigger_upload' => new UploadTrigger(),
            'trigger_manual' => new ManualTrigger(),
            'trigger_document_added' => new DocumentAddedTrigger(),
            'trigger_tag_added' => new TagAddedTrigger(),
            'trigger_validation_changed' => new ValidationStatusChangedTrigger(),
            
            // Processing
            'process_ocr' => new OcrProcessor(),
            'process_ai_extract' => new AiExtractProcessor(),
            'process_classify' => new ClassifyProcessor(),
            
            // Conditions
            'condition_category' => new CategoryCondition(),
            'condition_amount' => new AmountCondition(),
            'condition_tag' => new TagCondition(),
            'condition_field' => new FieldCondition(),
            'condition_correspondent' => new CorrespondentCondition(),
            
            // Actions
            'action_assign_user' => new AssignUserAction(),
            'action_add_tag' => new AddTagAction(),
            'action_send_email' => new SendEmailAction(),
            'action_webhook' => new WebhookAction(),
            'action_create_approval' => new CreateApprovalAction(),
            'action_request_approval' => new RequestApprovalAction(),
            'action_assign_group' => new AssignToGroupAction(),
            'action_set_validation' => new SetValidationStatusAction(),
            
            // Waits
            'wait_approval' => new ApprovalWait(),
            
            // Timers
            'timer_delay' => new DelayTimer(),
            
            default => null,
        };
    }
    
    /**
     * Vérifie si un type de node est supporté
     */
    public static function isSupported(string $nodeType): bool
    {
        return self::create($nodeType) !== null;
    }
    
    /**
     * Retourne le catalogue complet des nodes disponibles
     * Utilisé par le designer frontend
     */
    public static function getCatalog(): array
    {
        return self::NODE_CATALOG;
    }
    
    /**
     * Retourne les infos d'un type de node
     */
    public static function getNodeInfo(string $nodeType): ?array
    {
        foreach (self::NODE_CATALOG as $category => $nodes) {
            if (isset($nodes[$nodeType])) {
                $info = $nodes[$nodeType];
                $info['type'] = $nodeType;
                $info['category'] = $category;

                // Récupérer le schema de config et output
                $executor = self::create($nodeType);
                if ($executor) {
                    $info['config_schema'] = $executor->getConfigSchema();

                    // Récupérer le schema d'output si disponible
                    if (method_exists($executor, 'getOutputSchema')) {
                        $info['output_schema'] = $executor->getOutputSchema();
                    }
                }

                return $info;
            }
        }
        return null;
    }

    /**
     * Retourne toutes les variables disponibles produites par les nœuds
     * Utile pour l'autocomplétion dans l'UI
     */
    public static function getAllOutputVariables(): array
    {
        $variables = [];

        foreach (self::NODE_CATALOG as $category => $nodes) {
            foreach ($nodes as $type => $info) {
                if (!empty($info['output_variables'])) {
                    $variables[$type] = [
                        'name' => $info['name'],
                        'variables' => $info['output_variables'],
                    ];
                }
            }
        }

        return $variables;
    }
    
    /**
     * Retourne tous les types de nodes par catégorie (format simplifié pour frontend)
     */
    public static function getNodeTypes(): array
    {
        $result = [];
        foreach (self::NODE_CATALOG as $category => $nodes) {
            $result[$category] = [];
            foreach ($nodes as $type => $info) {
                $result[$category][] = [
                    'type' => $type,
                    'name' => $info['name'],
                    'description' => $info['description'],
                    'icon' => $info['icon'],
                    'color' => $info['color'],
                    'outputs' => $info['outputs'],
                ];
            }
        }
        return $result;
    }
}
