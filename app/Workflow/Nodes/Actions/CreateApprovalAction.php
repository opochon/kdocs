<?php
/**
 * K-Docs - CreateApprovalAction
 * Crée un token d'approbation et expose les variables pour les nœuds suivants
 *
 * Ce nœud génère:
 *   - {approval_token} : Token unique
 *   - {approval_link} : Lien pour approuver
 *   - {reject_link} : Lien pour refuser
 *   - {view_link} : Lien pour voir le document
 *   - {expires_at} : Date d'expiration
 *
 * Les nœuds suivants (SendEmail, Notification, etc.) peuvent utiliser ces variables.
 * Le workflow doit ensuite passer par un nœud WaitApproval pour attendre la réponse.
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;
use KDocs\Core\Config;

class CreateApprovalAction extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }

        $db = Database::getInstance();
        $appConfig = Config::load();
        $baseUrl = rtrim($appConfig['app']['url'] ?? 'http://localhost/kdocs', '/');

        // Récupérer l'ID du nœud courant
        $nodeId = $config['node_id'] ?? null;
        if (!$nodeId) {
            return ExecutionResult::failed('ID de nœud non fourni');
        }

        // Déterminer le destinataire (optionnel à ce stade, peut être configuré plus tard)
        $assignToUserId = $config['assign_to_user_id'] ?? null;
        $assignToGroupId = $config['assign_to_group_id'] ?? null;
        $assignToGroupCode = $config['assign_to_group_code'] ?? null;

        // Si un code de groupe est fourni, récupérer l'ID
        if ($assignToGroupCode && !$assignToGroupId) {
            $stmt = $db->prepare("SELECT id FROM groups WHERE code = ?");
            $stmt->execute([$assignToGroupCode]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($group) {
                $assignToGroupId = $group['id'];
            }
        }

        // Générer un token unique pour cette approbation
        $token = bin2hex(random_bytes(32));

        // Calculer la date d'expiration
        $expiresHours = $config['expires_hours'] ?? 72; // 3 jours par défaut
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresHours * 3600));

        // Construire les URLs
        $approveUrl = "{$baseUrl}/workflow/approve/{$token}?action=approve";
        $rejectUrl = "{$baseUrl}/workflow/approve/{$token}?action=reject";
        $viewUrl = "{$baseUrl}/documents/{$context->documentId}";

        // Préparer les métadonnées
        $actionRequired = $config['action_required'] ?? 'approve';
        $customMessage = $config['message'] ?? '';
        $priority = $config['priority'] ?? 'normal';

        // Créer l'enregistrement du token d'approbation
        $stmt = $db->prepare("
            INSERT INTO workflow_approval_tokens
            (token, execution_id, document_id, node_id, source_node_id, assigned_user_id, assigned_group_id,
             action_required, message, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $token,
            $context->executionId,
            $context->documentId,
            $nodeId,
            $nodeId, // source_node_id = ce nœud
            $assignToUserId,
            $assignToGroupId,
            $actionRequired,
            $customMessage,
            $expiresAt
        ]);

        $tokenId = $db->lastInsertId();

        // Créer la tâche d'approbation (en status pending, sera activée par WaitApproval)
        $stmt = $db->prepare("
            INSERT INTO workflow_approval_tasks
            (execution_id, node_id, document_id, assigned_user_id, assigned_group_id,
             priority, expires_at, escalate_to_user_id, escalate_after_hours, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        $escalateToUserId = $config['escalate_to_user_id'] ?? null;
        $escalateAfterHours = $config['escalate_after_hours'] ?? null;

        $stmt->execute([
            $context->executionId,
            $nodeId,
            $context->documentId,
            $assignToUserId,
            $assignToGroupId,
            $priority,
            $expiresAt,
            $escalateToUserId,
            $escalateAfterHours
        ]);

        // =====================================================================
        // EXPOSER LES VARIABLES POUR LES NŒUDS SUIVANTS
        // =====================================================================

        // Enregistrer le nom du nœud pour permettre l'interpolation par nom
        $nodeName = $config['node_name'] ?? "approval_$nodeId";
        $context->registerNodeName($nodeId, $nodeName);

        // Définir les outputs du nœud (seront disponibles via {nodeId.key} ou {nodeName.key})
        $context->setNodeOutput($nodeId, 'approval_token', $token, 'string');
        $context->setNodeOutput($nodeId, 'approval_link', $approveUrl, 'url');
        $context->setNodeOutput($nodeId, 'reject_link', $rejectUrl, 'url');
        $context->setNodeOutput($nodeId, 'view_link', $viewUrl, 'url');
        $context->setNodeOutput($nodeId, 'expires_at', $expiresAt, 'string');
        $context->setNodeOutput($nodeId, 'token_id', $tokenId, 'integer');
        $context->setNodeOutput($nodeId, 'action_required', $actionRequired, 'string');
        $context->setNodeOutput($nodeId, 'priority', $priority, 'string');

        // Stocker aussi dans le contexte global pour compatibilité
        $context->set('approval_token', $token);
        $context->set('approval_link', $approveUrl);
        $context->set('reject_link', $rejectUrl);
        $context->set('view_link', $viewUrl);
        $context->set('approval_expires_at', $expiresAt);
        $context->set('approval_token_id', $tokenId);

        // Le workflow continue immédiatement vers le nœud suivant (ex: SendEmail)
        return ExecutionResult::success([
            'token' => $token,
            'token_id' => $tokenId,
            'approval_link' => $approveUrl,
            'reject_link' => $rejectUrl,
            'view_link' => $viewUrl,
            'expires_at' => $expiresAt,
            'assigned_user_id' => $assignToUserId,
            'assigned_group_id' => $assignToGroupId,
        ]);
    }

    /**
     * Outputs déclarés par ce nœud
     */
    public function getOutputs(): array
    {
        return ['default'];
    }

    /**
     * Schéma des outputs produits (pour l'UI)
     */
    public function getOutputSchema(): array
    {
        return [
            'approval_token' => [
                'type' => 'string',
                'description' => 'Token unique d\'approbation (64 caractères)',
            ],
            'approval_link' => [
                'type' => 'url',
                'description' => 'Lien complet pour approuver',
            ],
            'reject_link' => [
                'type' => 'url',
                'description' => 'Lien complet pour refuser',
            ],
            'view_link' => [
                'type' => 'url',
                'description' => 'Lien pour voir le document',
            ],
            'expires_at' => [
                'type' => 'string',
                'description' => 'Date d\'expiration (ISO)',
            ],
            'token_id' => [
                'type' => 'integer',
                'description' => 'ID du token en base',
            ],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'assign_to_user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID de l\'utilisateur approbateur (optionnel)',
            ],
            'assign_to_group_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID du groupe approbateur (optionnel)',
            ],
            'assign_to_group_code' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Code du groupe approbateur (ex: SUPERVISORS)',
            ],
            'action_required' => [
                'type' => 'string',
                'required' => false,
                'default' => 'approve',
                'description' => 'Type d\'action requise',
                'enum' => ['approve', 'reject', 'review', 'sign']
            ],
            'message' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Message personnalisé (stocké avec le token)',
            ],
            'expires_hours' => [
                'type' => 'integer',
                'required' => false,
                'default' => 72,
                'description' => 'Délai d\'expiration en heures (défaut: 72h)',
            ],
            'priority' => [
                'type' => 'string',
                'required' => false,
                'default' => 'normal',
                'enum' => ['low', 'normal', 'high', 'urgent']
            ],
            'escalate_to_user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID utilisateur pour escalade automatique',
            ],
            'escalate_after_hours' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Heures avant escalade automatique',
            ],
        ];
    }
}
