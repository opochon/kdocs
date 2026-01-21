<?php
/**
 * K-Docs - Modèle Webhook
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class Webhook
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Récupère tous les webhooks actifs
     */
    public function getAllActive(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM webhooks 
            WHERE is_active = 1 
            ORDER BY name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère tous les webhooks (actifs et inactifs)
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM webhooks 
            ORDER BY name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupère un webhook par ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Récupère les webhooks actifs qui écoutent un événement spécifique
     */
    public function getActiveForEvent(string $event): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM webhooks 
            WHERE is_active = 1 
            AND JSON_CONTAINS(events, JSON_QUOTE(?))
        ");
        $stmt->execute([$event]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crée un nouveau webhook
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO webhooks (name, url, events, secret, is_active, timeout, retry_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $eventsJson = json_encode($data['events'] ?? []);
        $secret = $data['secret'] ?? bin2hex(random_bytes(32));
        
        $stmt->execute([
            $data['name'],
            $data['url'],
            $eventsJson,
            $secret,
            $data['is_active'] ?? true,
            $data['timeout'] ?? 30,
            $data['retry_count'] ?? 3
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Met à jour un webhook
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];
        
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $values[] = $data['name'];
        }
        
        if (isset($data['url'])) {
            $fields[] = 'url = ?';
            $values[] = $data['url'];
        }
        
        if (isset($data['events'])) {
            $fields[] = 'events = ?';
            $values[] = json_encode($data['events']);
        }
        
        if (isset($data['secret'])) {
            $fields[] = 'secret = ?';
            $values[] = $data['secret'];
        }
        
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $values[] = $data['is_active'] ? 1 : 0;
        }
        
        if (isset($data['timeout'])) {
            $fields[] = 'timeout = ?';
            $values[] = $data['timeout'];
        }
        
        if (isset($data['retry_count'])) {
            $fields[] = 'retry_count = ?';
            $values[] = $data['retry_count'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $id;
        
        $stmt = $this->db->prepare("
            UPDATE webhooks 
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ");
        
        return $stmt->execute($values);
    }

    /**
     * Supprime un webhook
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM webhooks WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Met à jour la date de dernier déclenchement
     */
    public function updateLastTriggered(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE webhooks 
            SET last_triggered_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
    }

    /**
     * Récupère les logs d'un webhook
     */
    public function getLogs(int $webhookId, int $limit = 100): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM webhook_logs 
            WHERE webhook_id = ? 
            ORDER BY executed_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$webhookId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Génère un secret aléatoire
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
