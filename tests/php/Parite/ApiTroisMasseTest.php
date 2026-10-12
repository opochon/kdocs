<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Reponse;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Famille « opérations en masse » du lot ged-parite-api-3.
 *
 * Vecteurs extraits en LECTURE SEULE de test_api_bulk_download.py (Paperless-ngx v2.20.5) : le
 * téléchargement groupé rend 200 pour des documents lisibles et 403 quand un document visé échappe aux
 * droits. Côté GEDv2, la route `POST /api/v1/documents/bulk_download` applique le prédicat de lecture du
 * Catalogue (droit par dossier) et refuse en JSON nommé.
 */
final class ApiTroisMasseTest extends TestCase
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

    public function testRejoueLesVecteursDeTelechargementDeMasse(): void
    {
        $donnees = Vecteurs::charger('api-3-masse');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');
        $visible = $this->deposer($compte['ecriture'], 'Lot visible');
        $cache = $this->deposer($compte['refus'], 'Lot confidentiel');

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            $documents = str_contains($id, 'insufficient') ? [$cache] : [$visible];
            $statut = $this->statut($app, 'POST', '/api/v1/documents/bulk_download', $jeton, ['documents' => $documents]);
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            if ($statut === $attendu) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('3-masse', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '3-masse',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de masse en écart :\n" . implode("\n", $echecs));

        // Non-accès : sans jeton, la route refuse et se nomme (jamais un 500 muet).
        self::assertSame(401, $this->appel($app, 'POST', '/api/v1/documents/bulk_download', null, ['documents' => [$visible]])[0]);
    }

    private function deposer(string $dossier, string $titre): int
    {
        return (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $titre),
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => 'Émetteur ' . $titre]);
    }

    /**
     * Statut seul : l'archive ZIP du téléchargement de masse n'est pas du JSON (contrat binaire).
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
