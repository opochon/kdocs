<?php
/**
 * Tests unitaires AIProviderService
 */

namespace KDocs\Tests\Unit\Services;

use KDocs\Tests\TestCase;
use KDocs\Services\AIProviderService;

class AIProviderServiceTest extends TestCase
{
    private AIProviderService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        AIProviderService::resetCache();
        $this->service = new AIProviderService();
    }
    
    public function testGetStatusReturnsArray(): void
    {
        $status = $this->service->getStatus();
        
        $this->assertIsArray($status);
        $this->assertArrayHasKey('ai_available', $status);
        $this->assertArrayHasKey('active_provider', $status);
        $this->assertArrayHasKey('claude', $status);
        $this->assertArrayHasKey('ollama', $status);
    }
    
    public function testGetStatusClaudeStructure(): void
    {
        $status = $this->service->getStatus();
        
        $this->assertArrayHasKey('available', $status['claude']);
        $this->assertArrayHasKey('configured', $status['claude']);
        $this->assertArrayHasKey('model', $status['claude']);
    }
    
    public function testGetStatusOllamaStructure(): void
    {
        $status = $this->service->getStatus();
        
        $this->assertArrayHasKey('available', $status['ollama']);
        $this->assertArrayHasKey('url', $status['ollama']);
        $this->assertArrayHasKey('model', $status['ollama']);
    }
    
    public function testGetBestProviderReturnsString(): void
    {
        $provider = $this->service->getBestProvider();
        
        $this->assertIsString($provider);
        $this->assertContains($provider, ['claude', 'ollama', 'none']);
    }
    
    public function testIsAIAvailableReturnsBool(): void
    {
        $available = $this->service->isAIAvailable();
        
        $this->assertIsBool($available);
    }
    
    public function testCacheReset(): void
    {
        // First call
        $status1 = $this->service->getStatus();
        
        // Reset cache
        AIProviderService::resetCache();
        
        // Second call should work
        $status2 = $this->service->getStatus();
        
        $this->assertEquals(array_keys($status1), array_keys($status2));
    }
}
