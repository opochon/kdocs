<?php
/**
 * K-Docs - AIProviderService Unit Tests
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\AIProviderService;

class AIProviderServiceTest extends TestCase
{
    private ?AIProviderService $service = null;

    protected function setUp(): void
    {
        try {
            $this->service = new AIProviderService();
        } catch (\Exception $e) {
            $this->markTestSkipped('AIProviderService requires config: ' . $e->getMessage());
        }
    }

    public function testGetBestProviderReturnsString(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        $provider = $this->service->getBestProvider();

        $this->assertIsString($provider);
        $this->assertContains($provider, ['claude', 'ollama', 'none']);
    }

    public function testIsAIAvailableReturnsBool(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        $available = $this->service->isAIAvailable();

        $this->assertIsBool($available);
    }

    public function testIsOllamaAvailableReturnsBool(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        $available = $this->service->isOllamaAvailable();

        $this->assertIsBool($available);
    }

    public function testIsClaudeAvailableReturnsBool(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        $available = $this->service->isClaudeAvailable();

        $this->assertIsBool($available);
    }

    public function testGetStatusReturnsArray(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        $status = $this->service->getStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('active_provider', $status);
        $this->assertArrayHasKey('ai_available', $status);
        $this->assertArrayHasKey('claude', $status);
        $this->assertArrayHasKey('ollama', $status);
    }

    public function testGetRecommendedOllamaModels(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        $models = $this->service->getRecommendedOllamaModels();

        $this->assertIsArray($models);
        $this->assertArrayHasKey('llm', $models);
        $this->assertArrayHasKey('embedding', $models);
        $this->assertEquals('llama3.1:8b', $models['llm']['name']);
        $this->assertEquals('nomic-embed-text', $models['embedding']['name']);
    }

    public function testResetCacheWorks(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        // This should not throw
        AIProviderService::resetCache();

        // After reset, getBestProvider should still work
        $provider = $this->service->getBestProvider();
        $this->assertIsString($provider);
    }

    public function testCompleteReturnsNullOrArray(): void
    {
        if (!$this->service) {
            $this->markTestSkipped('Service not initialized');
            return;
        }

        // Skip if no AI available
        if (!$this->service->isAIAvailable()) {
            $this->markTestSkipped('No AI provider available');
            return;
        }

        $result = $this->service->complete("Test prompt", ['max_tokens' => 50]);

        // Can be null (if AI fails) or array (if successful)
        $this->assertTrue($result === null || is_array($result));
    }
}
