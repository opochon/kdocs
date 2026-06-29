<?php

declare(strict_types=1);

namespace KDocs\Services;

/**
 * Client Infomaniak AI Tools — aligné sur clearmydocs providers_infomaniak.py
 * et htmleditor word-io/ai/providers/infomaniak.js.
 *
 * Auth : Bearer token (API key) + product_id (secret / identifiant produit AI Tools).
 */
class InfomaniakAIService
{
    public const MAX_TOKENS = 5000;

    private const RETRY_DELAYS_SECONDS = [0, 5, 15, 30];

    private const RETRYABLE_HTTP = [429, 503, 504];

    public function isEnabled(): bool
    {
        return filter_var(\env('INFOMANIAK_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function isConfigured(): bool
    {
        return $this->getApiKey() !== '' && $this->getProductId() !== '';
    }

    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function getApiKey(): string
    {
        foreach (['INFOMANIAK_AI_API_KEY', 'INFOMANIAK_API_TOKEN'] as $key) {
            $value = trim((string) (\env($key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** Product ID AI Tools (souvent nommé « secret » côté compte). */
    public function getProductId(): string
    {
        foreach (['INFOMANIAK_AI_API_SECRET', 'INFOMANIAK_AI_PRODUCT_ID', 'INFOMANIAK_PRODUCT_ID'] as $key) {
            $value = trim((string) (\env($key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function getModel(): string
    {
        $model = trim((string) \env('INFOMANIAK_AI_MODEL', 'swiss-ai/Apertus-70B-Instruct-2509'));

        return $model !== '' ? $model : 'swiss-ai/Apertus-70B-Instruct-2509';
    }

    public function getTimeoutSeconds(): int
    {
        return max(30, (int) \env('INFOMANIAK_AI_TIMEOUT', 120));
    }

    /**
     * Ping léger : liste des produits AI Tools du compte.
     *
     * @return array{ok: bool, products?: list<array<string, mixed>>, error?: string}
     */
    public function health(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $response = $this->httpGet('https://api.infomaniak.com/1/ai');
        if ($response === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        $products = $response['data'] ?? [];
        if (!is_array($products)) {
            $products = [];
        }

        return [
            'ok' => true,
            'products' => $products,
            'product_id_configured' => $this->getProductId(),
        ];
    }

    /**
     * Chat completion OpenAI-compatible.
     *
     * @param array<string, mixed> $options max_tokens, temperature, system
     *
     * @return array{provider: string, model: string, text: string, raw: array<string, mixed>}|null
     */
    public function complete(string $prompt, array $options = []): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $productId = $this->getProductId();
        $model = (string) ($options['model'] ?? $this->getModel());
        $maxTokens = (int) ($options['max_tokens'] ?? self::MAX_TOKENS);
        $maxTokens = min(max(1, $maxTokens), self::MAX_TOKENS);
        $temperature = (float) ($options['temperature'] ?? 0.1);
        $system = (string) ($options['system'] ?? 'Tu es un assistant documentaire. Réponds en français.');

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $endpoints = [
            'https://api.infomaniak.com/1/ai/' . rawurlencode($productId) . '/openai/chat/completions',
            'https://api.infomaniak.com/2/ai/' . rawurlencode($productId) . '/openai/v1/chat/completions',
        ];

        foreach ($endpoints as $endpoint) {
            $payload = $this->postWithRetry($endpoint, $body);
            if ($payload === null) {
                continue;
            }

            $text = $payload['choices'][0]['message']['content'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                return null;
            }

            return [
                'provider' => 'infomaniak',
                'model' => $model,
                'text' => trim($text),
                'raw' => $payload,
            ];
        }

        return null;
    }

    public static function stripJsonFences(string $text): string
    {
        $trimmed = trim($text);
        if (!str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $trimmed = preg_replace('/^```json\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/^```\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
    }

    /** @return array<string, mixed>|null */
    public static function parseJsonResponse(string $text): ?array
    {
        $text = self::stripJsonFences($text);
        if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
            $text = $matches[0];
        }

        $data = json_decode($text, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $body */
    private function postWithRetry(string $url, array $body): ?array
    {
        $lastResponse = null;

        foreach (self::RETRY_DELAYS_SECONDS as $retryIndex => $delay) {
            if ($retryIndex > 0 && $delay > 0) {
                sleep($delay);
            }

            $lastResponse = $this->httpPost($url, $body);
            if ($lastResponse === null) {
                continue;
            }

            $httpCode = (int) ($lastResponse['_http_code'] ?? 0);
            unset($lastResponse['_http_code']);

            if ($httpCode >= 200 && $httpCode < 400) {
                return $lastResponse;
            }

            if ($httpCode === 404) {
                return null;
            }

            if (!in_array($httpCode, self::RETRYABLE_HTTP, true)) {
                return null;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function httpGet(string $url): ?array
    {
        return $this->httpRequest('GET', $url);
    }

    /** @param array<string, mixed> $body */
    private function httpPost(string $url, array $body): ?array
    {
        return $this->httpRequest('POST', $url, $body);
    }

    /** @param array<string, mixed>|null $body */
    private function httpRequest(string $method, string $url, ?array $body = null): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->getApiKey(),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->getTimeoutSeconds(),
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $decoded['_http_code'] = $httpCode;

        return $decoded;
    }
}
