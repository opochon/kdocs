<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Filtres des listes de référentiels (famille « filtres-référentiels » du lot ged-parite-api-1).
 *
 * Correspondance fonctionnelle avec Paperless (noms de paramètres GEDv2, en français) :
 *   - DocumentTypeFilterSet.id / .name   -> GET /api/v1/types      ?id= / ?nom= / ?nom_contient=
 *   - TagFilterSet.name                  -> GET /api/v1/etiquettes ?nom= / ?nom_contient=
 *   - CorrespondentFilterSet.name        -> GET /api/v1/emetteurs  ?nom= / ?nom_contient=
 *
 * Paperless-ngx v2.20.5, src/documents/filters.py (CHAR_KWARGS / ID_KWARGS), test_api_objects.py.
 * Les filtres s'appliquent AU PÉRIMÈTRE DES DROITS : un référentiel d'un document caché ne remonte pas.
 */
final class ApiFiltresReferentielsTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testLesFiltresDesReferentielsSontCablesEtRespectentLesDroits(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        SchemaRecherche::installer($this->pdo);
        $donnees = $this->compteEtDossiers();
        $this->deposer($donnees['ecriture'], 'Piece A', 'a-' . $this->sel, 'Partage SA', ['Urgent', '2026']);
        $this->deposer($donnees['ecriture'], 'Piece B', 'b-' . $this->sel, 'Autre SA', []);
        $this->deposer($donnees['refus'], 'Piece C', 'c-' . $this->sel, 'Confidentiel SA', ['Cache']);
        $jeton = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        // Types (DocumentTypeFilterSet.id et .name) : la nomenclature par défaut porte « Facture » (id 1).
        $parId = $this->json($this->http($app, '/api/v1/types', $jeton, ['id' => '1']));
        self::assertCount(1, $parId);
        self::assertSame(1, $parId[0]['id']);
        $parNom = $this->json($this->http($app, '/api/v1/types', $jeton, ['nom' => 'facture']));
        self::assertCount(1, $parNom);
        self::assertSame('Facture', $parNom[0]['nom']);
        self::assertSame([], $this->json($this->http($app, '/api/v1/types', $jeton, ['nom' => 'inexistant'])));
        self::assertSame(400, $this->http($app, '/api/v1/types', $jeton, ['id' => 'abc'])->getStatusCode());

        // Étiquettes (TagFilterSet.name) : égal et sous-chaîne, casse ignorée.
        self::assertSame(['Urgent'], $this->json($this->http($app, '/api/v1/etiquettes', $jeton, ['nom' => 'urgent'])));
        self::assertSame(['Urgent'], $this->json($this->http($app, '/api/v1/etiquettes', $jeton, ['nom_contient' => 'URG'])));

        // Émetteurs (CorrespondentFilterSet.name).
        self::assertSame(['Partage SA'], $this->json($this->http($app, '/api/v1/emetteurs', $jeton, ['nom' => 'partage sa'])));
        self::assertSame(['Partage SA'], $this->json($this->http($app, '/api/v1/emetteurs', $jeton, ['nom_contient' => 'partage'])));

        // Un référentiel d'un document hors dossiers ne remonte ni en liste ni par filtre.
        $tousEmetteurs = $this->json($this->http($app, '/api/v1/emetteurs', $jeton));
        self::assertContains('Partage SA', $tousEmetteurs);
        self::assertNotContains('Confidentiel SA', $tousEmetteurs);
        self::assertSame([], $this->json($this->http($app, '/api/v1/emetteurs', $jeton, ['nom' => 'Confidentiel SA'])));
        self::assertSame([], $this->json($this->http($app, '/api/v1/etiquettes', $jeton, ['nom' => 'Cache'])));
    }

    /** @param list<string> $etiquettes */
    private function deposer(string $dossier, string $titre, string $contenu, string $emetteur, array $etiquettes): int
    {
        $meta = ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => $emetteur];
        if ($etiquettes !== []) {
            $meta['etiquettes'] = $etiquettes;
        }

        return (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $contenu), $meta);
    }

    /** @param array<string, string> $query */
    private function http(App $app, string $chemin, string $jeton, array $query = []): ResponseInterface
    {
        $url = $chemin . ($query === [] ? '' : '?' . http_build_query($query));

        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $jeton));
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $reponse): array
    {
        return (array) json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
