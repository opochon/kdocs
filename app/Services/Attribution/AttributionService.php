<?php
/**
 * K-Docs - Attribution Service
 * Orchestrateur principal pour le système d'attribution
 */

namespace KDocs\Services\Attribution;

use KDocs\Core\Database;
use KDocs\Models\AttributionRule;
use KDocs\Models\ClassificationAuditLog;
use KDocs\Models\Tag;

class AttributionService
{
    private AttributionRuleEngine $ruleEngine;

    public function __construct()
    {
        $this->ruleEngine = new AttributionRuleEngine();
    }

    /**
     * Traite un document avec les règles d'attribution
     *
     * @param int $documentId ID du document
     * @param bool $apply Appliquer les actions (false = simulation)
     * @param int|null $userId ID de l'utilisateur (pour l'audit)
     * @return array Résultat du traitement
     */
    public function process(int $documentId, bool $apply = true, ?int $userId = null): array
    {
        // Évaluer les règles
        $evaluation = $this->ruleEngine->evaluate($documentId);

        if (!$evaluation['success']) {
            return $evaluation;
        }

        // Si pas d'actions, retourner le résultat tel quel
        if (empty($evaluation['actions'])) {
            return [
                'success' => true,
                'document_id' => $documentId,
                'rules_evaluated' => $evaluation['rules_evaluated'],
                'rules_matched' => $evaluation['rules_matched'],
                'actions_planned' => 0,
                'actions_applied' => 0,
                'changes' => [],
                'logs' => $evaluation['logs']
            ];
        }

        $result = [
            'success' => true,
            'document_id' => $documentId,
            'rules_evaluated' => $evaluation['rules_evaluated'],
            'rules_matched' => $evaluation['rules_matched'],
            'actions_planned' => count($evaluation['actions']),
            'actions_applied' => 0,
            'changes' => [],
            'logs' => $evaluation['logs']
        ];

        // Appliquer les actions si demandé
        if ($apply) {
            foreach ($evaluation['actions'] as $action) {
                $changeResult = $this->applyAction($documentId, $action, $userId);

                if ($changeResult['applied']) {
                    $result['actions_applied']++;
                    $result['changes'][] = $changeResult;
                }
            }

            // Mettre à jour le document
            $this->updateDocumentClassificationStatus($documentId, 'rules');
        } else {
            // Mode simulation - juste lister les changements prévus
            foreach ($evaluation['actions'] as $action) {
                $result['changes'][] = [
                    'action_type' => $action['action_type'],
                    'field_name' => $action['field_name'],
                    'new_value' => $action['value'],
                    'applied' => false,
                    'simulation' => true
                ];
            }
        }

        return $result;
    }

    /**
     * Applique une action sur un document
     */
    private function applyAction(int $documentId, array $action, ?int $userId): array
    {
        $db = Database::getInstance();

        $result = [
            'action_type' => $action['action_type'],
            'field_name' => $action['field_name'] ?? null,
            'new_value' => $action['value'],
            'old_value' => null,
            'applied' => false,
            'rule_id' => $action['rule_id'] ?? null
        ];

        try {
            switch ($action['action_type']) {
                case 'set_field':
                    $result = $this->applySetField($documentId, $action, $userId);
                    break;

                case 'add_tag':
                    $result = $this->applyAddTag($documentId, $action, $userId);
                    break;

                case 'remove_tag':
                    $result = $this->applyRemoveTag($documentId, $action, $userId);
                    break;

                case 'move_to_folder':
                    $result = $this->applyMoveToFolder($documentId, $action, $userId);
                    break;

                case 'set_correspondent':
                    $result = $this->applySetCorrespondent($documentId, $action, $userId);
                    break;

                case 'set_document_type':
                    $result = $this->applySetDocumentType($documentId, $action, $userId);
                    break;
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Applique l'action set_field
     */
    private function applySetField(int $documentId, array $action, ?int $userId): array
    {
        $db = Database::getInstance();
        $fieldName = $action['field_name'];
        $newValue = $action['value'];

        // Vérifier que le champ est autorisé
        $allowedFields = ['compte_comptable', 'centre_cout', 'projet'];
        if (!in_array($fieldName, $allowedFields)) {
            return [
                'action_type' => 'set_field',
                'field_name' => $fieldName,
                'applied' => false,
                'error' => "Field not allowed: $fieldName"
            ];
        }

        // Récupérer l'ancienne valeur
        $stmt = $db->prepare("SELECT $fieldName FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $oldValue = $stmt->fetchColumn();

        // Ne pas modifier si la valeur est identique
        if ($oldValue === $newValue) {
            return [
                'action_type' => 'set_field',
                'field_name' => $fieldName,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'applied' => false,
                'reason' => 'Value unchanged'
            ];
        }

        // Appliquer le changement
        $stmt = $db->prepare("UPDATE documents SET $fieldName = ? WHERE id = ?");
        $stmt->execute([$newValue, $documentId]);

        // Logger l'audit
        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_source' => 'rules',
            'rule_id' => $action['rule_id'] ?? null,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'action_type' => 'set_field',
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'applied' => true,
            'rule_id' => $action['rule_id'] ?? null
        ];
    }

    /**
     * Applique l'action add_tag
     */
    private function applyAddTag(int $documentId, array $action, ?int $userId): array
    {
        $tagId = is_array($action['value']) ? ($action['value']['id'] ?? $action['value'][0]) : $action['value'];

        Tag::addToDocument($documentId, (int)$tagId);

        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => 'tag',
            'old_value' => null,
            'new_value' => $tagId,
            'change_source' => 'rules',
            'change_reason' => 'Tag added by rule',
            'rule_id' => $action['rule_id'] ?? null,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'action_type' => 'add_tag',
            'new_value' => $tagId,
            'applied' => true,
            'rule_id' => $action['rule_id'] ?? null
        ];
    }

    /**
     * Applique l'action remove_tag
     */
    private function applyRemoveTag(int $documentId, array $action, ?int $userId): array
    {
        $tagId = is_array($action['value']) ? ($action['value']['id'] ?? $action['value'][0]) : $action['value'];

        Tag::removeFromDocument($documentId, (int)$tagId);

        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => 'tag',
            'old_value' => $tagId,
            'new_value' => null,
            'change_source' => 'rules',
            'change_reason' => 'Tag removed by rule',
            'rule_id' => $action['rule_id'] ?? null,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'action_type' => 'remove_tag',
            'old_value' => $tagId,
            'applied' => true,
            'rule_id' => $action['rule_id'] ?? null
        ];
    }

    /**
     * Applique l'action move_to_folder
     */
    private function applyMoveToFolder(int $documentId, array $action, ?int $userId): array
    {
        $db = Database::getInstance();
        $folderId = is_array($action['value']) ? ($action['value']['id'] ?? $action['value'][0]) : $action['value'];

        // Récupérer l'ancien dossier
        $stmt = $db->prepare("SELECT logical_folder_id FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $oldFolderId = $stmt->fetchColumn();

        if ($oldFolderId == $folderId) {
            return [
                'action_type' => 'move_to_folder',
                'applied' => false,
                'reason' => 'Already in folder'
            ];
        }

        // Déplacer
        $stmt = $db->prepare("UPDATE documents SET logical_folder_id = ? WHERE id = ?");
        $stmt->execute([$folderId, $documentId]);

        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => 'logical_folder_id',
            'old_value' => $oldFolderId,
            'new_value' => $folderId,
            'change_source' => 'rules',
            'rule_id' => $action['rule_id'] ?? null,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'action_type' => 'move_to_folder',
            'old_value' => $oldFolderId,
            'new_value' => $folderId,
            'applied' => true,
            'rule_id' => $action['rule_id'] ?? null
        ];
    }

    /**
     * Applique l'action set_correspondent
     */
    private function applySetCorrespondent(int $documentId, array $action, ?int $userId): array
    {
        $db = Database::getInstance();
        $correspondentId = is_array($action['value']) ? ($action['value']['id'] ?? $action['value'][0]) : $action['value'];

        $stmt = $db->prepare("SELECT correspondent_id FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $oldValue = $stmt->fetchColumn();

        if ($oldValue == $correspondentId) {
            return [
                'action_type' => 'set_correspondent',
                'applied' => false,
                'reason' => 'Value unchanged'
            ];
        }

        $stmt = $db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?");
        $stmt->execute([$correspondentId, $documentId]);

        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => 'correspondent_id',
            'old_value' => $oldValue,
            'new_value' => $correspondentId,
            'change_source' => 'rules',
            'rule_id' => $action['rule_id'] ?? null,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'action_type' => 'set_correspondent',
            'old_value' => $oldValue,
            'new_value' => $correspondentId,
            'applied' => true,
            'rule_id' => $action['rule_id'] ?? null
        ];
    }

    /**
     * Applique l'action set_document_type
     */
    private function applySetDocumentType(int $documentId, array $action, ?int $userId): array
    {
        $db = Database::getInstance();
        $documentTypeId = is_array($action['value']) ? ($action['value']['id'] ?? $action['value'][0]) : $action['value'];

        $stmt = $db->prepare("SELECT document_type_id FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $oldValue = $stmt->fetchColumn();

        if ($oldValue == $documentTypeId) {
            return [
                'action_type' => 'set_document_type',
                'applied' => false,
                'reason' => 'Value unchanged'
            ];
        }

        $stmt = $db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?");
        $stmt->execute([$documentTypeId, $documentId]);

        ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => 'document_type_id',
            'old_value' => $oldValue,
            'new_value' => $documentTypeId,
            'change_source' => 'rules',
            'rule_id' => $action['rule_id'] ?? null,
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'action_type' => 'set_document_type',
            'old_value' => $oldValue,
            'new_value' => $documentTypeId,
            'applied' => true,
            'rule_id' => $action['rule_id'] ?? null
        ];
    }

    /**
     * Met à jour le statut de classification du document
     */
    private function updateDocumentClassificationStatus(int $documentId, string $source): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE documents
            SET last_classified_at = NOW(), last_classified_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$source, $documentId]);
    }

    /**
     * Traite plusieurs documents en batch
     */
    public function processBatch(array $documentIds, bool $apply = true, ?int $userId = null): array
    {
        $results = [];

        foreach ($documentIds as $documentId) {
            $results[$documentId] = $this->process($documentId, $apply, $userId);
        }

        return [
            'total' => count($documentIds),
            'processed' => count($results),
            'with_matches' => count(array_filter($results, fn($r) => ($r['rules_matched'] ?? 0) > 0)),
            'with_changes' => count(array_filter($results, fn($r) => ($r['actions_applied'] ?? 0) > 0)),
            'results' => $results
        ];
    }

    /**
     * Obtient le moteur de règles (pour les tests)
     */
    public function getRuleEngine(): AttributionRuleEngine
    {
        return $this->ruleEngine;
    }
}
