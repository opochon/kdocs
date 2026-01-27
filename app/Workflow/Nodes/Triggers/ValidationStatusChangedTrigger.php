<?php
/**
 * K-Docs - ValidationStatusChangedTrigger
 * Déclenche un workflow quand le statut de validation d'un document change
 */

namespace KDocs\Workflow\Nodes\Triggers;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class ValidationStatusChangedTrigger extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        // Un trigger retourne toujours success si on arrive ici
        // La logique de filtrage est dans shouldTrigger()
        return ExecutionResult::success([
            'trigger_type' => 'validation_status_changed',
            'document_id' => $context->documentId
        ]);
    }

    /**
     * Vérifie si ce trigger doit déclencher le workflow
     * Appelé par WorkflowEngine lors d'un événement document_validation_changed
     */
    public static function shouldTrigger(array $config, int $documentId, array $eventContext = []): bool
    {
        $db = Database::getInstance();

        // Récupérer les infos du document
        $stmt = $db->prepare("
            SELECT d.*, dt.code as document_type_code
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return false;
        }

        // Récupérer le nouveau et ancien statut depuis le contexte d'événement
        $newStatus = $eventContext['new_status'] ?? $document['validation_status'] ?? null;
        $previousStatus = $eventContext['previous_status'] ?? null;

        // Filtre par statut cible
        if (!empty($config['filter_status'])) {
            $allowedStatuses = (array)$config['filter_status'];
            if (!in_array($newStatus, $allowedStatuses)) {
                return false;
            }
        }

        // Filtre par statut spécifique "approved"
        if (isset($config['on_approved']) && $config['on_approved'] === true) {
            if ($newStatus !== 'approved') {
                return false;
            }
        }

        // Filtre par statut spécifique "rejected"
        if (isset($config['on_rejected']) && $config['on_rejected'] === true) {
            if ($newStatus !== 'rejected') {
                return false;
            }
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

        // Filtre par niveau de validation
        if (isset($config['filter_validation_level'])) {
            $level = $document['validation_level'] ?? 0;
            if ($level != (int)$config['filter_validation_level']) {
                return false;
            }
        }

        return true;
    }

    public function getOutputs(): array
    {
        return ['approved', 'rejected', 'default'];
    }

    public function getConfigSchema(): array
    {
        return [
            'filter_status' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Statuts qui déclenchent le workflow (approved, rejected, pending)',
                'items' => ['type' => 'string'],
            ],
            'on_approved' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Déclencher uniquement si le document est approuvé',
            ],
            'on_rejected' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Déclencher uniquement si le document est rejeté',
            ],
            'filter_document_type_ids' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Filtrer par IDs de types de document',
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
            'filter_validation_level' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Niveau de validation spécifique (1, 2, 3...)',
            ],
        ];
    }
}
