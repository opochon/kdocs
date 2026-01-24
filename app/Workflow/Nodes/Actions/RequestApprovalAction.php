<?php
/**
 * K-Docs - RequestApprovalAction
 * Envoie une demande d'approbation par email avec lien sécurisé
 * Style Alfresco - Workflow d'approbation complet
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\MailService;

class RequestApprovalAction extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $db = Database::getInstance();
        $appConfig = Config::load();
        $baseUrl = $appConfig['app']['url'] ?? 'http://localhost/kdocs';
        
        // Récupérer les données du document
        $document = $this->getDocumentData($context->documentId);
        if (!$document) {
            return ExecutionResult::failed('Document non trouvé');
        }
        
        // Déterminer le destinataire (utilisateur ou groupe)
        $assignToUserId = $config['assign_to_user_id'] ?? null;
        $assignToGroupId = $config['assign_to_group_id'] ?? null;
        $assignToGroupCode = $config['assign_to_group_code'] ?? null;
        
        // Si un code de groupe est fourni, récupérer l'ID
        if ($assignToGroupCode && !$assignToGroupId) {
            $stmt = $db->prepare("SELECT id FROM user_groups WHERE code = ?");
            $stmt->execute([$assignToGroupCode]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($group) {
                $assignToGroupId = $group['id'];
            }
        }
        
        // Récupérer les emails des destinataires
        $recipients = [];
        
        if ($assignToUserId) {
            $stmt = $db->prepare("SELECT id, username, email, first_name, last_name FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$assignToUserId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($user && $user['email']) {
                $recipients[] = $user;
            }
        }
        
        if ($assignToGroupId) {
            $stmt = $db->prepare("
                SELECT u.id, u.username, u.email, u.first_name, u.last_name
                FROM users u
                INNER JOIN user_group_memberships ugm ON u.id = ugm.user_id
                WHERE ugm.group_id = ? AND u.is_active = 1 AND u.email IS NOT NULL AND u.email != ''
            ");
            $stmt->execute([$assignToGroupId]);
            $groupMembers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($groupMembers as $member) {
                // Éviter les doublons
                if (!in_array($member['id'], array_column($recipients, 'id'))) {
                    $recipients[] = $member;
                }
            }
        }
        
        if (empty($recipients)) {
            return ExecutionResult::failed('Aucun destinataire trouvé pour l\'approbation');
        }
        
        // Générer un token unique pour cette approbation
        $token = bin2hex(random_bytes(32));
        
        // Calculer la date d'expiration
        $expiresHours = $config['expires_hours'] ?? 72; // 3 jours par défaut
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresHours * 3600));
        
        // Préparer le message personnalisé
        $actionRequired = $config['action_required'] ?? 'approve';
        $customMessage = $config['message'] ?? '';
        $emailSubject = $this->replacePlaceholders(
            $config['email_subject'] ?? 'Demande d\'approbation: {title}',
            $document
        );
        
        // Construire le corps de l'email HTML
        $approveUrl = "{$baseUrl}/workflow/approve/{$token}?action=approve";
        $rejectUrl = "{$baseUrl}/workflow/approve/{$token}?action=reject";
        $viewUrl = "{$baseUrl}/documents/{$context->documentId}";
        
        $emailBody = $this->buildEmailBody($document, $customMessage, $approveUrl, $rejectUrl, $viewUrl, $expiresAt, $config);
        
        // Créer l'enregistrement du token d'approbation
        $stmt = $db->prepare("
            INSERT INTO workflow_approval_tokens 
            (token, execution_id, document_id, node_id, assigned_user_id, assigned_group_id, 
             action_required, message, email_subject, email_body, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $token,
            $context->executionId,
            $context->documentId,
            $config['node_id'] ?? null,
            $assignToUserId,
            $assignToGroupId,
            $actionRequired,
            $customMessage,
            $emailSubject,
            $emailBody,
            $expiresAt
        ]);
        
        $tokenId = $db->lastInsertId();
        
        // Créer la tâche d'approbation
        $stmt = $db->prepare("
            INSERT INTO workflow_approval_tasks 
            (execution_id, node_id, document_id, assigned_user_id, assigned_group_id, 
             priority, expires_at, escalate_to_user_id, escalate_after_hours)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $priority = $config['priority'] ?? 'normal';
        $escalateToUserId = $config['escalate_to_user_id'] ?? null;
        $escalateAfterHours = $config['escalate_after_hours'] ?? null;
        
        $stmt->execute([
            $context->executionId,
            $config['node_id'] ?? null,
            $context->documentId,
            $assignToUserId,
            $assignToGroupId,
            $priority,
            $expiresAt,
            $escalateToUserId,
            $escalateAfterHours
        ]);
        
        // Envoyer les emails
        $mailService = new MailService();
        $sentCount = 0;
        $errors = [];
        
        foreach ($recipients as $recipient) {
            try {
                $personalizedBody = str_replace(
                    ['{recipient_name}', '{recipient_email}'],
                    [trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? '')) ?: $recipient['username'], $recipient['email']],
                    $emailBody
                );
                
                $result = $mailService->send($recipient['email'], $emailSubject, $personalizedBody);
                if ($result) {
                    $sentCount++;
                    
                    // Créer une notification
                    $this->createNotification($db, $recipient['id'], $context, $document, $viewUrl);
                } else {
                    $errors[] = "Échec envoi à {$recipient['email']}";
                }
            } catch (\Exception $e) {
                $errors[] = "Erreur pour {$recipient['email']}: " . $e->getMessage();
            }
        }
        
        if ($sentCount === 0) {
            return ExecutionResult::failed('Aucun email envoyé. Erreurs: ' . implode('; ', $errors));
        }
        
        // Mettre le workflow en attente
        return ExecutionResult::waiting('approval', null, [
            'token' => $token,
            'token_id' => $tokenId,
            'sent_count' => $sentCount,
            'recipients' => array_column($recipients, 'email'),
            'expires_at' => $expiresAt,
            'errors' => $errors
        ]);
    }
    
    private function getDocumentData(int $documentId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT 
                d.*,
                c.name as correspondent_name,
                dt.label as document_type_name,
                u.username as owner_username,
                CONCAT(u.first_name, ' ', u.last_name) as owner_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN users u ON d.created_by = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    private function replacePlaceholders(string $template, array $document): string
    {
        $placeholders = [
            '{title}' => $document['title'] ?? $document['original_filename'] ?? 'Sans titre',
            '{correspondent}' => $document['correspondent_name'] ?? 'N/A',
            '{document_type}' => $document['document_type_name'] ?? 'N/A',
            '{amount}' => $document['amount'] ? number_format((float)$document['amount'], 2, '.', ' ') . ' ' . ($document['currency'] ?? 'CHF') : 'N/A',
            '{date}' => $document['doc_date'] ?? $document['document_date'] ?? 'N/A',
            '{created_at}' => $document['created_at'] ?? 'N/A',
            '{original_filename}' => $document['original_filename'] ?? 'N/A',
            '{owner}' => $document['owner_name'] ?? $document['owner_username'] ?? 'N/A',
        ];
        
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }
    
    private function buildEmailBody(array $document, string $customMessage, string $approveUrl, string $rejectUrl, string $viewUrl, string $expiresAt, array $config): string
    {
        $title = $document['title'] ?? $document['original_filename'] ?? 'Sans titre';
        $correspondent = $document['correspondent_name'] ?? 'N/A';
        $documentType = $document['document_type_name'] ?? 'N/A';
        $amount = $document['amount'] ? number_format((float)$document['amount'], 2, '.', ' ') . ' ' . ($document['currency'] ?? 'CHF') : 'N/A';
        $date = $document['doc_date'] ?? $document['document_date'] ?? 'N/A';
        
        $actionLabel = match($config['action_required'] ?? 'approve') {
            'approve' => 'Approuver',
            'reject' => 'Refuser',
            'review' => 'Réviser',
            'sign' => 'Signer',
            default => 'Valider'
        };
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1f2937; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 20px; }
        .content { background: #f9fafb; padding: 20px; border: 1px solid #e5e7eb; }
        .document-info { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .document-info table { width: 100%; border-collapse: collapse; }
        .document-info td { padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .document-info td:first-child { font-weight: 500; color: #6b7280; width: 140px; }
        .message { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 15px 0; border-radius: 0 8px 8px 0; }
        .actions { text-align: center; margin: 25px 0; }
        .btn { display: inline-block; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 500; margin: 0 8px; }
        .btn-approve { background: #10b981; color: white; }
        .btn-reject { background: #ef4444; color: white; }
        .btn-view { background: #3b82f6; color: white; }
        .footer { text-align: center; font-size: 12px; color: #9ca3af; margin-top: 20px; }
        .expires { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; text-align: center; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Demande d'approbation</h1>
        </div>
        <div class="content">
            <p>Bonjour {recipient_name},</p>
            <p>Une demande d'approbation vous a été assignée pour le document suivant :</p>
            
            <div class="document-info">
                <table>
                    <tr><td>Titre</td><td><strong>{$title}</strong></td></tr>
                    <tr><td>Type</td><td>{$documentType}</td></tr>
                    <tr><td>Correspondant</td><td>{$correspondent}</td></tr>
                    <tr><td>Montant</td><td><strong>{$amount}</strong></td></tr>
                    <tr><td>Date du document</td><td>{$date}</td></tr>
                </table>
            </div>
HTML;

        if (!empty($customMessage)) {
            $html .= <<<HTML
            <div class="message">
                <strong>Message :</strong><br>
                {$customMessage}
            </div>
HTML;
        }

        $html .= <<<HTML
            <div class="expires">
                ⏰ Cette demande expire le <strong>{$expiresAt}</strong>
            </div>
            
            <div class="actions">
                <a href="{$approveUrl}" class="btn btn-approve">✅ Approuver</a>
                <a href="{$rejectUrl}" class="btn btn-reject">❌ Refuser</a>
            </div>
            
            <p style="text-align: center;">
                <a href="{$viewUrl}" class="btn btn-view">👁 Voir le document</a>
            </p>
        </div>
        <div class="footer">
            <p>Cet email a été envoyé automatiquement par K-Docs.<br>
            Ne répondez pas à cet email.</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }
    
    private function createNotification(\PDO $db, int $userId, ContextBag $context, array $document, string $link): void
    {
        $title = $document['title'] ?? $document['original_filename'] ?? 'Document';
        $stmt = $db->prepare("
            INSERT INTO workflow_notifications 
            (user_id, execution_id, document_id, type, title, message, link)
            VALUES (?, ?, ?, 'approval_request', ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $context->executionId,
            $context->documentId,
            "Demande d'approbation: $title",
            "Une nouvelle demande d'approbation nécessite votre attention.",
            $link
        ]);
    }
    
    public function getOutputs(): array
    {
        return ['approved', 'rejected', 'timeout', 'cancelled'];
    }
    
    public function getConfigSchema(): array
    {
        return [
            'assign_to_user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID de l\'utilisateur approbateur',
            ],
            'assign_to_group_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID du groupe approbateur',
            ],
            'assign_to_group_code' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Code du groupe approbateur (ex: SUPERVISORS, ACCOUNTING)',
            ],
            'action_required' => [
                'type' => 'string',
                'required' => false,
                'default' => 'approve',
                'description' => 'Type d\'action requise',
                'enum' => ['approve', 'reject', 'review', 'sign']
            ],
            'email_subject' => [
                'type' => 'string',
                'required' => false,
                'default' => 'Demande d\'approbation: {title}',
                'description' => 'Sujet de l\'email (supporte placeholders)',
            ],
            'message' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Message personnalisé dans l\'email',
            ],
            'expires_hours' => [
                'type' => 'integer',
                'required' => false,
                'default' => 72,
                'description' => 'Délai d\'expiration en heures',
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
