<?php
/**
 * K-Docs - SendEmailAction
 * Envoie un email depuis un workflow
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
     * Placeholders disponibles pour les templates email
     */
    private array $placeholders = [
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
        
        // Remplacer les placeholders dans le sujet et le corps
        $subject = $this->replacePlaceholders($config['subject'] ?? 'Notification document', $document);
        $body = $this->replacePlaceholders($config['body'] ?? '', $document);
        
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
                    u.name as owner_name
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
            }
            
            return $document ?: null;
        } catch (\Exception $e) {
            error_log("SendEmailAction: Erreur récupération document: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Remplace les placeholders dans un template
     */
    private function replacePlaceholders(string $template, array $document): string
    {
        foreach ($this->placeholders as $placeholder => $field) {
            $value = $document[$field] ?? '';
            $template = str_replace($placeholder, $value, $template);
        }
        return $template;
    }
    
    public function getConfigSchema(): array
    {
        return [
            'to' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Destinataire(s) email (séparés par virgule)',
            ],
            'subject' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Sujet de l\'email (supporte placeholders: {title}, {correspondent}, etc.)',
            ],
            'body' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Corps de l\'email (HTML, supporte placeholders)',
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
