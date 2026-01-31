<?php
/**
 * K-Docs - Service d'import de fichiers MSG (Outlook)
 * 
 * Fonctionnalités :
 * - Import du mail comme document parent
 * - Extraction et import des pièces jointes liées
 * - Groupement par thread (conversation)
 * - Métadonnées riches (expéditeur, date, destinataires)
 * 
 * Utilise hfig/mapi (PHP pur) pour parser les fichiers MSG
 * Installation: composer require hfig/mapi pear/pear-core-minimal
 */

namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Core\Database;

class MSGImportService
{
    private string $tempDir;
    private string $storageDir;
    private \PDO $db;
    private bool $libraryAvailable;
    
    public function __construct()
    {
        $config = Config::load();
        $this->tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../storage/temp';
        $this->storageDir = $config['storage']['documents'] ?? __DIR__ . '/../../storage/documents';
        $this->db = Database::getInstance();
        
        // Vérifier si la bibliothèque PHP est disponible
        $this->libraryAvailable = class_exists('\\Hfig\\MAPI\\MapiMessageFactory');
        
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }
    
    /**
     * Vérifie si l'import MSG est disponible (bibliothèque PHP)
     */
    public function isAvailable(): bool
    {
        return $this->libraryAvailable;
    }
    
    /**
     * Message d'installation si non disponible
     */
    public function getInstallMessage(): string
    {
        if ($this->libraryAvailable) {
            return 'Import MSG disponible';
        }
        return 'Bibliothèque PHP requise: composer require hfig/mapi pear/pear-core-minimal';
    }
    
    /**
     * Import complet d'un MSG avec ses pièces jointes
     * 
     * @param string $msgPath Chemin vers le fichier .msg
     * @param int|null $userId ID de l'utilisateur
     * @param array $options Options d'import
     * @return array Résultat avec mail_id et attachment_ids
     */
    public function importWithAttachments(string $msgPath, ?int $userId = null, array $options = []): array
    {
        $result = [
            'success' => false,
            'mail_id' => null,
            'attachment_ids' => [],
            'thread_id' => null,
            'error' => null,
        ];
        
        if (!file_exists($msgPath)) {
            $result['error'] = 'Fichier introuvable';
            return $result;
        }
        
        if (!$this->libraryAvailable) {
            $result['error'] = $this->getInstallMessage();
            return $result;
        }
        
        // Extraire tout le contenu du MSG via PHP
        $extracted = $this->extractMSGComplete($msgPath);
        if (!$extracted) {
            $result['error'] = 'Extraction MSG échouée';
            return $result;
        }
        
        // Calculer le thread_id
        $threadId = $this->computeThreadId($extracted['subject'] ?? '');
        $result['thread_id'] = $threadId;
        
        // 1. Créer le document mail parent
        $mailId = $this->createMailDocument($extracted, $msgPath, $userId, $threadId);
        if (!$mailId) {
            $result['error'] = 'Création document mail échouée';
            return $result;
        }
        $result['mail_id'] = $mailId;
        
        // 2. Extraire et importer chaque pièce jointe
        if (!empty($extracted['attachments'])) {
            foreach ($extracted['attachments'] as $attachment) {
                $attId = $this->importAttachment($attachment, $mailId, $userId, $extracted);
                if ($attId) {
                    $result['attachment_ids'][] = $attId;
                }
            }
        }
        
        // 3. Mettre à jour le mail avec le compte des PJ importées
        $this->updateMailAttachmentCount($mailId, count($result['attachment_ids']));
        
        // 4. Nettoyer les fichiers temporaires des pièces jointes
        if (!empty($extracted['attachments'])) {
            foreach ($extracted['attachments'] as $att) {
                if (!empty($att['temp_path']) && file_exists($att['temp_path'])) {
                    @unlink($att['temp_path']);
                }
            }
        }
        
        $result['success'] = true;
        return $result;
    }
    
    /**
     * Import simple (mail seul, sans extraire les PJ)
     */
    public function import(string $msgPath, ?int $userId = null): ?array
    {
        $result = $this->importWithAttachments($msgPath, $userId);
        if ($result['success']) {
            return [
                'document_id' => $result['mail_id'],
                'thread_id' => $result['thread_id'],
            ];
        }
        return null;
    }
    
    /**
     * Extraction complète du MSG via PHP (hfig/mapi)
     */
    private function extractMSGComplete(string $msgPath): ?array
    {
        try {
            $messageFactory = new \Hfig\MAPI\MapiMessageFactory();
            $documentFactory = new \Hfig\MAPI\OLE\Pear\DocumentFactory();
            
            $ole = $documentFactory->createFromFile($msgPath);
            $message = $messageFactory->parseMessage($ole);
            
            // Extraire les propriétés
            $data = [
                'subject' => $message->properties['subject'] ?? '(sans sujet)',
                'sender' => $message->getSender() ?? '',
                'sender_email' => $message->properties['sender_email_address'] ?? '',
                'recipients' => $this->formatRecipients($message->getRecipients(), 'To'),
                'cc' => $this->formatRecipients($message->getRecipients(), 'Cc'),
                'date' => $this->extractDate($message),
                'body' => $message->getBody() ?? '',
                'html_body' => $message->getBodyHTML() ?? '',
                'attachments' => [],
            ];
            
            // Extraire les pièces jointes
            foreach ($message->getAttachments() as $i => $attachment) {
                $filename = $attachment->getFilename();
                if (empty($filename)) {
                    $filename = 'attachment_' . $i;
                }
                
                // Sauvegarder temporairement
                $tempPath = $this->tempDir . '/' . uniqid('att_') . '_' . $this->sanitizeFilename($filename);
                $attachmentData = $attachment->getData();
                
                if ($attachmentData) {
                    file_put_contents($tempPath, $attachmentData);
                    
                    $data['attachments'][] = [
                        'index' => $i,
                        'filename' => $filename,
                        'size' => strlen($attachmentData),
                        'temp_path' => $tempPath,
                        'mime_type' => $attachment->getMimeType() ?? mime_content_type($tempPath),
                    ];
                }
            }
            
            return $data;
            
        } catch (\Exception $e) {
            error_log("MSG extraction failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Formate les destinataires
     */
    private function formatRecipients($recipients, string $type): string
    {
        $filtered = [];
        foreach ($recipients as $recipient) {
            if ($recipient->getType() === $type) {
                $filtered[] = (string) $recipient;
            }
        }
        return implode(', ', $filtered);
    }
    
    /**
     * Extrait la date du message
     */
    private function extractDate($message): ?string
    {
        $date = $message->properties['message_delivery_time'] 
             ?? $message->properties['client_submit_time']
             ?? $message->properties['creation_time']
             ?? null;
        
        if ($date instanceof \DateTime) {
            return $date->format('Y-m-d H:i:s');
        }
        
        return $date;
    }
    
    /**
     * Nettoie un nom de fichier
     */
    private function sanitizeFilename(string $filename): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    }
    
    /**
     * Crée le document mail parent
     */
    private function createMailDocument(array $data, string $originalPath, ?int $userId, string $threadId): ?int
    {
        // Construire le contenu textuel
        $content = $this->buildMailContent($data);
        
        // Titre = sujet
        $title = $data['subject'] ?? '(sans sujet)';
        
        // Date
        $docDate = null;
        if (!empty($data['date'])) {
            try {
                $dt = new \DateTime($data['date']);
                $docDate = $dt->format('Y-m-d');
            } catch (\Exception $e) {}
        }
        
        // Copier le MSG original
        $filename = uniqid('mail_') . '.msg';
        $targetPath = $this->storageDir . '/' . $filename;
        
        if (!copy($originalPath, $targetPath)) {
            return null;
        }
        
        // Chercher ou créer le correspondant
        $correspondentId = $this->findOrCreateCorrespondent(
            $data['sender'] ?? '',
            $data['sender_email'] ?? ''
        );
        
        // Métadonnées enrichies
        $metadata = [
            'type' => 'email',
            'msg_import' => true,
            'sender' => $data['sender'] ?? '',
            'sender_email' => $data['sender_email'] ?? '',
            'recipients' => $data['recipients'] ?? '',
            'cc' => $data['cc'] ?? '',
            'thread_id' => $threadId,
            'attachments_count' => count($data['attachments'] ?? []),
            'attachments_imported' => 0,
            'original_date' => $data['date'] ?? null,
        ];
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO documents (
                    title, filename, original_filename, file_path, file_size, 
                    mime_type, content, ocr_text, doc_date, correspondent_id,
                    created_by, metadata, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    'message/rfc822', ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            
            $stmt->execute([
                $title,
                $filename,
                basename($originalPath),
                $targetPath,
                filesize($targetPath),
                $content,
                $content,
                $docDate,
                $correspondentId,
                $userId,
                json_encode($metadata),
            ]);
            
            return (int) $this->db->lastInsertId();
            
        } catch (\Exception $e) {
            error_log("Mail document creation failed: " . $e->getMessage());
            @unlink($targetPath);
            return null;
        }
    }
    
    /**
     * Importe une pièce jointe liée au mail parent
     */
    private function importAttachment(array $attachment, int $parentMailId, ?int $userId, array $mailData): ?int
    {
        if (empty($attachment['temp_path']) || !file_exists($attachment['temp_path'])) {
            return null;
        }
        
        $originalFilename = $attachment['filename'] ?? 'attachment';
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        
        // Vérifier extension autorisée
        $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'tiff', 'tif', 'txt', 'rtf', 'odt', 'ods'];
        if (!in_array($extension, $allowedExt)) {
            error_log("Attachment skipped (extension not allowed): " . $originalFilename);
            return null;
        }
        
        // Copier dans storage
        $filename = uniqid('att_') . '.' . $extension;
        $targetPath = $this->storageDir . '/' . $filename;
        
        if (!copy($attachment['temp_path'], $targetPath)) {
            return null;
        }
        
        // Déterminer le mime type
        $mimeType = $attachment['mime_type'] ?? mime_content_type($targetPath) ?: 'application/octet-stream';
        
        // Date du mail = date du document
        $docDate = null;
        if (!empty($mailData['date'])) {
            try {
                $dt = new \DateTime($mailData['date']);
                $docDate = $dt->format('Y-m-d');
            } catch (\Exception $e) {}
        }
        
        // Correspondant du mail parent
        $correspondentId = $this->findOrCreateCorrespondent(
            $mailData['sender'] ?? '',
            $mailData['sender_email'] ?? ''
        );
        
        // Métadonnées
        $metadata = [
            'type' => 'email_attachment',
            'parent_mail_id' => $parentMailId,
            'mail_subject' => $mailData['subject'] ?? '',
            'mail_sender' => $mailData['sender'] ?? '',
            'mail_date' => $mailData['date'] ?? null,
            'attachment_index' => $attachment['index'] ?? 0,
        ];
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO documents (
                    title, filename, original_filename, file_path, file_size, 
                    mime_type, doc_date, correspondent_id, parent_id,
                    created_by, metadata, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            
            // Titre = nom du fichier
            $title = $originalFilename;
            
            $stmt->execute([
                $title,
                $filename,
                $originalFilename,
                $targetPath,
                filesize($targetPath),
                $mimeType,
                $docDate,
                $correspondentId,
                $parentMailId,  // Lien vers le mail parent
                $userId,
                json_encode($metadata),
            ]);
            
            return (int) $this->db->lastInsertId();
            
        } catch (\Exception $e) {
            error_log("Attachment import failed: " . $e->getMessage());
            @unlink($targetPath);
            return null;
        }
    }
    
    /**
     * Met à jour le compteur de PJ importées sur le mail
     */
    private function updateMailAttachmentCount(int $mailId, int $count): void
    {
        try {
            $stmt = $this->db->prepare("SELECT metadata FROM documents WHERE id = ?");
            $stmt->execute([$mailId]);
            $row = $stmt->fetch();
            
            if ($row) {
                $metadata = json_decode($row['metadata'] ?? '{}', true);
                $metadata['attachments_imported'] = $count;
                
                $updateStmt = $this->db->prepare("UPDATE documents SET metadata = ? WHERE id = ?");
                $updateStmt->execute([json_encode($metadata), $mailId]);
            }
        } catch (\Exception $e) {
            error_log("Update attachment count failed: " . $e->getMessage());
        }
    }
    
    /**
     * Trouve ou crée un correspondant
     */
    private function findOrCreateCorrespondent(string $name, string $email): ?int
    {
        if (empty($name) && empty($email)) {
            return null;
        }
        
        $searchName = $name ?: $email;
        
        $stmt = $this->db->prepare("
            SELECT id FROM correspondents 
            WHERE name = ? OR email = ? 
            LIMIT 1
        ");
        $stmt->execute([$searchName, $email]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return (int) $existing['id'];
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO correspondents (name, email, created_at) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$searchName, $email ?: null]);
            return (int) $this->db->lastInsertId();
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Calcule un ID de thread basé sur le sujet
     */
    private function computeThreadId(string $subject): string
    {
        $clean = preg_replace('/^(RE|FW|TR|Fwd|Re|Rép|Réf)[\s_:]+/i', '', $subject);
        $clean = preg_replace('/\s*\(\d+\)\s*$/', '', $clean);
        $clean = trim(strtolower($clean));
        
        if (empty($clean)) {
            return 'thread_' . uniqid();
        }
        
        return 'thread_' . substr(md5($clean), 0, 12);
    }
    
    /**
     * Construit le contenu textuel du mail
     */
    private function buildMailContent(array $data): string
    {
        $lines = [];
        
        $lines[] = "══════════════════════════════════════════════════════════";
        $lines[] = "EMAIL";
        $lines[] = "══════════════════════════════════════════════════════════";
        $lines[] = "";
        $lines[] = "De: " . ($data['sender'] ?? '');
        if (!empty($data['sender_email']) && $data['sender_email'] !== $data['sender']) {
            $lines[] = "    <" . $data['sender_email'] . ">";
        }
        $lines[] = "À: " . ($data['recipients'] ?? '');
        if (!empty($data['cc'])) {
            $lines[] = "Cc: " . $data['cc'];
        }
        $lines[] = "Date: " . ($data['date'] ?? 'inconnue');
        $lines[] = "Sujet: " . ($data['subject'] ?? '');
        $lines[] = "";
        $lines[] = "──────────────────────────────────────────────────────────";
        $lines[] = "";
        $lines[] = $data['body'] ?? '';
        
        if (!empty($data['attachments'])) {
            $lines[] = "";
            $lines[] = "──────────────────────────────────────────────────────────";
            $lines[] = "PIÈCES JOINTES (" . count($data['attachments']) . ")";
            $lines[] = "──────────────────────────────────────────────────────────";
            foreach ($data['attachments'] as $att) {
                $size = isset($att['size']) ? ' (' . $this->formatSize($att['size']) . ')' : '';
                $lines[] = "  • " . ($att['filename'] ?? 'attachment') . $size;
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Formate une taille en bytes
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' o';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' Ko';
        return round($bytes / 1048576, 1) . ' Mo';
    }
    
    /**
     * Récupère tous les documents d'un thread
     */
    public function getThreadDocuments(string $threadId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, c.name as correspondent_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(d.metadata, '\$.thread_id')) = ?
            ORDER BY d.doc_date ASC, d.created_at ASC
        ");
        $stmt->execute([$threadId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Récupère le mail parent et ses pièces jointes
     */
    public function getMailWithAttachments(int $mailId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, c.name as correspondent_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.id = ?
        ");
        $stmt->execute([$mailId]);
        $mail = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$mail) {
            return ['mail' => null, 'attachments' => []];
        }
        
        $stmt = $this->db->prepare("
            SELECT d.*, c.name as correspondent_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            WHERE d.parent_id = ?
            ORDER BY d.created_at ASC
        ");
        $stmt->execute([$mailId]);
        $attachments = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return [
            'mail' => $mail,
            'attachments' => $attachments,
        ];
    }
    
    /**
     * Import d'un dossier complet de MSG
     */
    public function importDirectory(string $directory, ?int $userId = null, ?callable $progressCallback = null): array
    {
        $stats = [
            'total' => 0,
            'imported' => 0,
            'attachments' => 0,
            'threads' => [],
            'errors' => [],
        ];
        
        $files = glob($directory . '/*.msg');
        $stats['total'] = count($files);
        
        foreach ($files as $i => $file) {
            if ($progressCallback) {
                $progressCallback($i + 1, $stats['total'], basename($file));
            }
            
            try {
                $result = $this->importWithAttachments($file, $userId);
                
                if ($result['success']) {
                    $stats['imported']++;
                    $stats['attachments'] += count($result['attachment_ids']);
                    
                    if ($result['thread_id']) {
                        $stats['threads'][$result['thread_id']] = 
                            ($stats['threads'][$result['thread_id']] ?? 0) + 1;
                    }
                } else {
                    $stats['errors'][] = basename($file) . ': ' . ($result['error'] ?? 'unknown');
                }
            } catch (\Exception $e) {
                $stats['errors'][] = basename($file) . ': ' . $e->getMessage();
            }
        }
        
        $stats['unique_threads'] = count($stats['threads']);
        unset($stats['threads']);
        
        return $stats;
    }
}
