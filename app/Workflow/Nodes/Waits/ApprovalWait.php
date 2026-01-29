<?php
/**
 * K-Docs - ApprovalWait
 * Attend une approbation avant de continuer
 *
 * Mode modulaire (recommandé):
 *   - Utilise le token créé par CreateApprovalAction
 *   - Récupère {approval_token} ou {nodeId.approval_token} du contexte
 *
 * Mode standalone (legacy):
 *   - Crée directement une tâche d'approbation si aucun token n'est fourni
 */

namespace KDocs\Workflow\Nodes\Waits;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class ApprovalWait extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }

        $nodeId = $config['node_id'] ?? null;
        $db = Database::getInstance();

        // =====================================================================
        // MODE 1: Token fourni par CreateApprovalAction (modulaire)
        // =====================================================================

        // Chercher le token dans le contexte
        $approvalToken = $this->findApprovalToken($context, $config);

        if ($approvalToken) {
            // Vérifier que le token existe et est valide
            $stmt = $db->prepare("
                SELECT id, expires_at, response_action
                FROM workflow_approval_tokens
                WHERE token = ? AND execution_id = ?
            ");
            $stmt->execute([$approvalToken, $context->executionId]);
            $tokenData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$tokenData) {
                return ExecutionResult::failed('Token d\'approbation invalide ou non trouvé');
            }

            // Token déjà traité?
            if ($tokenData['response_action']) {
                // L'approbation a déjà été donnée, continuer avec le résultat
                $decision = $tokenData['response_action'];
                return ExecutionResult::success(['decision' => $decision], $decision);
            }

            // Token expiré?
            if ($tokenData['expires_at'] && strtotime($tokenData['expires_at']) < time()) {
                return ExecutionResult::success(['decision' => 'timeout'], 'timeout');
            }

            // Mettre à jour le node_id dans la tâche d'approbation (pour le lier à ce nœud wait)
            $stmt = $db->prepare("
                UPDATE workflow_approval_tasks
                SET node_id = ?
                WHERE execution_id = ? AND status = 'pending'
            ");
            $stmt->execute([$nodeId, $context->executionId]);

            // Mettre l'exécution en attente
            return ExecutionResult::waiting('approval', null, [
                'token' => $approvalToken,
                'token_id' => $tokenData['id'],
                'expires_at' => $tokenData['expires_at'],
            ]);
        }

        // =====================================================================
        // MODE 2: Standalone (legacy) - Créer une tâche d'approbation directement
        // =====================================================================

        $userId = $config['assign_to_user_id'] ?? null;
        $groupId = $config['assign_to_group_id'] ?? null;

        if (!$userId && !$groupId) {
            return ExecutionResult::failed('Aucun token d\'approbation trouvé et aucun utilisateur/groupe assigné. Utilisez CreateApprovalAction avant ce nœud, ou configurez assign_to_user_id/assign_to_group_id.');
        }

        try {
            // Calculer l'expiration
            $expiresAt = null;
            if (isset($config['timeout_hours'])) {
                $expiresAt = date('Y-m-d H:i:s', time() + ($config['timeout_hours'] * 3600));
            }

            // Créer une tâche d'approbation
            $stmt = $db->prepare("
                INSERT INTO workflow_approval_tasks
                (execution_id, node_id, document_id, assigned_user_id, assigned_group_id,
                 expires_at, escalate_to_user_id, escalate_after_hours, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");

            $stmt->execute([
                $context->executionId,
                $nodeId,
                $context->documentId,
                $userId,
                $groupId,
                $expiresAt,
                $config['escalate_to_user_id'] ?? null,
                $config['escalate_after_hours'] ?? null,
            ]);

            $taskId = $db->lastInsertId();

            // Mettre l'exécution en attente
            return ExecutionResult::waiting('approval', null, [
                'task_id' => $taskId,
                'expires_at' => $expiresAt,
                'mode' => 'standalone'
            ]);
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur création approbation: ' . $e->getMessage());
        }
    }

    /**
     * Cherche le token d'approbation dans le contexte
     * Ordre de priorité:
     *   1. Config explicite (token_source_node_id)
     *   2. Variable globale {approval_token}
     *   3. Dernier nœud CreateApproval exécuté
     */
    private function findApprovalToken(ContextBag $context, array $config): ?string
    {
        // 1. Source explicite configurée
        if (!empty($config['token_source_node_id'])) {
            $sourceNodeId = (int)$config['token_source_node_id'];
            $token = $context->getNodeOutput($sourceNodeId, 'approval_token');
            if ($token) {
                return $token;
            }
        }

        // 2. Variable globale (set par CreateApprovalAction)
        $globalToken = $context->get('approval_token');
        if ($globalToken) {
            return $globalToken;
        }

        // 3. Chercher dans la base le dernier token créé pour cette exécution
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT token
                FROM workflow_approval_tokens
                WHERE execution_id = ? AND response_action IS NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$context->executionId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return $row['token'];
            }
        } catch (\Exception $e) {
            error_log("ApprovalWait::findApprovalToken error: " . $e->getMessage());
        }

        return null;
    }

    public function getOutputs(): array
    {
        return ['approved', 'rejected', 'timeout', 'cancelled'];
    }

    /**
     * Schéma des outputs produits
     */
    public function getOutputSchema(): array
    {
        return [
            'decision' => [
                'type' => 'string',
                'description' => 'Décision: approved, rejected, timeout, cancelled',
            ],
            'decided_by' => [
                'type' => 'integer',
                'description' => 'ID de l\'utilisateur ayant décidé',
            ],
            'decided_at' => [
                'type' => 'string',
                'description' => 'Date de la décision',
            ],
            'comment' => [
                'type' => 'string',
                'description' => 'Commentaire de l\'approbateur',
            ],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'token_source_node_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID du nœud CreateApproval source (auto-détecté si non spécifié)',
            ],
            'assign_to_user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID utilisateur (mode standalone uniquement)',
            ],
            'assign_to_group_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID groupe (mode standalone uniquement)',
            ],
            'timeout_hours' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Timeout en heures (mode standalone)',
            ],
            'escalate_to_user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID utilisateur pour escalade',
            ],
            'escalate_after_hours' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Heures avant escalade',
            ],
        ];
    }
}
