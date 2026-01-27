<?php
/**
 * K-Docs - SetValidationStatusAction
 * Marque un document comme validé, rejeté ou en attente
 * Persiste le statut sur le document pour exploitation ultérieure
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class SetValidationStatusAction extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }

        $db = Database::getInstance();

        // Récupérer le statut à appliquer
        $status = $config['status'] ?? null;
        if (!$status || !in_array($status, ['pending', 'approved', 'rejected', 'na'])) {
            return ExecutionResult::failed('Statut de validation invalide: ' . ($status ?? 'null'));
        }

        // Récupérer l'utilisateur validateur (depuis config ou contexte)
        $validatedBy = $config['validated_by_user_id'] ?? null;

        // Si pas d'utilisateur spécifié, essayer de récupérer depuis le contexte workflow
        if (!$validatedBy && isset($context->data['approver_user_id'])) {
            $validatedBy = $context->data['approver_user_id'];
        }

        // Commentaire optionnel
        $comment = $config['comment'] ?? $context->data['approval_comment'] ?? null;

        // Niveau de validation (pour workflows multi-niveaux)
        $validationLevel = $config['validation_level'] ?? 1;

        // Récupérer le statut actuel pour l'historique
        $stmt = $db->prepare("SELECT validation_status FROM documents WHERE id = ?");
        $stmt->execute([$context->documentId]);
        $currentDoc = $stmt->fetch(\PDO::FETCH_ASSOC);
        $previousStatus = $currentDoc['validation_status'] ?? null;

        try {
            $db->beginTransaction();

            // Mettre à jour le document
            $stmt = $db->prepare("
                UPDATE documents
                SET validation_status = ?,
                    validated_by = ?,
                    validated_at = NOW(),
                    validation_comment = ?,
                    validation_level = ?,
                    requires_approval = FALSE
                WHERE id = ?
            ");

            $stmt->execute([
                $status,
                $validatedBy,
                $comment,
                $validationLevel,
                $context->documentId
            ]);

            // Enregistrer dans l'historique
            $action = match($status) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                'pending' => 'returned',
                'na' => 'set_na',
                default => 'commented'
            };

            $stmt = $db->prepare("
                INSERT INTO document_validation_history
                (document_id, action, from_status, to_status, performed_by, role_code, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            // Récupérer le rôle de l'utilisateur si disponible
            $roleCode = null;
            if ($validatedBy) {
                $stmtRole = $db->prepare("
                    SELECT rt.code
                    FROM user_roles ur
                    JOIN role_types rt ON ur.role_type_id = rt.id
                    WHERE ur.user_id = ?
                    ORDER BY rt.level DESC
                    LIMIT 1
                ");
                $stmtRole->execute([$validatedBy]);
                $roleResult = $stmtRole->fetch(\PDO::FETCH_ASSOC);
                $roleCode = $roleResult['code'] ?? null;
            }

            $stmt->execute([
                $context->documentId,
                $action,
                $previousStatus,
                $status,
                $validatedBy,
                $roleCode,
                $comment
            ]);

            $db->commit();

            // Déterminer la sortie en fonction du statut
            $output = match($status) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                'na' => 'na',
                default => 'default'
            };

            return ExecutionResult::success([
                'status' => $status,
                'previous_status' => $previousStatus,
                'validated_by' => $validatedBy,
                'validation_level' => $validationLevel,
                'comment' => $comment
            ], $output);

        } catch (\Exception $e) {
            $db->rollBack();
            return ExecutionResult::failed('Erreur lors de la mise à jour: ' . $e->getMessage());
        }
    }

    public function getOutputs(): array
    {
        return ['approved', 'rejected', 'na', 'default'];
    }

    public function getConfigSchema(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Statut de validation à appliquer (Validé, Non validé, N/A, En attente)',
                'enum' => ['approved', 'rejected', 'na', 'pending'],
                'enumLabels' => ['Validé', 'Non validé', 'N/A', 'En attente'],
            ],
            'validated_by_user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID de l\'utilisateur validateur (optionnel, pris du contexte si absent)',
            ],
            'comment' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Commentaire de validation',
            ],
            'validation_level' => [
                'type' => 'integer',
                'required' => false,
                'default' => 1,
                'description' => 'Niveau de validation (1, 2, 3... pour multi-niveaux)',
            ],
        ];
    }
}
