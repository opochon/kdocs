<?php
/**
 * K-Docs - Embedding Service
 * Generates text embeddings using OpenAI or local models
 */

namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Core\Database;

class EmbeddingService
{
    private array $config;
    private string $provider;
    private string $model;
    private int $dimensions;
    private ?string $apiKey;

    public function __construct()
    {
        $this->config = Config::get('embeddings', []);
        $this->provider = $this->config['provider'] ?? 'openai';
        $this->model = $this->config['model'] ?? 'text-embedding-3-small';
        $this->dimensions = $this->config['dimensions'] ?? 1536;
        $this->apiKey = $this->config['api_key'] ?? null;
    }

    /**
     * Check if embedding service is available
     */
    public function isAvailable(): bool
    {
        if (!($this->config['enabled'] ?? false)) {
            return false;
        }

        if ($this->provider === 'openai') {
            return !empty($this->apiKey);
        }

        if ($this->provider === 'local' || $this->provider === 'ollama') {
            return $this->isOllamaAvailable();
        }

        return false;
    }

    /**
     * Generate embedding for text
     * @return array|null Vector array or null on failure
     */
    public function embed(string $text): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Truncate text if too long (OpenAI limit is ~8192 tokens)
        $text = $this->truncateText($text, 8000);

        if (empty(trim($text))) {
            return null;
        }

        $startTime = microtime(true);

        try {
            $embedding = match($this->provider) {
                'openai' => $this->embedWithOpenAI($text),
                'local', 'ollama' => $this->embedWithLocal($text),
                default => null
            };

            $processingTime = (int)((microtime(true) - $startTime) * 1000);

            // Log the embedding generation
            $this->logEmbedding(null, 'create', $this->countTokens($text), $processingTime);

            return $embedding;
        } catch (\Exception $e) {
            error_log("EmbeddingService error: " . $e->getMessage());
            $this->logEmbedding(null, 'error', null, null, $e->getMessage());
            return null;
        }
    }

    /**
     * Generate embedding for a document
     */
    public function embedDocument(int $documentId): ?array
    {
        $db = Database::getInstance();

        // Get document content
        $stmt = $db->prepare("
            SELECT id, title, original_filename, ocr_text, content, content_hash
            FROM documents
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            return null;
        }

        // Build text to embed
        $text = $this->buildDocumentText($doc);
        if (empty(trim($text))) {
            $this->updateDocumentStatus($documentId, 'skipped', 'No text content');
            return null;
        }

        // Calculate content hash
        $newHash = hash('sha256', $text);

        // Skip if content hasn't changed
        if ($doc['content_hash'] === $newHash) {
            return null;
        }

        // Mark as processing
        $this->updateDocumentStatus($documentId, 'processing');

        $startTime = microtime(true);

        try {
            $embedding = $this->embed($text);

            if ($embedding) {
                $processingTime = (int)((microtime(true) - $startTime) * 1000);

                // Update document status
                $stmt = $db->prepare("
                    UPDATE documents
                    SET embedding_status = 'completed',
                        vector_updated_at = NOW(),
                        content_hash = ?,
                        embedding_error = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$newHash, $documentId]);

                // Log success
                $this->logEmbedding($documentId, 'create', $this->countTokens($text), $processingTime);

                return $embedding;
            }

            $this->updateDocumentStatus($documentId, 'failed', 'Embedding generation failed');
            return null;

        } catch (\Exception $e) {
            $this->updateDocumentStatus($documentId, 'failed', $e->getMessage());
            $this->logEmbedding($documentId, 'error', null, null, $e->getMessage());
            return null;
        }
    }

    /**
     * Generate embeddings for multiple texts in batch
     */
    public function embedBatch(array $texts): array
    {
        if (!$this->isAvailable() || empty($texts)) {
            return [];
        }

        // OpenAI supports batch embeddings
        if ($this->provider === 'openai') {
            return $this->embedBatchWithOpenAI($texts);
        }

        // Fallback: process one by one
        $results = [];
        foreach ($texts as $key => $text) {
            $results[$key] = $this->embed($text);
        }
        return $results;
    }

    /**
     * Get documents pending embedding
     */
    public function getPendingDocuments(int $limit = 100): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            SELECT id, title, original_filename
            FROM documents
            WHERE deleted_at IS NULL
            AND (embedding_status IS NULL OR embedding_status IN ('pending', 'failed'))
            AND (ocr_text IS NOT NULL OR content IS NOT NULL)
            ORDER BY
                CASE WHEN embedding_status = 'failed' THEN 1 ELSE 0 END,
                created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get embedding statistics
     */
    public function getStatistics(): array
    {
        $db = Database::getInstance();

        // Count by status
        $stmt = $db->query("
            SELECT
                embedding_status,
                COUNT(*) as count
            FROM documents
            WHERE deleted_at IS NULL
            GROUP BY embedding_status
        ");
        $statusCounts = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $statusCounts[$row['embedding_status'] ?? 'null'] = (int)$row['count'];
        }

        // Recent logs
        $stmt = $db->query("
            SELECT action, COUNT(*) as count, SUM(tokens_used) as total_tokens
            FROM embedding_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY action
        ");
        $recentActivity = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'status_counts' => $statusCounts,
            'total_documents' => array_sum($statusCounts),
            'completed' => $statusCounts['completed'] ?? 0,
            'pending' => ($statusCounts['pending'] ?? 0) + ($statusCounts['null'] ?? 0),
            'failed' => $statusCounts['failed'] ?? 0,
            'recent_activity' => $recentActivity,
        ];
    }

    /**
     * Embed text using OpenAI API
     */
    private function embedWithOpenAI(string $text): ?array
    {
        $url = 'https://api.openai.com/v1/embeddings';

        $payload = [
            'model' => $this->model,
            'input' => $text,
        ];

        // Add dimensions for newer models that support it
        if (str_contains($this->model, 'text-embedding-3')) {
            $payload['dimensions'] = $this->dimensions;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            throw new \Exception('OpenAI API error: ' . ($error['error']['message'] ?? $response));
        }

        $data = json_decode($response, true);

        if (!isset($data['data'][0]['embedding'])) {
            throw new \Exception('Invalid OpenAI response format');
        }

        return $data['data'][0]['embedding'];
    }

    /**
     * Embed multiple texts using OpenAI batch API
     */
    private function embedBatchWithOpenAI(array $texts): array
    {
        $url = 'https://api.openai.com/v1/embeddings';

        // Truncate all texts
        $inputs = array_map(fn($t) => $this->truncateText($t, 8000), $texts);

        $payload = [
            'model' => $this->model,
            'input' => array_values($inputs),
        ];

        if (str_contains($this->model, 'text-embedding-3')) {
            $payload['dimensions'] = $this->dimensions;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            throw new \Exception('OpenAI API error: ' . ($error['error']['message'] ?? $response));
        }

        $data = json_decode($response, true);

        if (!isset($data['data'])) {
            throw new \Exception('Invalid OpenAI response format');
        }

        // Map back to original keys
        $results = [];
        $keys = array_keys($texts);
        foreach ($data['data'] as $item) {
            $index = $item['index'];
            if (isset($keys[$index])) {
                $results[$keys[$index]] = $item['embedding'];
            }
        }

        return $results;
    }

    /**
     * Embed text using Ollama (local embedding)
     */
    private function embedWithLocal(string $text): ?array
    {
        $ollamaUrl = $this->config['ollama_url'] ?? Config::get('api.ollama_url', 'http://localhost:11434');
        $model = $this->config['ollama_model'] ?? 'nomic-embed-text';

        $url = rtrim($ollamaUrl, '/') . '/api/embeddings';

        $payload = [
            'model' => $model,
            'prompt' => $text,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('Ollama connection error: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new \Exception('Ollama API error: HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);

        if (!isset($data['embedding'])) {
            throw new \Exception('Invalid Ollama response format');
        }

        return $data['embedding'];
    }

    /**
     * Check if Ollama is available
     */
    public function isOllamaAvailable(): bool
    {
        $ollamaUrl = $this->config['ollama_url'] ?? Config::get('api.ollama_url', 'http://localhost:11434');

        $ch = curl_init(rtrim($ollamaUrl, '/') . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Build text content from document for embedding
     */
    private function buildDocumentText(array $doc): string
    {
        $parts = [];

        // Add title
        if (!empty($doc['title'])) {
            $parts[] = "Titre: " . $doc['title'];
        }

        // Add filename
        if (!empty($doc['original_filename'])) {
            $parts[] = "Fichier: " . $doc['original_filename'];
        }

        // Add OCR text or content
        $content = $doc['ocr_text'] ?? $doc['content'] ?? '';
        if (!empty($content)) {
            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Truncate text to approximate token limit
     */
    private function truncateText(string $text, int $maxTokens): string
    {
        // Rough estimation: 1 token ≈ 4 characters for English, 2-3 for French
        $maxChars = $maxTokens * 3;

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . '...';
    }

    /**
     * Estimate token count
     */
    private function countTokens(string $text): int
    {
        // Rough estimation
        return (int)(mb_strlen($text) / 3);
    }

    /**
     * Update document embedding status
     */
    private function updateDocumentStatus(int $documentId, string $status, ?string $error = null): void
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("
            UPDATE documents
            SET embedding_status = ?, embedding_error = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $error, $documentId]);
    }

    /**
     * Log embedding operation
     */
    private function logEmbedding(
        ?int $documentId,
        string $action,
        ?int $tokensUsed = null,
        ?int $processingTimeMs = null,
        ?string $error = null
    ): void {
        try {
            $db = Database::getInstance();

            $stmt = $db->prepare("
                INSERT INTO embedding_logs
                (document_id, action, tokens_used, processing_time_ms, error_message)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$documentId, $action, $tokensUsed, $processingTimeMs, $error]);
        } catch (\Exception $e) {
            // Silent fail for logging
            error_log("Failed to log embedding: " . $e->getMessage());
        }
    }

    /**
     * Get model info
     */
    public function getModelInfo(): array
    {
        $model = $this->model;
        $dimensions = $this->dimensions;

        // Adjust for Ollama
        if ($this->provider === 'local' || $this->provider === 'ollama') {
            $model = $this->config['ollama_model'] ?? 'nomic-embed-text';
            // Ollama model dimensions vary
            $dimensions = match($model) {
                'nomic-embed-text' => 768,
                'mxbai-embed-large' => 1024,
                'all-minilm' => 384,
                default => $this->dimensions,
            };
        }

        return [
            'provider' => $this->provider,
            'model' => $model,
            'dimensions' => $dimensions,
            'available' => $this->isAvailable(),
            'ollama_url' => ($this->provider === 'local' || $this->provider === 'ollama')
                ? ($this->config['ollama_url'] ?? 'http://localhost:11434')
                : null,
        ];
    }
}
