<?php
/**
 * K-Docs - Modèle MailAccount
 * Gestion des comptes email
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class MailAccount
{
    /**
     * Récupère tous les comptes email
     */
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM mail_accounts ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Trouve un compte par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM mail_accounts WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Crée un nouveau compte email
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        
        // Chiffrer le mot de passe
        $passwordEncrypted = self::encryptPassword($data['password']);
        
        $stmt = $db->prepare("
            INSERT INTO mail_accounts (
                name, imap_server, imap_port, imap_security,
                username, password_encrypted, character_set, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['name'],
            $data['imap_server'],
            $data['imap_port'] ?? 993,
            $data['imap_security'] ?? 'ssl',
            $data['username'],
            $passwordEncrypted,
            $data['character_set'] ?? 'UTF-8',
            $data['is_active'] ?? true
        ]);
        
        return (int)$db->lastInsertId();
    }
    
    /**
     * Met à jour un compte email
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();

        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = $data['name'];
        }

        if (isset($data['imap_server'])) {
            $fields[] = 'imap_server = ?';
            $params[] = $data['imap_server'];
        }

        if (isset($data['imap_port'])) {
            $fields[] = 'imap_port = ?';
            $params[] = $data['imap_port'];
        }

        if (isset($data['imap_security'])) {
            $fields[] = 'imap_security = ?';
            $params[] = $data['imap_security'];
        }

        if (isset($data['username'])) {
            $fields[] = 'username = ?';
            $params[] = $data['username'];
        }

        if (!empty($data['password'])) {
            $fields[] = 'password_encrypted = ?';
            $params[] = self::encryptPassword($data['password']);
        }

        if (isset($data['character_set'])) {
            $fields[] = 'character_set = ?';
            $params[] = $data['character_set'];
        }

        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }

        // Ingestion fields
        $ingestionFields = ['folder', 'processed_folder', 'check_interval', 'filter_from',
                           'filter_subject', 'default_correspondent_id', 'default_document_type_id',
                           'default_folder_id'];
        foreach ($ingestionFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field] === '' ? null : $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $stmt = $db->prepare("UPDATE mail_accounts SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }
    
    /**
     * Supprime un compte email
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM mail_accounts WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Teste la connexion à un compte email
     */
    public static function testConnection(int $id): array
    {
        $account = self::find($id);
        if (!$account) {
            return ['success' => false, 'error' => 'Compte introuvable'];
        }
        
        try {
            $password = self::decryptPassword($account['password_encrypted']);
            $mailbox = self::connect($account['imap_server'], $account['imap_port'], $account['imap_security'], $account['username'], $password);
            
            if ($mailbox) {
                imap_close($mailbox);
                return ['success' => true, 'message' => 'Connexion réussie'];
            } else {
                return ['success' => false, 'error' => 'Échec de la connexion'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Se connecte à un serveur IMAP
     */
    public static function connect(string $server, int $port, string $security, string $username, string $password)
    {
        $connectionString = "{" . $server . ":" . $port;
        
        if ($security === 'ssl') {
            $connectionString .= "/ssl";
        } elseif ($security === 'tls') {
            $connectionString .= "/tls";
        }
        
        $connectionString .= "}INBOX";
        
        return @imap_open($connectionString, $username, $password);
    }
    
    /**
     * Chiffre un mot de passe
     */
    private static function encryptPassword(string $password): string
    {
        // Utiliser une clé de chiffrement depuis la config
        $key = \KDocs\Core\Config::get('encryption_key', 'default-key-change-in-production');
        return base64_encode(openssl_encrypt($password, 'AES-256-CBC', $key, 0, substr(hash('sha256', $key), 0, 16)));
    }
    
    /**
     * Déchiffre un mot de passe
     */
    private static function decryptPassword(string $encrypted): string
    {
        $key = \KDocs\Core\Config::get('encryption_key', 'default-key-change-in-production');
        return openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, substr(hash('sha256', $key), 0, 16));
    }
    
    /**
     * Met à jour la date de dernière vérification
     */
    public static function updateLastChecked(int $id): void
    {
        $db = Database::getInstance();
        $db->prepare("UPDATE mail_accounts SET last_checked_at = NOW() WHERE id = ?")->execute([$id]);
    }
}
