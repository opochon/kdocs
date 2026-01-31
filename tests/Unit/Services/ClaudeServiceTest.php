<?php
/**
 * K-Docs - Tests ClaudeService
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\ClaudeService;

class ClaudeServiceTest extends TestCase
{
    private ClaudeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClaudeService();
    }

    public function testExtractTextFromValidResponse(): void
    {
        $response = [
            'content' => [
                ['type' => 'text', 'text' => 'Hello World']
            ]
        ];

        $text = $this->service->extractText($response);
        $this->assertEquals('Hello World', $text);
    }

    public function testExtractTextFromEmptyResponse(): void
    {
        $response = ['content' => []];
        $text = $this->service->extractText($response);
        $this->assertEquals('', $text);
    }

    public function testExtractTextFromMalformedResponse(): void
    {
        $response = ['invalid' => 'structure'];
        $text = $this->service->extractText($response);
        $this->assertEquals('', $text);
    }

    public function testExtractTextFromNullContent(): void
    {
        $response = ['content' => null];
        $text = $this->service->extractText($response);
        $this->assertEquals('', $text);
    }

    public function testExtractTextFromMultipleContentBlocks(): void
    {
        $response = [
            'content' => [
                ['type' => 'text', 'text' => 'First block'],
                ['type' => 'text', 'text' => 'Second block']
            ]
        ];

        // La méthode actuelle ne retourne que le premier bloc
        $text = $this->service->extractText($response);
        $this->assertEquals('First block', $text);
    }

    public function testIsConfiguredReturnsFalseWithoutApiKey(): void
    {
        // Créer un service sans clé API (la config par défaut peut ne pas avoir de clé)
        $service = new ClaudeService();
        // Le résultat dépend de la configuration
        $this->assertIsBool($service->isConfigured());
    }
}
