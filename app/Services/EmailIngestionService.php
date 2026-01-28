<?php
/**
 * K-Docs - EmailIngestionService
 * Service pour récupérer et traiter les emails entrants
 * Utilise les comptes mail_accounts existants avec les champs d'ingestion supplémentaires
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use PDO;

class EmailIngestionService
{
    private PDO $db;
    private string $consumePath;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->consumePath = dirname(__DIR__, 2) . '/storage/consume';
        $this->ensureTablesExist();
    }

    private function ensureTablesExist(): void
    {
        try {
            // Check if new columns exist
            $this->db->query("SELECT folder FROM mail_accounts LIMIT 1");
        } catch (\Exception $e) {
            // Run migration to add columns
            $sql = file_get_contents(__DIR__ . '/../../database/migrations/021_email_ingestion.sql');
            if ($sql) {
                foreach (explode(';', $sql) as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement)) {
                        try {
                            $this->db->exec($statement);
                        } catch (\Exception $e2) {
                            // Column might already exist, ignore
                        }
                    }
                }
            }
        }
    }

    /**
     * Get all email accounts
     */
    public function getAccounts(): array
    {
        $stmt = $this->db->query("SELECT * FROM mail_accounts ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get active email accounts
     */
    public function getActiveAccounts(): array
    {
        $stmt = $this->db->query("SELECT * FROM mail_accounts WHERE is_active = 1");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get account by ID
     */
    public function getAccount(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM mail_accounts WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Decrypt password using the same method as MailAccount model
     */
    private function decryptPassword(string $encrypted): string
    {
        $key = Config::get('encryption_key', 'default-key-change-in-production');
        return openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, substr(hash('sha256', $key), 0, 16));
    }

    /**
     * Connect to IMAP server
     */
    private function connect(array $account)
    {
        $security = match($account['imap_security'] ?? 'ssl') {
            'ssl' => '/ssl',
            'tls' => '/tls',
            default => '/notls'
        };

        $mailbox = sprintf(
            '{%s:%d/imap%s}%s',
            $account['imap_server'],
            $account['imap_port'] ?? 993,
            $security,
            $account['folder'] ?? 'INBOX'
        );

        // Decrypt password
        $password = $this->decryptPassword($account['password_encrypted']);

        return @imap_open($mailbox, $account['username'], $password);
    }

    /**
     * Test connection to email account
     */
    public function testConnection(int $accountId): array
    {
        $account = $this->getAccount($accountId);
        if (!$account) {
            return ['success' => false, 'error' => 'Compte non trouvé'];
        }

        try {
            $mailbox = $this->connect($account);
            if ($mailbox === false) {
                $error = imap_last_error();
                return ['success' => false, 'error' => $error ?: 'Connexion impossible'];
            }

            $info = imap_check($mailbox);
            imap_close($mailbox);

            return [
                'success' => true,
                'messages' => $info->Nmsgs ?? 0,
                'recent' => $info->Recent ?? 0,
                'folder' => $account['folder'] ?? 'INBOX'
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check and process emails from an account
     */
    public function processAccount(int $accountId): array
    {
        $account = $this->getAccount($accountId);
        if (!$account || !$account['is_active']) {
            return ['success' => false, 'error' => 'Compte inactif ou inexistant'];
        }

        $results = [
            'processed' => 0,
            'documents' => 0,
            'errors' => [],
            'skipped' => 0
        ];

        try {
            $mailbox = $this->connect($account);
            if ($mailbox === false) {
                $error = imap_last_error();
                return ['success' => false, 'error' => "Connexion IMAP échouée: $error"];
            }

            // Get new messages since last UID
            $lastUid = $account['last_uid'] ?? 0;
            $search = $lastUid > 0 ? "UID {$lastUid}:*" : "ALL";

            // Apply filters if set
            if (!empty($account['filter_from'])) {
                $search .= " FROM \"{$account['filter_from']}\"";
            }
            if (!empty($account['filter_subject'])) {
                $search .= " SUBJECT \"{$account['filter_subject']}\"";
            }

            $messages = imap_search($mailbox, $search, SE_UID);

            if ($messages === false) {
                imap_close($mailbox);
                $this->updateLastCheck($accountId, $lastUid);
                return ['success' => true, 'message' => 'Aucun nouveau message', 'results' => $results];
            }

            $maxUid = $lastUid;

            foreach ($messages as $uid) {
                if ($uid <= $lastUid) continue;

                $maxUid = max($maxUid, $uid);

                try {
                    $result = $this->processEmail($mailbox, $uid, $account);
                    $results['processed']++;
                    $results['documents'] += $result['documents'];

                    $this->logIngestion($accountId, $uid, $result);

                    // Move processed email if configured
                    if (!empty($account['processed_folder'])) {
                        imap_mail_move($mailbox, (string)$uid, $account['processed_folder'], CP_UID);
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = "UID $uid: " . $e->getMessage();
                    $this->logIngestion($accountId, $uid, [
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            imap_expunge($mailbox);
            imap_close($mailbox);

            $this->updateLastCheck($accountId, $maxUid);

            return ['success' => true, 'results' => $results];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process a single email
     */
    private function processEmail($mailbox, int $uid, array $account): array
    {
        $header = imap_headerinfo($mailbox, imap_msgno($mailbox, $uid));
        $structure = imap_fetchstructure($mailbox, $uid, FT_UID);

        $result = [
            'from' => $header->fromaddress ?? '',
            'subject' => $this->decodeMimeHeader($header->subject ?? ''),
            'date' => isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : null,
            'attachments' => 0,
            'documents' => 0,
            'status' => 'success'
        ];

        // Find and process attachments
        $attachments = $this->getAttachments($mailbox, $uid, $structure);
        $result['attachments'] = count($attachments);

        if (empty($attachments)) {
            $result['status'] = 'skipped';
            return $result;
        }

        // Ensure consume directory exists
        if (!is_dir($this->consumePath)) {
            mkdir($this->consumePath, 0755, true);
        }

        foreach ($attachments as $attachment) {
            // Save to consume folder
            $filename = $this->sanitizeFilename($attachment['filename']);
            $filepath = $this->consumePath . '/' . $filename;

            // Handle duplicates
            $counter = 1;
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            while (file_exists($filepath)) {
                $filepath = $this->consumePath . '/' . $base . '_' . $counter . '.' . $ext;
                $counter++;
            }

            if (file_put_contents($filepath, $attachment['data'])) {
                $result['documents']++;
            }
        }

        return $result;
    }

    /**
     * Get attachments from email
     */
    private function getAttachments($mailbox, int $uid, $structure, string $partNum = ''): array
    {
        $attachments = [];
        $validExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'doc', 'docx'];

        if (isset($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $currentPart = $partNum ? "$partNum." . ($index + 1) : (string)($index + 1);

                if ($part->ifdparameters) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            $filename = $this->decodeMimeHeader($param->value);
                            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                            if (in_array($ext, $validExtensions)) {
                                $data = imap_fetchbody($mailbox, $uid, $currentPart, FT_UID);

                                if ($part->encoding === 3) { // BASE64
                                    $data = base64_decode($data);
                                } elseif ($part->encoding === 4) { // QUOTED-PRINTABLE
                                    $data = quoted_printable_decode($data);
                                }

                                $attachments[] = [
                                    'filename' => $filename,
                                    'data' => $data,
                                    'type' => $part->subtype ?? 'unknown'
                                ];
                            }
                            break;
                        }
                    }
                }

                // Recurse into multipart
                if (isset($part->parts)) {
                    $attachments = array_merge(
                        $attachments,
                        $this->getAttachments($mailbox, $uid, $part, $currentPart)
                    );
                }
            }
        }

        return $attachments;
    }

    /**
     * Decode MIME header
     */
    private function decodeMimeHeader(string $text): string
    {
        $elements = imap_mime_header_decode($text);
        $result = '';
        foreach ($elements as $element) {
            $result .= $element->text;
        }
        return $result;
    }

    /**
     * Sanitize filename
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove dangerous characters
        $filename = preg_replace('/[^\w\s\-\.\(\)]/u', '_', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        return $filename;
    }

    /**
     * Update last check time and UID
     */
    private function updateLastCheck(int $accountId, int $lastUid): void
    {
        $stmt = $this->db->prepare("
            UPDATE mail_accounts SET last_checked_at = NOW(), last_uid = ? WHERE id = ?
        ");
        $stmt->execute([$lastUid, $accountId]);
    }

    /**
     * Log ingestion result
     */
    private function logIngestion(int $accountId, int $uid, array $result): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_ingestion_logs
                (account_id, email_uid, email_from, email_subject, email_date,
                 attachments_count, documents_created, status, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $accountId,
                $uid,
                $result['from'] ?? null,
                $result['subject'] ?? null,
                $result['date'] ?? null,
                $result['attachments'] ?? 0,
                $result['documents'] ?? 0,
                $result['status'] ?? 'success',
                $result['error'] ?? null
            ]);
        } catch (\Exception $e) {
            // Ignore logging errors
        }
    }

    /**
     * Get ingestion logs
     */
    public function getLogs(int $accountId = null, int $limit = 100): array
    {
        $sql = "SELECT l.*, a.name as account_name
                FROM email_ingestion_logs l
                JOIN mail_accounts a ON l.account_id = a.id";
        $params = [];

        if ($accountId) {
            $sql .= " WHERE l.account_id = ?";
            $params[] = $accountId;
        }

        $sql .= " ORDER BY l.created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Process all active accounts
     */
    public function processAllAccounts(): array
    {
        $accounts = $this->getActiveAccounts();
        $results = [];

        foreach ($accounts as $account) {
            // Check if enough time has passed since last check
            if (!empty($account['last_checked_at'])) {
                $lastCheck = strtotime($account['last_checked_at']);
                $interval = $account['check_interval'] ?? 300;
                if (time() - $lastCheck < $interval) {
                    continue;
                }
            }

            $results[$account['id']] = $this->processAccount($account['id']);
        }

        return $results;
    }

    /**
     * Update account ingestion settings
     */
    public function updateIngestionSettings(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowedFields = ['folder', 'processed_folder', 'check_interval',
                         'filter_from', 'filter_subject', 'default_correspondent_id',
                         'default_document_type_id', 'default_folder_id'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field] === '' ? null : $data[$field];
            }
        }

        if (empty($fields)) return false;

        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE mail_accounts SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }
}
