<?php
/**
 * K-Docs - SendEmailAction
 * Envoie un email depuis un workflow avec support des variables inter-nœuds
 *
 * Variables supportées:
 *   - {document.field} - Champs du document (title, correspondent_name, amount, etc.)
 *   - {nodeId.key} - Outputs d'autres nœuds (ex: {12.approval_link})
 *   - {nodeName.key} - Outputs par nom de nœud (ex: {CreateApproval.approval_link})
 *   - {key} - Variables globales du contexte (ex: {approval_link})
 *
 * Placeholders document standards:
 *   {title}, {correspondent}, {document_type}, {amount}, {owner}, {created}, {added}, etc.
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;
use KDocs\Services\MailService;

class SendEmailAction extends AbstractNodeExecutor
{
    /**
     * Placeholders document standards (rétro-compatibilité)
     */
    private array $documentPlaceholders = [
        '{correspondent}' => 'correspondent_name',
        '{document_type}' => 'document_type_name',
        '{title}' => 'title',
        '{created}' => 'created_at',
        '{created_year}' => 'created_year',
        '{created_month}' => 'created_month',
        '{created_day}' => 'created_day',
        '{added}' => 'added_at',
        '{added_year}' => 'added_year',
        '{added_month}' => 'added_month',
        '{added_day}' => 'added_day',
        '{asn}' => 'archive_serial_number',
        '{owner}' => 'owner_name',
        '{original_filename}' => 'original_filename',
        '{amount}' => 'amount',
        '{currency}' => 'currency',
        '{date}' => 'doc_date',
    ];

    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }

        $to = $config['to'] ?? null;
        if (!$to) {
            return ExecutionResult::failed('Destinataire email non spécifié');
        }

        // Récupérer les données du document
        $document = $this->getDocumentData($context->documentId);
        if (!$document) {
            return ExecutionResult::failed('Document non trouvé');
        }

        // =====================================================================
        // INTERPOLATION DES VARIABLES
        // =====================================================================

        // D'abord les placeholders document standards (rétro-compatibilité)
        $subject = $this->replaceDocumentPlaceholders($config['subject'] ?? 'Notification document', $document);
        $body = $this->replaceDocumentPlaceholders($config['body'] ?? '', $document);
        $to = $this->replaceDocumentPlaceholders($to, $document);

        // Ensuite l'interpolation avancée via ContextBag (node outputs, etc.)
        $subject = $context->interpolate($subject, $document);
        $body = $context->interpolate($body, $document);
        $to = $context->interpolate($to, $document);

        // =====================================================================
        // VALIDATION ET ENVOI
        // =====================================================================

        // Valider et parser les adresses email (support plusieurs destinataires)
        $recipients = array_map('trim', explode(',', $to));
        $validRecipients = [];
        foreach ($recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $validRecipients[] = $recipient;
            } else {
                error_log("SendEmailAction: Email invalide ignoré: $recipient");
            }
        }

        if (empty($validRecipients)) {
            return ExecutionResult::failed('Aucune adresse email valide spécifiée');
        }

        // Construire le chemin complet du fichier si nécessaire
        $attachment = null;
        if (!empty($config['include_document'])) {
            $filePath = $document['file_path'] ?? null;
            if ($filePath) {
                // Si le chemin est relatif, construire le chemin complet
                if (!file_exists($filePath)) {
                    $configStorage = \KDocs\Core\Config::load();
                    $documentsPath = $configStorage['storage']['documents'] ?? __DIR__ . '/../../../../storage/documents';
                    $fullPath = $documentsPath . '/' . basename($filePath);
                    if (file_exists($fullPath)) {
                        $filePath = $fullPath;
                    }
                }

                if ($filePath && file_exists($filePath)) {
                    $attachment = $filePath;
                } else {
                    error_log("SendEmailAction: Fichier document introuvable: " . ($document['file_path'] ?? 'N/A'));
                }
            }
        }

        // Envoyer les emails
        $mailService = new MailService();
        $sent = false;
        $errors = [];

        foreach ($validRecipients as $recipient) {
            try {
                $result = $mailService->send($recipient, $subject, $body, $attachment);
                if ($result) {
                    $sent = true;
                } else {
                    $errors[] = "Échec envoi à $recipient";
                }
            } catch (\Exception $e) {
                $errors[] = "Erreur pour $recipient: " . $e->getMessage();
                error_log("SendEmailAction: Erreur envoi email à $recipient: " . $e->getMessage());
            }
        }

        if (!$sent) {
            return ExecutionResult::failed('Aucun email envoyé. Erreurs: ' . implode('; ', $errors));
        }

        // Exposer les outputs de ce nœud
        $nodeId = $config['node_id'] ?? null;
        if ($nodeId) {
            $context->setNodeOutput($nodeId, 'sent', true, 'boolean');
            $context->setNodeOutput($nodeId, 'recipients_count', count($validRecipients), 'integer');
            $context->setNodeOutput($nodeId, 'recipients', implode(', ', $validRecipients), 'string');
        }

        return ExecutionResult::success([
            'sent' => true,
            'recipients' => $validRecipients,
            'recipients_count' => count($validRecipients),
            'subject' => $subject,
            'errors' => $errors
        ]);
    }

    /**
     * Récupère les données complètes du document
     */
    private function getDocumentData(int $documentId): ?array
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT
                    d.*,
                    c.name as correspondent_name,
                    dt.label as document_type_name,
                    u.name as owner_name,
                    CONCAT(u.first_name, ' ', u.last_name) as owner_full_name
                FROM documents d
                LEFT JOIN correspondents c ON d.correspondent_id = c.id
                LEFT JOIN document_types dt ON d.document_type_id = dt.id
                LEFT JOIN users u ON d.owner_id = u.id
                WHERE d.id = ?
            ");
            $stmt->execute([$documentId]);
            $document = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($document) {
                // Enrichir avec les dates calculées
                if (!empty($document['created_at'])) {
                    $created = new \DateTime($document['created_at']);
                    $document['created_year'] = $created->format('Y');
                    $document['created_month'] = $created->format('m');
                    $document['created_day'] = $created->format('d');
                }

                if (!empty($document['added_at'])) {
                    $added = new \DateTime($document['added_at']);
                    $document['added_year'] = $added->format('Y');
                    $document['added_month'] = $added->format('m');
                    $document['added_day'] = $added->format('d');
                }

                // Formater le montant
                if (!empty($document['amount'])) {
                    $document['amount_formatted'] = number_format((float)$document['amount'], 2, '.', ' ') . ' ' . ($document['currency'] ?? 'CHF');
                }
            }

            return $document ?: null;
        } catch (\Exception $e) {
            error_log("SendEmailAction: Erreur récupération document: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Remplace les placeholders document standards
     */
    private function replaceDocumentPlaceholders(string $template, array $document): string
    {
        foreach ($this->documentPlaceholders as $placeholder => $field) {
            $value = $document[$field] ?? '';
            $template = str_replace($placeholder, $value, $template);
        }
        return $template;
    }

    /**
     * Schéma des outputs produits
     */
    public function getOutputSchema(): array
    {
        return [
            'sent' => [
                'type' => 'boolean',
                'description' => 'Email envoyé avec succès',
            ],
            'recipients_count' => [
                'type' => 'integer',
                'description' => 'Nombre de destinataires',
            ],
            'recipients' => [
                'type' => 'string',
                'description' => 'Liste des destinataires (séparés par virgule)',
            ],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'to' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Destinataire(s) email (séparés par virgule). Supporte variables: {approval_link}, {nodeId.key}, etc.',
            ],
            'subject' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Sujet de l\'email. Variables: {title}, {correspondent}, {amount}, {nodeId.approval_link}, etc.',
            ],
            'body' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Corps de l\'email (HTML). Variables disponibles: document ({title}, {amount}...), nœuds ({approval_link}, {reject_link}...)',
            ],
            'include_document' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Inclure le document en pièce jointe',
            ],
        ];
    }
}
