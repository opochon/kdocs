<?php

declare(strict_types=1);

/**
 * Cascade IA — priorité Infomaniak > Claude > Ollama.
 * Épingle la décision de sélection (getBestProvider/getStatus) et l'exécution
 * (complete() route vers Infomaniak quand il est le best provider).
 * Aucun appel réseau : isAvailable() est config-only, complete() est mocké.
 */

namespace KDocs\Tests\Unit\Services;

use KDocs\Services\AIProviderService;
use KDocs\Services\InfomaniakAIService;
use KDocs\Tests\TestCase;

class AiCascadeInfomaniakTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        AIProviderService::resetCache();
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key . '=');
            unset($_ENV[$key]);
        }
        AIProviderService::resetCache();
    }

    private function enableInfomaniakEnv(): void
    {
        putenv('INFOMANIAK_AI_ENABLED=true');
        putenv('INFOMANIAK_AI_API_KEY=test-key-aaaaaaaaaaaaaaaaaaaaaaaaaa');
        putenv('INFOMANIAK_AI_API_SECRET=10642');
        $_ENV['INFOMANIAK_AI_ENABLED'] = 'true';
        $_ENV['INFOMANIAK_AI_API_KEY'] = 'test-key-aaaaaaaaaaaaaaaaaaaaaaaaaa';
        $_ENV['INFOMANIAK_AI_API_SECRET'] = '10642';
        AIProviderService::resetCache();
    }

    /**
     * Infomaniak configuré (env) -> best provider = infomaniak, quel que soit
     * l'état de Claude/Ollama. Épingle la priorité de la cascade.
     */
    public function testInfomaniakIsPreferredWhenConfigured(): void
    {
        $this->enableInfomaniakEnv();
        $service = new AIProviderService();

        $this->assertTrue($service->isInfomaniakAvailable());
        $this->assertSame('infomaniak', $service->getBestProvider());

        $status = $service->getStatus();
        $this->assertSame('infomaniak', $status['active_provider']);
        $this->assertTrue($status['infomaniak']['available']);
        $this->assertTrue($status['infomaniak']['enabled']);
        $this->assertTrue($status['infomaniak']['configured']);
        $this->assertTrue($status['ai_available']);
    }

    /**
     * Infomaniak désactivé -> best provider != infomaniak (retombe sur
     * Claude/Ollama/none selon l'env de test, neutralisé par phpunit.xml).
     */
    public function testInfomaniakNotSelectedWhenDisabled(): void
    {
        // phpunit.xml neutralise Infomaniak -> isAvailable() = false.
        $service = new AIProviderService();

        $this->assertFalse($service->isInfomaniakAvailable());
        $this->assertNotSame('infomaniak', $service->getBestProvider());

        $status = $service->getStatus();
        $this->assertFalse($status['infomaniak']['available']);
    }

    /**
     * complete() route vers Infomaniak quand c'est le best provider.
     * Mock du service Infomaniak (isAvailable=true, complete=fixe) -> aucun
     * appel réseau. Épingle la branche completeWithInfomaniak de la cascade.
     */
    public function testCompleteRoutesToInfomaniakWhenBest(): void
    {
        $infomaniakMock = $this->createMock(InfomaniakAIService::class);
        $infomaniakMock->method('isAvailable')->willReturn(true);
        $infomaniakMock->method('complete')
            ->willReturn(['text' => 'réponse cascade', 'model' => 'swiss-ai/test']);

        $service = new class($infomaniakMock) extends AIProviderService {
            private InfomaniakAIService $mock;
            public function __construct(InfomaniakAIService $mock)
            {
                parent::__construct();
                $this->mock = $mock;
            }
            protected function getInfomaniakService(): InfomaniakAIService
            {
                return $this->mock;
            }
        };

        $result = $service->complete('prompt de test');

        $this->assertNotNull($result);
        $this->assertSame('infomaniak', $result['provider']);
        $this->assertSame('réponse cascade', $result['text']);
    }

    /**
     * Infomaniak best provider mais complete() échoue (null) et Ollama
     * indisponible -> complete() retourne null (pas de boucle infinie, pas
     * d'exception). Épingle la gestion d'échec de la branche Infomaniak.
     */
    public function testCompleteReturnsNullWhenInfomaniakFailsAndNoOllama(): void
    {
        $infomaniakMock = $this->createMock(InfomaniakAIService::class);
        $infomaniakMock->method('isAvailable')->willReturn(true);
        $infomaniakMock->method('complete')->willReturn(null);

        $service = new class($infomaniakMock) extends AIProviderService {
            private InfomaniakAIService $mock;
            public function __construct(InfomaniakAIService $mock)
            {
                parent::__construct();
                $this->mock = $mock;
            }
            protected function getInfomaniakService(): InfomaniakAIService
            {
                return $this->mock;
            }
            public function isOllamaAvailable(): bool
            {
                return false;
            }
        };

        $this->assertNull($service->complete('prompt'));
    }
}
