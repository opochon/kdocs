<?php

declare(strict_types=1);

namespace Tests\Feature;

use KDocs\Controllers\AuditLogsController;
use KDocs\Services\Compliance\AuditTrailExportService;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * GAP-022 — export piste de révision via GET /admin/audit/export.
 *
 * Hermétique : AuditTrailExportService mocké via makeExportService()
 * (sous-classe anonyme du contrôleur, pattern LegalSealGuard).
 * Utilise les objets PSR-7 Slim natifs pour respecter les signatures du contrôleur.
 */
class AuditExportTest extends ApiTestCase
{
    private function buildRequest(array $queryParams = []): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', 'http://localhost/admin/audit/export');

        return $queryParams ? $request->withQueryParams($queryParams) : $request;
    }

    /** Résultat de service minimal utilisé par défaut dans les tests. */
    private function makeExportPayload(array $overrides = []): array
    {
        return array_merge([
            'generated_at' => '2026-07-03T12:00:00+02:00',
            'filters'      => [],
            'count'        => 1,
            'timeline'     => [
                [
                    'at'          => '2026-01-01 08:00:00',
                    'user_id'     => 1,
                    'action'      => 'CREATE',
                    'object_type' => 'document',
                    'object_id'   => 42,
                    'object_name' => 'Facture.pdf',
                    'changes'     => null,
                    'ip_address'  => '127.0.0.1',
                ],
            ],
        ], $overrides);
    }

    /**
     * Retourne un contrôleur dont makeExportService() renvoie un mock
     * du service configuré pour retourner $payload lors de l'appel à export().
     */
    private function makeControllerWithMockedService(array $payload): AuditLogsController
    {
        $mockService = $this->createMock(AuditTrailExportService::class);
        $mockService->method('export')->willReturn($payload);

        return new class($mockService) extends AuditLogsController {
            private AuditTrailExportService $svc;

            public function __construct(AuditTrailExportService $svc)
            {
                $this->svc = $svc;
            }

            protected function makeExportService(): AuditTrailExportService
            {
                return $this->svc;
            }
        };
    }

    public function testExportRenvoieStatus200(): void
    {
        $ctrl   = $this->makeControllerWithMockedService($this->makeExportPayload());
        $result = $ctrl->export($this->buildRequest(), new Response());

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testExportRenvoieJsonValide(): void
    {
        $ctrl   = $this->makeControllerWithMockedService($this->makeExportPayload());
        $result = $ctrl->export($this->buildRequest(), new Response());

        $body = (string) $result->getBody();
        $data = json_decode($body, true);

        $this->assertNotNull($data, "La réponse n'est pas un JSON valide : {$body}");
        $this->assertArrayHasKey('timeline', $data);
        $this->assertArrayHasKey('count', $data);
        $this->assertArrayHasKey('generated_at', $data);
    }

    public function testExportHeaderContentTypeEstJson(): void
    {
        $ctrl   = $this->makeControllerWithMockedService($this->makeExportPayload());
        $result = $ctrl->export($this->buildRequest(), new Response());

        $this->assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));
    }

    public function testExportHeaderContentDispositionAvecNomFichier(): void
    {
        $ctrl        = $this->makeControllerWithMockedService($this->makeExportPayload());
        $result      = $ctrl->export($this->buildRequest(), new Response());
        $disposition = $result->getHeaderLine('Content-Disposition');

        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('audit-export-', $disposition);
        $this->assertStringContainsString('.json', $disposition);
    }

    public function testExportTimelineContientLesEntrees(): void
    {
        $ctrl   = $this->makeControllerWithMockedService($this->makeExportPayload());
        $result = $ctrl->export($this->buildRequest(), new Response());
        $data   = json_decode((string) $result->getBody(), true);

        $this->assertCount(1, $data['timeline']);
        $this->assertSame('CREATE', $data['timeline'][0]['action']);
    }

    public function testExportAvecTimelineVideRenvoieCountZero(): void
    {
        $payload = $this->makeExportPayload(['count' => 0, 'timeline' => []]);
        $ctrl    = $this->makeControllerWithMockedService($payload);
        $result  = $ctrl->export($this->buildRequest(), new Response());
        $data    = json_decode((string) $result->getBody(), true);

        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['timeline']);
    }
}
