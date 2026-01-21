<?php
/**
 * K-Docs - Service MailService
 * Traitement automatique des emails
 */

namespace KDocs\Services;

use KDocs\Models\MailAccount;
use KDocs\Models\MailRule;
use KDocs\Models\Document;
use KDocs\Core\Database;

class MailService
{
    /**
     * Traite les emails d'un compte
     */
    public static function processAccount(int $accountId): array
    {
        $account = MailAccount::find($accountId);
        if (!$account || !$account['is_active']) {
            return ['success' => false, 'error' => 'Compte inactif ou introuvable'];
        }
        
        try {
            $password = self::decryptPassword($account['password_encrypted']);
            $mailbox = MailAccount::connect(
                $account['imap_server'],
                $account['imap_port'],
                $account['imap_security'],
                $account['username'],
                $password
            );
            
            if (!$mailbox) {
                return ['success' => false, 'error' => 'Impossible de se connecter au serveur email'];
            }
            
            // Récupérer les règles
            $rules = MailRule::getByAccount($accountId);
            
            // Récupérer les emails non lus
            $emails = imap_search($mailbox, 'UNSEEN');
            $processed = 0;
            $errors = [];
            
            if ($emails) {
                foreach ($emails as $emailNumber) {
                    try {
                        $email = self::fetchEmail($mailbox, $emailNumber);
                        
                        // Appliquer les règles
                        $ruleApplied = false;
                        foreach ($rules as $rule) {
                            if (MailRule::matches($rule, $email)) {
                                $result = self::applyRule($rule, $email, $accountId, $mailbox, $emailNumber);
                                if ($result['success']) {
                                    $processed++;
                                    $ruleApplied = true;
                                    // Marquer comme lu
                                    imap_setflag_full($mailbox, $emailNumber, "\\Seen");
                                } else {
                                    $errors[] = $result['error'];
                                }
                                break; // Une seule règle par email
                            }
                        }
                        
                        // Si aucune règle n'a été appliquée, marquer quand même comme lu pour éviter de le retraiter
                        if (!$ruleApplied) {
                            imap_setflag_full($mailbox, $emailNumber, "\\Seen");
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Erreur email #$emailNumber: " . $e->getMessage();
                    }
                }
            }
            
            imap_close($mailbox);
            MailAccount::updateLastChecked($accountId);
            
            return [
                'success' => true,
                'processed' => $processed,
                'errors' => $errors
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Récupère un email depuis le serveur
     */
    private static function fetchEmail($mailbox, int $emailNumber): array
    {
        $header = imap_headerinfo($mailbox, $emailNumber);
        $structure = imap_fetchstructure($mailbox, $emailNumber);
        $body = imap_body($mailbox, $emailNumber);
        
        $email = [
            'message_id' => $header->message_id,
            'from' => $header->from[0]->mailbox . '@' . $header->from[0]->host,
            'to' => isset($header->to[0]) ? $header->to[0]->mailbox . '@' . $header->to[0]->host : '',
            'subject' => $header->subject ?? '',
            'date' => date('Y-m-d H:i:s', $header->udate),
            'body' => $body,
            'attachments' => []
        ];
        
        // Extraire les pièces jointes
        if (isset($structure->parts)) {
            foreach ($structure->parts as $partNumber => $part) {
                if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                    $attachment = [
                        'filename' => $part->dparameters[0]->value ?? '',
                        'part_number' => $partNumber + 1
                    ];
                    $email['attachments'][] = $attachment;
                }
            }
        }
        
        return $email;
    }
    
    /**
     * Applique une règle à un email
     */
    private static function applyRule(array $rule, array $email, int $accountId): array
    {
        try {
            $db = Database::getInstance();
            
            // Traiter les pièces jointes
            foreach ($email['attachments'] as $attachment) {
                // Note: $mailbox et $emailNumber doivent être passés en paramètre
                // Pour l'instant, on utilise une approche simplifiée
                return ['success' => false, 'error' => 'Traitement des pièces jointes à implémenter complètement'];
                
                // Créer un document temporaire
                $tempFile = tempnam(sys_get_temp_dir(), 'mail_attachment_');
                file_put_contents($tempFile, base64_decode($attachmentData));
                
                // Déterminer le titre
                $title = $email['subject'];
                if ($rule['assign_title_from'] === 'filename') {
                    $title = $attachment['filename'];
                }
                
                // Créer le document
                $correspondentId = self::getOrCreateCorrespondent($email['from']);
                $documentData = [
                    'original_filename' => $attachment['filename'],
                    'title' => $title,
                    'document_date' => $email['date'],
                    'correspondent_id' => $correspondentId,
                    'document_type_id' => $rule['assign_document_type_id'] ?? null,
                    'storage_path_id' => $rule['assign_storage_path_id'] ?? null,
                    'created_by' => null // Système
                ];
                
                $documentId = Document::createFromFile($tempFile, $documentData);
                
                // Assigner les tags
                if (!empty($rule['tag_ids'])) {
                    $tagIds = explode(',', $rule['tag_ids']);
                    foreach ($tagIds as $tagId) {
                        $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                           ->execute([$documentId, (int)$tagId]);
                    }
                }
                
                // Logger
                self::logProcessing($accountId, $rule['id'] ?? null, $email['message_id'], $email['subject'], $email['from'], 'success', null, $documentId);
                
                unlink($tempFile);
            }
            
            return ['success' => true];
            
        } catch (\Exception $e) {
            self::logProcessing($accountId, $rule['id'] ?? null, $email['message_id'] ?? '', $email['subject'] ?? '', $email['from'] ?? '', 'error', $e->getMessage(), null);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Récupère ou crée un correspondant depuis une adresse email
     */
    private static function getOrCreateCorrespondent(string $email): ?int
    {
        $db = Database::getInstance();
        
        // Chercher par email
        $stmt = $db->prepare("SELECT id FROM correspondents WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $correspondent = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($correspondent) {
            return (int)$correspondent['id'];
        }
        
        // Créer un nouveau correspondant
        $name = explode('@', $email)[0];
        $stmt = $db->prepare("INSERT INTO correspondents (name, email) VALUES (?, ?)");
        $stmt->execute([$name, $email]);
        
        return (int)$db->lastInsertId();
    }
    
    /**
     * Log le traitement d'un email
     */
    private static function logProcessing(int $accountId, ?int $ruleId, string $messageId, string $subject, string $from, string $status, ?string $error, ?int $documentId): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO mail_logs (
                mail_account_id, mail_rule_id, message_id, subject, from_address,
                status, error_message, document_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$accountId, $ruleId, $messageId, $subject, $from, $status, $error, $documentId]);
    }
    
    /**
     * Déchiffre un mot de passe (méthode publique pour compatibilité)
     */
    private static function decryptPassword(string $encrypted): string
    {
        $key = \KDocs\Core\Config::get('encryption_key', 'default-key-change-in-production');
        return openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, substr(hash('sha256', $key), 0, 16));
    }
}
