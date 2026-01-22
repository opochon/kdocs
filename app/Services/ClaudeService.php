<?php
/**
 * K-Docs - Service Claude API
 * Interface avec l'API Anthropic Claude pour la classification IA
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class ClaudeService
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
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 60
        ]);
        
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
            return null;
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ClaudeService JSON decode error: " . json_last_error_msg());
            return null;
        }
        
        return $decoded;
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
