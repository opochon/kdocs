<?php

declare(strict_types=1);

namespace KDocs\Tests\Unit\Services;

use KDocs\Services\InfomaniakAIService;
use KDocs\Tests\TestCase;

class InfomaniakAIServiceTest extends TestCase
{
    public function testIsConfiguredRequiresKeyAndSecret(): void
    {
        $service = new InfomaniakAIService();
        $this->assertFalse($service->isConfigured());
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
}
