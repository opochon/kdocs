<?php

declare(strict_types=1);

namespace KDocs\Tests\Unit\Services;

use KDocs\Services\InfomaniakAIService;
use KDocs\Tests\TestCase;

class InfomaniakAIServiceTest extends TestCase
{
    /** @var list<string> */
    private const ENV_KEYS = [
        'INFOMANIAK_AI_ENABLED',
        'INFOMANIAK_AI_API_KEY',
        'INFOMANIAK_AI_API_SECRET',
        'INFOMANIAK_API_TOKEN',
        'INFOMANIAK_PRODUCT_ID',
        'ANTHROPIC_API_KEY',
    ];

    /**
     * Neutralise l'env Infomaniak/Claude : tests hermétiques, indépendants
     * du .env local (qui peut contenir de vraies credentials en dev).
     */
    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key]);
            putenv($key . '=');
        }
    }

    public function testIsConfiguredRequiresKeyAndSecret(): void
    {
        $service = new InfomaniakAIService();
        $this->assertFalse($service->isConfigured());
        $this->assertFalse($service->isEnabled());
        $this->assertFalse($service->isAvailable());
    }

    public function testStripJsonFences(): void
    {
        $raw = "```json\n{\"ok\":true}\n```";
        $this->assertSame('{"ok":true}', InfomaniakAIService::stripJsonFences($raw));
    }

    public function testParseJsonResponse(): void
    {
        $parsed = InfomaniakAIService::parseJsonResponse('Voici le résultat: {"confidence":0.9,"title":"Facture"}');
        $this->assertIsArray($parsed);
        $this->assertSame(0.9, $parsed['confidence']);
        $this->assertSame('Facture', $parsed['title']);
    }

    public function testParseJsonResponseWithFences(): void
    {
        $parsed = InfomaniakAIService::parseJsonResponse("```json\n{\"title\":\"X\",\"confidence\":0.5}\n```");
        $this->assertSame('X', $parsed['title']);
    }

    public function testParseJsonResponseReturnsNullOnGarbage(): void
    {
        $this->assertNull(InfomaniakAIService::parseJsonResponse('not json at all'));
    }

    /**
     * v2 (premier endpoint) répond 200 → succès direct, v1 non sollicité.
     * Valide l'ordre v2-first (catalogue OpenAI-compatible complet).
     */
    public function testCompleteSucceedsOnV2Endpoint(): void
    {
        $service = new class extends InfomaniakAIService {
            public array $calls = [];

            protected function httpRequest(string $method, string $url, ?array $body = null): ?array
            {
                $this->calls[] = $url;
                if (str_contains($url, '/2/ai/')) {
                    return [
                        '_http_code' => 200,
                        'choices' => [['message' => ['content' => 'réponse v2']]],
                    ];
                }
                return null;
            }

            public function isAvailable(): bool { return true; }
        };

        $res = $service->complete('prompt');
        $this->assertNotNull($res);
        $this->assertSame('infomaniak', $res['provider']);
        $this->assertSame('réponse v2', trim($res['text']));
        $this->assertCount(1, $service->calls);
        $this->assertStringContainsString('/2/ai/', $service->calls[0]);
    }

    /**
     * v2 injoignable (null) → retry 4x sur v2 puis fallback v1 (200).
     * RETRY_DELAYS_SECONDS a 4 entrées : un endpoint nul = 4 appels avant abandon.
     */
    public function testCompleteFallsBackToV1WhenV2Unreachable(): void
    {
        $service = new class extends InfomaniakAIService {
            public array $calls = [];

            protected function httpRequest(string $method, string $url, ?array $body = null): ?array
            {
                $this->calls[] = $url;
                if (str_contains($url, '/1/ai/')) {
                    return [
                        '_http_code' => 200,
                        'choices' => [['message' => ['content' => 'réponse v1']]],
                    ];
                }
                return null; // v2 injoignable
            }

            protected function retrySleep(int $seconds): void { /* no-op */ }

            public function isAvailable(): bool { return true; }
        };

        $res = $service->complete('prompt');
        $this->assertNotNull($res);
        $this->assertSame('réponse v1', trim($res['text']));
        // 4 appels v2 (null, retry) + 1 appel v1 (200) = 5
        $this->assertCount(5, $service->calls);
    }

    /**
     * 503 (retryable) puis 200 au 2e essai sur v2. retrySleep no-op en test.
     */
    public function testCompleteRetriesOn503ThenSucceeds(): void
    {
        $service = new class extends InfomaniakAIService {
            private int $attempt = 0;

            protected function httpRequest(string $method, string $url, ?array $body = null): ?array
            {
                if (!str_contains($url, '/2/ai/')) {
                    return null;
                }
                $this->attempt++;
                if ($this->attempt === 1) {
                    return ['_http_code' => 503, 'error' => 'busy'];
                }
                return [
                    '_http_code' => 200,
                    'choices' => [['message' => ['content' => 'OK après retry']]],
                ];
            }

            protected function retrySleep(int $seconds): void { /* no-op */ }

            public function isAvailable(): bool { return true; }
        };

        $res = $service->complete('prompt');
        $this->assertNotNull($res);
        $this->assertSame('OK après retry', trim($res['text']));
    }

    /**
     * v2 injoignable puis v1 422 (non-retryable, modèle invalide) → abandon propre.
     * Reproduit le cas réel : v1 refuse Apertus, v2 injoignable → null.
     */
    public function testCompleteReturnsNullWhenAllEndpointsFail(): void
    {
        $service = new class extends InfomaniakAIService {
            protected function httpRequest(string $method, string $url, ?array $body = null): ?array
            {
                if (str_contains($url, '/1/ai/')) {
                    return ['_http_code' => 422, 'error' => 'validation_failed'];
                }
                return null; // v2 injoignable
            }

            protected function retrySleep(int $seconds): void { /* no-op */ }

            public function isAvailable(): bool { return true; }
        };

        $this->assertNull($service->complete('prompt'));
    }

    /**
     * 404 (non-retryable) → null immédiat, pas de retry sur l'endpoint.
     */
    public function testComplete404ReturnsNullImmediately(): void
    {
        $service = new class extends InfomaniakAIService {
            public int $calls = 0;

            protected function httpRequest(string $method, string $url, ?array $body = null): ?array
            {
                $this->calls++;
                return ['_http_code' => 404, 'error' => 'not found'];
            }

            protected function retrySleep(int $seconds): void { /* no-op */ }

            public function isAvailable(): bool { return true; }
        };

        $this->assertNull($service->complete('prompt'));
        // 404 non-retryable : 1 appel par endpoint, 2 endpoints = 2 appels.
        $this->assertSame(2, $service->calls);
    }

    public function testCompleteReturnsNullWhenNotAvailable(): void
    {
        // setUp() a neutralisé l'env → isAvailable() = false.
        $service = new InfomaniakAIService();
        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->complete('prompt'));
    }
}
