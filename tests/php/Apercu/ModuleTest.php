<?php

declare(strict_types=1);

namespace Ged\Tests\Apercu;

use Ged\Apercu\Outils;
use Ged\Apercu\Permission;
use Ged\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Contrat des routes d'aperçu : la page se désigne, le contenu est servi avec son type, la permission
 * `document.voir` est déclarée, et une erreur se nomme.
 */
final class ModuleTest extends TestCase
{
    /** @return array{int, string, array<string, string>} */
    private function get(string $chemin): array
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $rep = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $chemin));
        $entetes = [];
        foreach ($rep->getHeaders() as $nom => $valeurs) {
            $entetes[strtolower($nom)] = implode(', ', $valeurs);
        }

        return [$rep->getStatusCode(), (string) $rep->getBody(), $entetes];
    }

    public function testPageApercuPdfSeDesigne(): void
    {
        [$code, $html] = $this->get('/documents/204/apercu');

        self::assertSame(200, $code, $html);
        self::assertStringContainsString('data-testid="visionneuse"', $html);
        self::assertStringContainsString('data-visionneuse', $html);
        self::assertStringContainsString('data-src="/documents/204/apercu/fichier"', $html);
        self::assertStringContainsString('data-categorie="pdf"', $html);
        self::assertStringContainsString('data-pages="3"', $html);
    }

    public function testContenuPdfEstServiInline(): void
    {
        [$code, $corps, $entetes] = $this->get('/documents/204/apercu/fichier');

        self::assertSame(200, $code);
        self::assertSame('application/pdf', $entetes['content-type'] ?? '');
        self::assertStringStartsWith('%PDF-', $corps);
    }

    public function testFichierOriginalEstTelecharge(): void
    {
        [$code, $corps, $entetes] = $this->get('/documents/204/fichier');

        self::assertSame(200, $code);
        self::assertStringContainsString('attachment', $entetes['content-disposition'] ?? '');
        self::assertStringStartsWith('%PDF-', $corps);
    }

    public function testMiniatureServieEnPng(): void
    {
        [$code, $corps, $entetes] = $this->get('/documents/204/miniature');

        self::assertSame(200, $code);
        self::assertSame('image/png', $entetes['content-type'] ?? '');
        self::assertStringStartsWith("\x89PNG", $corps);
    }

    public function testBureautiqueNommeLAbsenceDeLibreOffice(): void
    {
        [, $html] = $this->get('/documents/209/apercu');

        if (Outils::libreOffice() === null) {
            self::assertStringContainsString('aperçu indisponible : LibreOffice absent', $html);
        } else {
            self::assertStringContainsString('data-categorie="pdf"', $html);
        }
    }

    public function testDocumentInconnuEstNommeEtJamais500(): void
    {
        [$code, $html] = $this->get('/documents/999999/apercu');

        self::assertSame(404, $code);
        self::assertStringContainsString('data-testid="erreur-reference"', $html);
    }

    public function testLaPermissionDocumentVoirEstDeclaree(): void
    {
        $vue = new ServerRequestFactory()->createServerRequest('GET', '/documents/204/apercu');
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $vu = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->vu = $request;

                return new Response();
            }
        };

        (new Permission())->process($vue, $handler);

        self::assertNotNull($handler->vu);
        self::assertSame(Permission::VOIR, $handler->vu->getAttribute('permission'));
        self::assertSame('document.voir', Permission::VOIR);
    }
}
