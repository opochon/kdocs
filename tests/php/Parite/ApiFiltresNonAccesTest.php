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
 * Garde de droits de la famille « filtres-documents » (lot ged-parite-api-1).
 *
 * Les filtres s'ajoutent au périmètre des droits, ils ne le remplacent jamais : un compte ne peut pas
 * utiliser un filtre (identifiant, étiquette, statut, texte) pour faire remonter un document qu'il n'a
 * pas le droit de lire. Prouvé par l'interface HTTP réelle, avec un jeton lié au compte.
 */
final class ApiFiltresNonAccesTest extends TestCase
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

    public function testUnFiltreNeFaitJamaisRemonterUnDocumentHorsDroits(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        SchemaRecherche::installer($this->pdo);
        $donnees = $this->compteEtDossiers();
        $visible = $this->deposer($donnees['ecriture'], 'Facture partagee', 'visible-' . $this->sel, 'Partage SA');
        $cache = $this->deposer($donnees['refus'], 'Facture confidentielle', 'cache-' . $this->sel, 'Confidentiel SA');
        $jeton = $this->jetonDocuments($donnees['compte'], $donnees['identifiant']);

        // Filtre de statut (« à traiter ») : le document hors dossiers reste invisible.
        $page = $this->json($this->http($app, '/api/v1/documents', $jeton, ['a_traiter' => '1']));
        $ids = $this->ids($page);
        self::assertContains($visible, $ids);
        self::assertNotContains($cache, $ids, 'un filtre ne contourne pas les droits');

        // Filtre par identifiant : demander explicitement l'identifiant caché ne le rend pas.
        $cible = $this->json($this->http($app, '/api/v1/documents', $jeton, ['ids' => (string) $cache]));
        self::assertSame(0, (int) $cible['total']);
        self::assertNotContains($cache, $this->ids($cible));

        // Filtre de texte : le titre du document caché ne le fait pas remonter.
        $texte = $this->json($this->http($app, '/api/v1/documents', $jeton, ['titre_contient' => 'confidentielle']));
        self::assertNotContains($cache, $this->ids($texte));

        // Filtre d'étiquette : l'étiquette du document caché non plus.
        $etiquette = $this->json($this->http($app, '/api/v1/documents', $jeton, ['etiquettes_any' => 'Confidentiel']));
        self::assertNotContains($cache, $this->ids($etiquette));
    }

    /** @param array<string, mixed> $page @return list<int> */
    private function ids(array $page): array
    {
        return array_map('intval', array_column(is_array($page['documents'] ?? null) ? $page['documents'] : [], 'id'));
    }

    private function deposer(string $dossier, string $titre, string $contenu, string $emetteur): int
    {
        $chemin = $this->piece($titre . '.pdf', $contenu);

        return (new DepotFichiers($this->config()))->deposer($chemin,
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => $emetteur]);
    }

    /** @param array<string, string> $query */
    private function http(App $app, string $chemin, string $jeton, array $query = []): ResponseInterface
    {
        $url = $chemin . ($query === [] ? '' : '?' . http_build_query($query));

        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $jeton));
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $reponse): array
    {
        return (array) json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
