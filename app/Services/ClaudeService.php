<?php
/**
 * K-Docs - Service Claude API
 * Interface avec l'API Anthropic Claude pour la classification IA
 */

namespace KDocs\Services;

use KDocs\Contracts\AIServiceInterface;
use KDocs\Core\Config;

class ClaudeService implements AIServiceInterface
{
    private string $apiKey;
    private string $model = 'claude-sonnet-4-20250514';
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';
    
    public function __construct()
    {
        $config = Config::load();
        
        // Chercher la clé API (ordre de priorité)
        $this->apiKey = '';
        
        // 1. Config directe
        if (!empty($config['claude']['api_key'])) {
            $this->apiKey = $config['claude']['api_key'];
        }
        // 2. Config ai
        elseif (!empty($config['ai']['claude_api_key'])) {
            $this->apiKey = $config['ai']['claude_api_key'];
        }
        // 3. Variable d'environnement
        elseif (!empty($_ENV['ANTHROPIC_API_KEY'])) {
            $this->apiKey = $_ENV['ANTHROPIC_API_KEY'];
        }
        elseif (!empty(getenv('ANTHROPIC_API_KEY'))) {
            $this->apiKey = getenv('ANTHROPIC_API_KEY');
        }
        // 4. Setting en base
        else {
            try {
                $setting = \KDocs\Models\Setting::get('ai.claude_api_key');
                if (!empty($setting)) {
                    $this->apiKey = $setting;
                }
            } catch (\Exception $e) {}
        }
        // 5. Fichier texte
        if (empty($this->apiKey)) {
            $keyFile = dirname(__DIR__, 2) . '/claude_api_key.txt';
            if (file_exists($keyFile)) {
                $this->apiKey = trim(file_get_contents($keyFile));
            }
        }
        
        if (isset($config['claude']['model'])) {
            $this->model = $config['claude']['model'];
        }
    }
    
    /**
     * Vérifie si Claude est configuré
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
    
    /**
     * Envoyer une requête à Claude
     * 
     * @param string $prompt Le prompt utilisateur
     * @param string|null $systemPrompt Le prompt système (optionnel)
     * @return array|null Réponse de l'API ou null en cas d'erreur
     */
    public function sendMessage(string $prompt, ?string $systemPrompt = null): ?array
    {
        if (!$this->isConfigured()) {
            error_log("ClaudeService: API key not configured");
            return null;
        }
        
        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $body = [
            'model' => $this->model,
            'max_tokens' => 2048,
            'messages' => $messages
        ];
        
        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }
        
        $ch = curl_init($this->apiUrl);
        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];

        // Sur Windows/WAMP, utiliser le bundle CA si disponible
        $caBundle = $this->findCaBundle();
        if ($caBundle) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("ClaudeService curl error: $curlError");
            return null;
        }
        
        if ($httpCode !== 200) {
            error_log("Claude API error: HTTP $httpCode - $response");
            // Enregistrer l'erreur
            $this->logUsage(null, 'text', null, null, "HTTP $httpCode");
            return null;
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ClaudeService JSON decode error: " . json_last_error_msg());
            $this->logUsage(null, 'text', null, null, "JSON decode error: " . json_last_error_msg());
            return null;
        }
        
        // Enregistrer l'utilisation de l'API
        $this->logUsage($decoded, 'text');
        
        return $decoded;
    }
    
    /**
     * Envoyer une requête à Claude avec un fichier (image/PDF)
     * 
     * @param string $prompt Le prompt utilisateur
     * @param string $filePath Chemin vers le fichier
     * @param string|null $systemPrompt Le prompt système (optionnel)
     * @return array|null Réponse de l'API ou null en cas d'erreur
     */
    public function sendMessageWithFile(string $prompt, string $filePath, ?string $systemPrompt = null): ?array
    {
        if (!$this->isConfigured()) {
            error_log("ClaudeService: API key not configured");
            return null;
        }
        
        if (!file_exists($filePath)) {
            error_log("ClaudeService: File not found: $filePath");
            return null;
        }
        
        // Lire le fichier et le convertir en base64
        $fileContent = file_get_contents($filePath);
        $base64Content = base64_encode($fileContent);
        
        // Déterminer le type MIME
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = 'application/pdf';
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            $mimeType = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
        }
        
        // Construire le message avec le fichier
        $content = [
            [
                'type' => 'text',
                'text' => $prompt
            ],
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $base64Content
                ]
            ]
        ];
        
        $messages = [
            ['role' => 'user', 'content' => $content]
        ];
        
        $body = [
            'model' => $this->model,
            'max_tokens' => 4096, // Plus de tokens pour les fichiers
            'messages' => $messages
        ];
        
        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }
        
        $ch = curl_init($this->apiUrl);
        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 120, // Plus de temps pour les fichiers
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];

        // Sur Windows/WAMP, utiliser le bundle CA si disponible
        $caBundle = $this->findCaBundle();
        if ($caBundle) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("ClaudeService curl error: $curlError");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("Claude API error: HTTP $httpCode - $response");
            // Enregistrer l'erreur
            $this->logUsage(null, 'file', null, null, "HTTP $httpCode");
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ClaudeService JSON decode error: " . json_last_error_msg());
            $this->logUsage(null, 'file', null, null, "JSON decode error: " . json_last_error_msg());
            return null;
        }
        
        // Enregistrer l'utilisation de l'API
        $this->logUsage($decoded, 'file');
        
        return $decoded;
    }
    
    /**
     * Enregistrer l'utilisation de l'API dans la base de données
     * 
     * @param array|null $response Réponse de l'API (peut être null en cas d'erreur)
     * @param string $requestType Type de requête ('text', 'file', 'complex')
     * @param int|null $documentId ID du document (optionnel)
     * @param string|null $endpoint Endpoint appelé (optionnel)
     * @param string|null $errorMessage Message d'erreur si échec (optionnel)
     */
    private function logUsage(?array $response, string $requestType, ?int $documentId = null, ?string $endpoint = null, ?string $errorMessage = null): void
    {
        try {
            $db = \KDocs\Core\Database::getInstance();
            
            // Vérifier si la table existe
            $tableExists = false;
            try {
                $db->query("SELECT 1 FROM api_usage_logs LIMIT 1");
                $tableExists = true;
            } catch (\Exception $e) {
                // Table n'existe pas encore, ignorer silencieusement
                return;
            }
            
            if (!$tableExists) {
                return;
            }
            
            $inputTokens = 0;
            $outputTokens = 0;
            $totalTokens = 0;
            $success = true;
            
            if ($response && isset($response['usage'])) {
                $inputTokens = (int)($response['usage']['input_tokens'] ?? 0);
                $outputTokens = (int)($response['usage']['output_tokens'] ?? 0);
                $totalTokens = $inputTokens + $outputTokens;
            } else if ($errorMessage) {
                $success = false;
            }
            
            // Calculer le coût estimé (prix pour Claude Sonnet 4)
            // Input: $3.00 par million de tokens
            // Output: $15.00 par million de tokens
            $inputCost = ($inputTokens / 1000000) * 3.00;
            $outputCost = ($outputTokens / 1000000) * 15.00;
            $estimatedCost = $inputCost + $outputCost;
            
            $stmt = $db->prepare("
                INSERT INTO api_usage_logs 
                (model, request_type, input_tokens, output_tokens, total_tokens, estimated_cost_usd, document_id, endpoint, success, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $this->model,
                $requestType,
                $inputTokens,
                $outputTokens,
                $totalTokens,
                $estimatedCost,
                $documentId,
                $endpoint,
                $success ? 1 : 0,
                $errorMessage
            ]);
        } catch (\Exception $e) {
            // Ne pas interrompre le flux en cas d'erreur de logging
            error_log("ClaudeService: Error logging usage: " . $e->getMessage());
        }
    }
    
    /**
     * Enregistrer l'utilisation avec contexte (pour les appels depuis les contrôleurs)
     */
    public function logUsageWithContext(?array $response, string $requestType, ?int $documentId = null, ?string $endpoint = null, ?string $errorMessage = null): void
    {
        $this->logUsage($response, $requestType, $documentId, $endpoint, $errorMessage);
    }
    
    /**
     * Trouve le bundle CA pour la vérification SSL
     * Nécessaire sur Windows/WAMP où curl n'a pas de CA bundle par défaut
     */
    private function findCaBundle(): ?string
    {
        // Chemins courants pour le bundle CA
        $paths = [
            // WAMP avec PHP 8.x (ordre par version décroissante)
            'C:/wamp64/bin/php/php8.3.14/extras/ssl/cacert.pem',
            'C:/wamp64/bin/php/php8.4.0/extras/ssl/cacert.pem',
            'C:/wamp64/bin/php/php8.2.0/extras/ssl/cacert.pem',
            'C:/wamp64/bin/php/extras/ssl/cacert.pem',
            // Dans le dossier de l'app
            __DIR__ . '/../../cacert.pem',
            // XAMPP
            'C:/xampp/php/extras/ssl/cacert.pem',
            // Certifi standard
            'C:/cacert.pem',
            // Linux
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/ca-bundle.pem',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Vérifier la config PHP
        $iniPath = ini_get('curl.cainfo');
        if ($iniPath && file_exists($iniPath)) {
            return $iniPath;
        }

        $iniPath = ini_get('openssl.cafile');
        if ($iniPath && file_exists($iniPath)) {
            return $iniPath;
        }

        return null;
    }

    /**
     * Extraire le texte de la réponse Claude
     *
     * @param array $response Réponse de l'API Claude
     * @return string Texte extrait
     */
    public function extractText(array $response): string
    {
        if (isset($response['content'][0]['text'])) {
            return $response['content'][0]['text'];
        }
        return '';
    }
}
