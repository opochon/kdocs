<?php
/**
 * K-Docs - AI Provider Service
 * Gestion unifiée des providers IA avec fallback automatique
 * 
 * Priorité : Claude > Ollama > Rules-only
 */

namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Core\Database;

class AIProviderService
{
    private const PROVIDER_CLAUDE = 'claude';
    private const PROVIDER_OLLAMA = 'ollama';
    private const PROVIDER_NONE = 'none';
    
    private static ?string $cachedProvider = null;
    private static ?array $cachedStatus = null;
    
    private ?ClaudeService $claudeService = null;
    private ?string $ollamaUrl = null;
    private ?string $ollamaModel = null;
    
    public function __construct()
    {
        $this->ollamaUrl = Config::get('api.ollama_url', 'http://localhost:11434');
        // llama3.1:8b tested and proven working in POC
        $this->ollamaModel = Config::get('ollama.model', 'llama3.1:8b');
    }
    
    /**
     * Détermine le meilleur provider disponible
     */
    public function getBestProvider(): string
    {
        if (self::$cachedProvider !== null) {
            return self::$cachedProvider;
        }
        
        // 1. Essayer Claude
        if ($this->isClaudeAvailable()) {
            self::$cachedProvider = self::PROVIDER_CLAUDE;
            return self::PROVIDER_CLAUDE;
        }
        
        // 2. Fallback sur Ollama
        if ($this->isOllamaAvailable()) {
            self::$cachedProvider = self::PROVIDER_OLLAMA;
            return self::PROVIDER_OLLAMA;
        }
        
        // 3. Aucun provider IA
        self::$cachedProvider = self::PROVIDER_NONE;
        return self::PROVIDER_NONE;
    }
    
    /**
     * Vérifie si une IA est disponible (Claude ou Ollama)
     */
    public function isAIAvailable(): bool
    {
        return $this->getBestProvider() !== self::PROVIDER_NONE;
    }
    
    /**
     * Vérifie si Claude est configuré et disponible
     */
    public function isClaudeAvailable(): bool
    {
        try {
            $claudeService = $this->getClaudeService();
            return $claudeService->isConfigured();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Vérifie si Ollama est disponible
     */
    public function isOllamaAvailable(): bool
    {
        try {
            $ch = curl_init($this->ollamaUrl . '/api/tags');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200 && $response !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Obtient le statut complet des providers
     */
    public function getStatus(): array
    {
        if (self::$cachedStatus !== null) {
            return self::$cachedStatus;
        }
        
        $claudeAvailable = $this->isClaudeAvailable();
        $ollamaAvailable = $this->isOllamaAvailable();
        $ollamaModels = $ollamaAvailable ? $this->getOllamaModels() : [];
        
        self::$cachedStatus = [
            'active_provider' => $this->getBestProvider(),
            'ai_available' => $claudeAvailable || $ollamaAvailable,
            'claude' => [
                'available' => $claudeAvailable,
                'configured' => $this->isClaudeConfigured(),
                'model' => Config::get('claude.model', 'claude-sonnet-4-20250514'),
            ],
            'ollama' => [
                'available' => $ollamaAvailable,
                'url' => $this->ollamaUrl,
                'model' => $this->ollamaModel,
                'models' => $ollamaModels,
                'has_llm' => $this->ollamaHasLLM($ollamaModels),
                'has_embedding' => $this->ollamaHasEmbedding($ollamaModels),
            ],
            'fallback_active' => !$claudeAvailable && $ollamaAvailable,
        ];
        
        return self::$cachedStatus;
    }
    
    /**
     * Reset le cache (utile après changement de config)
     */
    public static function resetCache(): void
    {
        self::$cachedProvider = null;
        self::$cachedStatus = null;
    }
    
    /**
     * Envoie un prompt au meilleur provider disponible
     * CASCADE: Claude > Ollama (avec fallback automatique)
     */
    public function complete(string $prompt, array $options = []): ?array
    {
        $provider = $this->getBestProvider();

        // Essayer Claude d'abord si c'est le provider principal
        if ($provider === self::PROVIDER_CLAUDE) {
            $result = $this->completeWithClaude($prompt, $options);
            if ($result !== null) {
                return $result;
            }
            // Fallback sur Ollama si Claude échoue
            error_log("AIProviderService: Claude failed, falling back to Ollama");
            if ($this->isOllamaAvailable()) {
                return $this->completeWithOllama($prompt, $options);
            }
            return null;
        }

        // Ollama direct si c'est le provider principal
        if ($provider === self::PROVIDER_OLLAMA) {
            return $this->completeWithOllama($prompt, $options);
        }

        return null;
    }
    
    /**
     * Classification de document via IA avec cascade:
     * Training (corrections) → Claude → Ollama → Rules
     */
    public function classifyDocument(string $content, string $filename, array $context = []): ?array
    {
        // 1. Check training data first (learned corrections)
        try {
            $trainingService = new TrainingService();

            // Try similarity-based match from past corrections
            $trainedResult = $trainingService->getTrainedClassification($content);
            if ($trainedResult && ($trainedResult['confidence'] ?? 0) >= 0.85) {
                $trainedResult['provider'] = 'training';
                return $trainedResult;
            }

            // Try learned rules
            $rulesResult = $trainingService->applyLearnedRules($content);
            if ($rulesResult && ($rulesResult['confidence'] ?? 0) >= 0.75) {
                $rulesResult['provider'] = 'learned_rules';
                return $rulesResult;
            }
        } catch (\Exception $e) {
            // Training not available, continue with AI
        }

        // 2. Try AI providers (Claude → Ollama)
        $provider = $this->getBestProvider();

        if ($provider === self::PROVIDER_NONE) {
            return null;
        }

        $prompt = $this->buildClassificationPrompt($content, $filename, $context);
        $response = $this->complete($prompt, ['max_tokens' => 1500]);

        if (!$response || empty($response['text'])) {
            return null;
        }

        $result = $this->parseClassificationResponse($response['text']);
        if ($result) {
            $result['provider'] = $response['provider'] ?? $provider;
        }

        return $result;
    }
    
    /**
     * Extraction de données structurées
     */
    public function extractData(string $content, array $fields = []): ?array
    {
        $provider = $this->getBestProvider();
        
        if ($provider === self::PROVIDER_NONE) {
            return null;
        }
        
        $prompt = $this->buildExtractionPrompt($content, $fields);
        $response = $this->complete($prompt, ['max_tokens' => 2000]);
        
        if (!$response || empty($response['text'])) {
            return null;
        }
        
        return $this->parseJsonResponse($response['text']);
    }
    
    /**
     * Génération de résumé
     */
    public function summarize(string $content, int $maxLength = 200): ?string
    {
        $provider = $this->getBestProvider();
        
        if ($provider === self::PROVIDER_NONE) {
            return null;
        }
        
        $prompt = "Résume ce document en {$maxLength} caractères maximum, en français:\n\n{$content}";
        $response = $this->complete($prompt, ['max_tokens' => 500]);
        
        return $response['text'] ?? null;
    }
    
    /**
     * Complétion via Claude
     */
    private function completeWithClaude(string $prompt, array $options = []): ?array
    {
        try {
            $claudeService = $this->getClaudeService();
            $response = $claudeService->sendMessage($prompt);
            
            if (!$response) {
                return null;
            }
            
            $text = $claudeService->extractText($response);
            
            return [
                'provider' => self::PROVIDER_CLAUDE,
                'model' => Config::get('claude.model', 'claude-sonnet-4-20250514'),
                'text' => $text,
                'raw' => $response,
            ];
        } catch (\Exception $e) {
            error_log("AIProviderService Claude error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Complétion via Ollama
     */
    private function completeWithOllama(string $prompt, array $options = []): ?array
    {
        try {
            $url = $this->ollamaUrl . '/api/generate';
            
            $payload = [
                'model' => $options['model'] ?? $this->ollamaModel,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => $options['temperature'] ?? 0.3,
                    'num_predict' => $options['max_tokens'] ?? 2000,
                ],
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 120, // Ollama peut être lent
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error || $httpCode !== 200) {
                error_log("AIProviderService Ollama error: HTTP $httpCode - $error");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (!isset($data['response'])) {
                return null;
            }
            
            return [
                'provider' => self::PROVIDER_OLLAMA,
                'model' => $payload['model'],
                'text' => $data['response'],
                'raw' => $data,
            ];
        } catch (\Exception $e) {
            error_log("AIProviderService Ollama error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Construit le prompt de classification
     */
    private function buildClassificationPrompt(string $content, string $filename, array $context): string
    {
        $db = Database::getInstance();
        
        // Récupérer les options disponibles
        $types = $db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name LIMIT 50")->fetchAll(\PDO::FETCH_ASSOC);
        $tags = $db->query("SELECT id, name FROM tags ORDER BY name LIMIT 50")->fetchAll(\PDO::FETCH_ASSOC);
        
        $typesList = implode(', ', array_map(fn($t) => "{$t['label']} (ID:{$t['id']})", $types));
        $corrList = implode(', ', array_map(fn($c) => "{$c['name']} (ID:{$c['id']})", $correspondents));
        $tagsList = implode(', ', array_map(fn($t) => "{$t['name']} (ID:{$t['id']})", $tags));
        
        $contentPreview = mb_substr($content, 0, 3000);
        
        return <<<PROMPT
Analyse ce document et propose une classification.

Fichier: {$filename}
Contenu:
{$contentPreview}

Types disponibles: {$typesList}
Correspondants existants: {$corrList}
Tags existants: {$tagsList}

Réponds UNIQUEMENT en JSON valide avec ce format:
{
  "document_type_id": <int ou null>,
  "correspondent_id": <int ou null>,
  "correspondent_name": "<nom si nouveau>",
  "tag_ids": [<int>, ...],
  "new_tags": ["<nom>", ...],
  "title": "<titre suggéré>",
  "document_date": "<YYYY-MM-DD ou null>",
  "amount": <decimal ou null>,
  "summary": "<résumé 2-3 phrases>",
  "confidence": <0.0-1.0>
}
PROMPT;
    }
    
    /**
     * Construit le prompt d'extraction
     */
    private function buildExtractionPrompt(string $content, array $fields): string
    {
        $contentPreview = mb_substr($content, 0, 4000);
        $fieldsJson = json_encode($fields, JSON_PRETTY_PRINT);
        
        return <<<PROMPT
Extrais les informations suivantes du document.

Contenu:
{$contentPreview}

Champs à extraire:
{$fieldsJson}

Réponds UNIQUEMENT en JSON valide avec les valeurs extraites.
Si une valeur n'est pas trouvée, utilise null.
PROMPT;
    }
    
    /**
     * Parse la réponse de classification
     */
    private function parseClassificationResponse(string $text): ?array
    {
        return $this->parseJsonResponse($text);
    }
    
    /**
     * Parse une réponse JSON (avec nettoyage)
     */
    private function parseJsonResponse(string $text): ?array
    {
        // Nettoyer le texte (enlever markdown, etc.)
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        $text = trim($text);
        
        // Essayer de trouver le JSON dans le texte
        if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
            $text = $matches[0];
        }
        
        $data = json_decode($text, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("AIProviderService JSON parse error: " . json_last_error_msg());
            return null;
        }
        
        return $data;
    }
    
    /**
     * Obtient le service Claude (lazy loading)
     */
    private function getClaudeService(): ClaudeService
    {
        if ($this->claudeService === null) {
            $this->claudeService = new ClaudeService();
        }
        return $this->claudeService;
    }
    
    /**
     * Vérifie si Claude est configuré (même si pas testé)
     */
    private function isClaudeConfigured(): bool
    {
        $apiKey = Config::get('claude.api_key') 
            ?? Config::get('ai.claude_api_key')
            ?? $_ENV['ANTHROPIC_API_KEY'] 
            ?? null;
        
        if (!$apiKey) {
            $keyFile = dirname(__DIR__, 2) . '/claude_api_key.txt';
            if (file_exists($keyFile)) {
                $apiKey = trim(file_get_contents($keyFile));
            }
        }
        
        return !empty($apiKey) && strlen($apiKey) > 20;
    }
    
    /**
     * Récupère les modèles Ollama disponibles
     */
    private function getOllamaModels(): array
    {
        try {
            $ch = curl_init($this->ollamaUrl . '/api/tags');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            if (!isset($data['models'])) {
                return [];
            }
            
            return array_map(fn($m) => $m['name'], $data['models']);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Vérifie si Ollama a un modèle LLM (pour classification/chat)
     */
    private function ollamaHasLLM(array $models): bool
    {
        $llmModels = ['llama3', 'llama3.2', 'llama3.1', 'mistral', 'mixtral', 'gemma', 'phi3', 'qwen'];
        
        foreach ($models as $model) {
            foreach ($llmModels as $llm) {
                if (str_contains(strtolower($model), $llm)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Vérifie si Ollama a un modèle d'embedding
     */
    private function ollamaHasEmbedding(array $models): bool
    {
        $embeddingModels = ['nomic-embed', 'mxbai-embed', 'all-minilm', 'bge-'];
        
        foreach ($models as $model) {
            foreach ($embeddingModels as $emb) {
                if (str_contains(strtolower($model), $emb)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Recommande un modèle Ollama à installer
     */
    public function getRecommendedOllamaModels(): array
    {
        return [
            'llm' => [
                'name' => 'llama3.1:8b',
                'command' => 'ollama pull llama3.1:8b',
                'size' => '~4.7GB',
                'description' => 'Modèle LLM performant pour classification et chat (testé POC)',
            ],
            'embedding' => [
                'name' => 'nomic-embed-text',
                'command' => 'ollama pull nomic-embed-text',
                'size' => '~275MB',
                'description' => 'Modèle embedding pour recherche sémantique (768d)',
            ],
        ];
    }
}
