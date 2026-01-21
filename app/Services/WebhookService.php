<?php
/**
 * K-Docs - Service Webhook
 * Gère l'envoi des webhooks et la gestion des erreurs
 */

namespace KDocs\Services;

use KDocs\Models\Webhook;
use KDocs\Core\Database;
use PDO;

class WebhookService
{
    private $webhookModel;
    private $db;

    public function __construct()
    {
        $this->webhookModel = new Webhook();
        $this->db = Database::getInstance();
    }

    /**
     * Déclenche les webhooks pour un événement donné
     */
    public function trigger(string $event, array $data): void
    {
        // Récupérer tous les webhooks actifs qui écoutent cet événement
        $webhooks = $this->webhookModel->getActiveForEvent($event);
        
        foreach ($webhooks as $webhook) {
            $this->sendWebhook($webhook, $event, $data);
        }
    }

    /**
     * Envoie un webhook spécifique
     */
    private function sendWebhook(array $webhook, string $event, array $data, int $retryAttempt = 0): void
    {
        $startTime = microtime(true);
        
        // Construire le payload
        $payload = [
            'event' => $event,
            'timestamp' => date('c'),
            'data' => $data
        ];
        
        $payloadJson = json_encode($payload);
        
        // Générer la signature HMAC
        $signature = hash_hmac('sha256', $payloadJson, $webhook['secret']);
        
        // Préparer les headers
        $headers = [
            'Content-Type: application/json',
            'X-Webhook-Signature: ' . $signature,
            'X-Webhook-Event: ' . $event,
            'X-Webhook-Id: ' . $webhook['id'],
            'User-Agent: K-Docs-Webhook/1.0'
        ];
        
        // Envoyer la requête HTTP POST
        $ch = curl_init($webhook['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $webhook['timeout'] ?? 30,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $executionTime = (int)((microtime(true) - $startTime) * 1000);
        
        // Log de l'exécution
        $this->logWebhook(
            $webhook['id'],
            $event,
            $payload,
            $httpCode,
            $response,
            $error,
            $executionTime
        );
        
        // Mettre à jour la date de dernier déclenchement seulement si succès ou première tentative
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->webhookModel->updateLastTriggered($webhook['id']);
        }
        
        // Retry en cas d'échec (seulement si pas déjà en retry)
        if (($httpCode >= 400 || $error) && $retryAttempt === 0) {
            $this->retryWebhook($webhook, $event, $data, 1);
        }
    }

    /**
     * Retente l'envoi d'un webhook en cas d'échec
     */
    private function retryWebhook(array $webhook, string $event, array $data, int $attempt): void
    {
        $maxRetries = $webhook['retry_count'] ?? 3;
        
        if ($attempt > $maxRetries) {
            return; // Arrêter après le nombre maximum de tentatives
        }
        
        // Attendre avant de réessayer (backoff exponentiel)
        $delay = min(pow(2, $attempt), 60); // Max 60 secondes
        sleep($delay);
        
        // Réessayer avec le compteur de tentative
        $this->sendWebhook($webhook, $event, $data, $attempt);
    }

    /**
     * Enregistre un log d'exécution de webhook
     */
    private function logWebhook(
        int $webhookId,
        string $event,
        array $payload,
        ?int $responseCode,
        ?string $responseBody,
        ?string $errorMessage,
        int $executionTimeMs
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO webhook_logs 
            (webhook_id, event, payload, response_code, response_body, error_message, execution_time_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $webhookId,
            $event,
            json_encode($payload),
            $responseCode,
            $responseBody ? substr($responseBody, 0, 10000) : null, // Limiter à 10KB
            $errorMessage,
            $executionTimeMs
        ]);
    }

    /**
     * Teste un webhook (envoie un événement de test)
     */
    public function testWebhook(int $webhookId): array
    {
        $webhook = $this->webhookModel->getById($webhookId);
        
        if (!$webhook) {
            return ['success' => false, 'error' => 'Webhook non trouvé'];
        }
        
        if (!$webhook['is_active']) {
            return ['success' => false, 'error' => 'Webhook inactif'];
        }
        
        // Envoyer un événement de test
        $testData = [
            'test' => true,
            'message' => 'Ceci est un test de webhook depuis K-Docs',
            'timestamp' => date('c')
        ];
        
        $this->sendWebhook($webhook, 'webhook.test', $testData);
        
        return ['success' => true, 'message' => 'Webhook de test envoyé'];
    }

    /**
     * Récupère les statistiques d'un webhook
     */
    public function getStats(int $webhookId, int $days = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_executions,
                SUM(CASE WHEN response_code >= 200 AND response_code < 300 THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN response_code >= 400 OR error_message IS NOT NULL THEN 1 ELSE 0 END) as error_count,
                AVG(execution_time_ms) as avg_execution_time_ms,
                MAX(execution_time_ms) as max_execution_time_ms
            FROM webhook_logs
            WHERE webhook_id = ?
            AND executed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        $stmt->execute([$webhookId, $days]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
