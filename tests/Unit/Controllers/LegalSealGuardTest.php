<?php

declare(strict_types=1);

/**
 * GAP-024 — document légal non modifiable : la garde WORM du contrôleur
 * documents renvoie 403 quand LegalArchiveService signale un document scellé,
 * et laisse passer (null) sinon.
 *
 * Hermétique : LegalArchiveService mocké via makeLegalArchiveService().
 */

namespace KDocs\Tests\Unit\Controllers;

use KDocs\Controllers\Api\DocumentsApiController;
use KDocs\Services\Compliance\LegalArchiveService;
use KDocs\Services\Compliance\LegalSealedException;
use KDocs\Tests\TestCase;
use ReflectionMethod;
use Slim\Psr7\Response;

class LegalSealGuardTest extends TestCase
{
    private function makeControllerWithMockArchive(LegalArchiveService $archive): DocumentsApiController
    {
        return new class($archive) extends DocumentsApiController {
            private LegalArchiveService $a;
            public function __construct(LegalArchiveService $a)
            {
                $this->a = $a;
            }
            protected function makeLegalArchiveService(): LegalArchiveService
            {
                return $this->a;
            }
        };
    }

    private function invokeGuard(object $ctrl, int $id): ?object
    {
        $m = new ReflectionMethod($ctrl, 'guardNotSealed');
        $m->setAccessible(true);

        return $m->invokeArgs($ctrl, [$id, new Response()]);
    }

    public function testGuardRenvoie403SiScelle(): void
    {
        $mock = $this->createMock(LegalArchiveService::class);
        $mock->method('assertWritable')->willThrowException(new LegalSealedException(42));

        $result = $this->invokeGuard($this->makeControllerWithMockArchive($mock), 42);

        $this->assertNotNull($result, 'la garde doit renvoyer une réponse');
        $this->assertSame(403, $result->getStatusCode());

        $body = (string) $result->getBody();
        $this->assertStringContainsString('scell', $body);
    }

    public function testGuardLaissePasserSiNonScelle(): void
    {
        $mock = $this->createMock(LegalArchiveService::class);
        $mock->expects($this->once())->method('assertWritable');

        $result = $this->invokeGuard($this->makeControllerWithMockArchive($mock), 42);

        $this->assertNull($result);
    }

    public function testGuardNeBloquePasSiMigrationAbsente(): void
    {
        // Colonne absente → SQLSTATE 42S22 : la garde ne doit pas bloquer l'écriture.
        $mock = $this->createMock(LegalArchiveService::class);
        $mock->method('assertWritable')->willThrowException(new \PDOException('Unknown column legal_sealed'));

        $result = $this->invokeGuard($this->makeControllerWithMockArchive($mock), 42);

        $this->assertNull($result);
    }
}
