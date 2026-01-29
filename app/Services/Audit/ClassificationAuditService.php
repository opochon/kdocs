<?php
/**
 * K-Docs - Classification Audit Service
 * Service de gestion de l'audit des classifications
 */

namespace KDocs\Services\Audit;

use KDocs\Core\Database;
use KDocs\Models\ClassificationAuditLog;

class ClassificationAuditService
{
    /**
     * Enregistre une modification de classification
     *
     * @param int $documentId ID du document
     * @param string $fieldCode Code du champ modifié
     * @param mixed $oldValue Ancienne valeur
     * @param mixed $newValue Nouvelle valeur
     * @param string $source Source de la modification (manual, rules, ml, ai)
     * @param array $options Options supplémentaires
     * @return int ID du log créé
     */
    public function log(
        int $documentId,
        string $fieldCode,
        $oldValue,
        $newValue,
        string $source = 'manual',
        array $options = []
    ): int {
        return ClassificationAuditLog::log([
            'document_id' => $documentId,
            'field_code' => $fieldCode,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_source' => $source,
            'change_reason' => $options['reason'] ?? null,
            'rule_id' => $options['rule_id'] ?? null,
            'suggestion_id' => $options['suggestion_id'] ?? null,
            'user_id' => $options['user_id'] ?? null,
            'ip_address' => $options['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null)
        ]);
    }

    /**
     * Enregistre plusieurs modifications à la fois
     */
    public function logBatch(int $documentId, array $changes, string $source, array $options = []): array
    {
        $ids = [];

        foreach ($changes as $change) {
            $ids[] = $this->log(
                $documentId,
                $change['field_code'],
                $change['old_value'] ?? null,
                $change['new_value'] ?? null,
                $source,
                array_merge($options, $change['options'] ?? [])
            );
        }

        return $ids;
    }

    /**
     * Récupère l'historique d'un document
     */
    public function getDocumentHistory(int $documentId, int $limit = 100): array
    {
        $logs = ClassificationAuditLog::getForDocument($documentId, $limit);

        // Enrichir avec les labels des champs
        $fieldLabels = $this->getFieldLabels();

        foreach ($logs as &$log) {
            $log['field_label'] = $fieldLabels[$log['field_code']] ?? $log['field_code'];
            $log['source_label'] = $this->getSourceLabel($log['change_source']);
        }

        return $logs;
    }

    /**
     * Récupère l'historique global avec filtres
     */
    public function getGlobalHistory(int $page = 1, int $perPage = 50, array $filters = []): array
    {
        $logs = ClassificationAuditLog::getAll($page, $perPage, $filters);
        $total = ClassificationAuditLog::count($filters);

        $fieldLabels = $this->getFieldLabels();

        foreach ($logs as &$log) {
            $log['field_label'] = $fieldLabels[$log['field_code']] ?? $log['field_code'];
            $log['source_label'] = $this->getSourceLabel($log['change_source']);
        }

        return [
            'data' => $logs,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage)
            ]
        ];
    }

    /**
     * Récupère les statistiques d'audit
     */
    public function getStats(int $days = 30): array
    {
        $stats = ClassificationAuditLog::getStats($days);

        // Ajouter les labels
        $fieldLabels = $this->getFieldLabels();
        $sourceLabels = [
            'manual' => 'Manuel',
            'rules' => 'Règles',
            'ml' => 'Machine Learning',
            'ai' => 'Intelligence Artificielle',
            'import' => 'Import',
            'api' => 'API'
        ];

        $stats['by_field_labeled'] = [];
        foreach ($stats['by_field'] as $field => $count) {
            $stats['by_field_labeled'][] = [
                'field' => $field,
                'label' => $fieldLabels[$field] ?? $field,
                'count' => $count
            ];
        }

        $stats['by_source_labeled'] = [];
        foreach ($stats['by_source'] as $source => $count) {
            $stats['by_source_labeled'][] = [
                'source' => $source,
                'label' => $sourceLabels[$source] ?? $source,
                'count' => $count
            ];
        }

        return $stats;
    }

    /**
     * Exporte l'historique au format CSV
     */
    public function exportCsv(array $filters = []): string
    {
        return ClassificationAuditLog::exportCsv($filters);
    }

    /**
     * Compare deux versions d'un document
     */
    public function compareVersions(int $documentId, string $fromDate, string $toDate): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT field_code, old_value, new_value, change_source, created_at
            FROM classification_audit_log
            WHERE document_id = ?
              AND created_at BETWEEN ? AND ?
            ORDER BY created_at
        ");
        $stmt->execute([$documentId, $fromDate, $toDate]);
        $changes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Reconstruire l'état initial et final
        $initial = [];
        $final = [];

        foreach ($changes as $change) {
            $field = $change['field_code'];

            if (!isset($initial[$field])) {
                $initial[$field] = $change['old_value'];
            }
            $final[$field] = $change['new_value'];
        }

        return [
            'document_id' => $documentId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'changes_count' => count($changes),
            'initial_state' => $initial,
            'final_state' => $final,
            'changes' => $changes
        ];
    }

    /**
     * Annule une modification (restaure l'ancienne valeur)
     */
    public function revert(int $logId, int $userId): array
    {
        $log = ClassificationAuditLog::find($logId);

        if (!$log) {
            return ['error' => 'Log entry not found'];
        }

        $db = Database::getInstance();
        $documentId = $log['document_id'];
        $fieldCode = $log['field_code'];
        $oldValue = $log['old_value'];
        $currentValue = $log['new_value'];

        // Vérifier que le champ peut être modifié directement
        $directFields = ['compte_comptable', 'centre_cout', 'projet', 'correspondent_id', 'document_type_id', 'logical_folder_id'];

        if (!in_array($fieldCode, $directFields)) {
            return ['error' => 'Field cannot be reverted directly'];
        }

        // Récupérer la valeur actuelle
        $stmt = $db->prepare("SELECT $fieldCode FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $actualCurrentValue = $stmt->fetchColumn();

        // Vérifier que la valeur n'a pas changé depuis
        if ($actualCurrentValue != $currentValue) {
            return [
                'error' => 'Value has changed since this modification',
                'expected' => $currentValue,
                'actual' => $actualCurrentValue
            ];
        }

        // Restaurer l'ancienne valeur
        $stmt = $db->prepare("UPDATE documents SET $fieldCode = ? WHERE id = ?");
        $stmt->execute([$oldValue, $documentId]);

        // Logger la restauration
        $this->log(
            $documentId,
            $fieldCode,
            $currentValue,
            $oldValue,
            'manual',
            [
                'reason' => "Revert from audit log #$logId",
                'user_id' => $userId
            ]
        );

        return [
            'success' => true,
            'document_id' => $documentId,
            'field' => $fieldCode,
            'reverted_from' => $currentValue,
            'reverted_to' => $oldValue
        ];
    }

    /**
     * Récupère les labels des champs
     */
    private function getFieldLabels(): array
    {
        return [
            'compte_comptable' => 'Compte comptable',
            'centre_cout' => 'Centre de coût',
            'projet' => 'Projet',
            'correspondent_id' => 'Correspondant',
            'document_type_id' => 'Type de document',
            'logical_folder_id' => 'Dossier',
            'tag' => 'Tag'
        ];
    }

    /**
     * Récupère le label d'une source
     */
    private function getSourceLabel(string $source): string
    {
        $labels = [
            'manual' => 'Manuel',
            'rules' => 'Règles automatiques',
            'ml' => 'Apprentissage automatique',
            'ai' => 'Intelligence artificielle',
            'import' => 'Import',
            'api' => 'API'
        ];

        return $labels[$source] ?? $source;
    }

    /**
     * Nettoie les anciens logs (maintenance)
     */
    public function cleanup(int $keepDays = 365): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            DELETE FROM classification_audit_log
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$keepDays]);
        return $stmt->rowCount();
    }
}
