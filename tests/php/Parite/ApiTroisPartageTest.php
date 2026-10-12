<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Famille « liens de partage » du lot ged-parite-api-3.
 *
 * Vecteurs extraits en LECTURE SEULE de test_api_documents.py (Paperless-ngx v2.20.5) : ShareLink
 * (create/list/retrieve, 404 sur document inconnu, 405 sur le mauvais verbe). Côté GEDv2, les liens
 * externes vivent dans `document_collaboration` et passent par `/api/v1/partages` et
 * `/api/v1/documents/{id}/partages` (`Socle\Collaboration`). Le jeton n'est rendu qu'à la création.
 *
 * Le vecteur de permission par objet (Paperless `assign_perm`) n'a pas d'équivalent GEDv2 : le droit est
 * par dossier. Il est mesuré comme écart ASSUMÉ (le document hors dossiers reste refusé 403).
 */
final class ApiTroisPartageTest extends TestCase
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

    public function testRejoueLesVecteursDeLiensDePartage(): void
    {
        $donnees = Vecteurs::charger('api-3-partage');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');
        $visible = $this->deposer($compte['ecriture'], 'Partageable');
        $cache = $this->deposer($compte['refus'], 'Confidentiel partage');

        // La permission par objet n'existe pas : après « assignation », GEDv2 refuse toujours 403.
        $divergences = ['partage:test_share_links_permissions_aware:007' => 403];

        $passes = 0;
        $assumes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            [$methode, $chemin, $corps] = $this->traduire($vecteur, $visible, $cache);
            $statut = $this->statut($app, $methode, $chemin, $jeton, $corps);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } elseif (isset($divergences[$id]) && $statut === $divergences[$id]) {
                ++$assumes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('3-partage', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '3-partage',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => $assumes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de partage en écart :\n" . implode("\n", $echecs));
        self::assertSame(1, $assumes);

        // Révocation (destroy) : le lien se révoque, la ligne reste (zéro suppression).
        [$creation, $partage] = $this->appel($app, 'POST', '/api/v1/partages', $jeton, ['document' => $visible]);
        self::assertSame(201, $creation);
        $pid = (int) $partage['id'];
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/partages/$pid", $jeton)[0]);
        self::assertSame(204, $this->statut($app, 'DELETE', "/api/v1/partages/$pid", $jeton));
        self::assertTrue((bool) ($this->appel($app, 'GET', "/api/v1/partages/$pid", $jeton)[1]['revoque'] ?? false));

        // Non-accès : sans jeton, la route refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'GET', "/api/v1/partages/$pid")[0]);
    }

    /**
     * @param array<string, mixed> $vecteur
     * @return array{0:string, 1:string, 2:array<string, mixed>}
     */
    private function traduire(array $vecteur, int $visible, int $cache): array
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $chemin = (string) ($entree['chemin'] ?? '');
        $methode = (string) ($entree['methode'] ?? 'GET');
        if ($chemin === '/api/share_links/') {
            return ['POST', '/api/v1/partages', ['document' => $visible]];
        }
        if (str_contains($chemin, '/share_links/')) {
            $cible = str_contains($chemin, '/99/') ? 99 : (str_contains((string) ($vecteur['id'] ?? ''), 'permissions') ? $cache : $visible);
            $mappe = $methode === 'POST' ? 'POST' : 'GET';

            return [$mappe, "/api/v1/documents/$cible/partages", []];
        }

        return [$methode, $chemin, []];
    }

    private function deposer(string $dossier, string $titre): int
    {
        return (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $titre),
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => 'Émetteur ' . $titre]);
    }

    /**
     * Statut seul : certaines réponses Paperless rejouées ne sont pas JSON (405 HTML, 204 vide).
     *
     * @param array<string, mixed>|null $corps
     */
    private function statut(App $app, string $methode, string $chemin, ?string $jeton, ?array $corps = null): int
    {
        $requete = (new ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($jeton !== null) {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($corps !== null) {
            $requete = $requete->withParsedBody($corps);
        }

        return $app->handle($requete)->getStatusCode();
    }
}
