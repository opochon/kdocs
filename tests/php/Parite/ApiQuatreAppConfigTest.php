<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « configuration, logo, favicon » du lot ged-parite-api-4.
 *
 * Vecteurs extraits en LECTURE SEULE de `test_api_app_config.py` (Paperless-ngx v2.20.5) : lecture de la
 * configuration, mise à jour, création interdite (405), logo et favicon. Depuis le lot ged-parite-final-1,
 * la configuration GEDv2 est INSCRIPTIBLE (surcharge du fonds, `.ged/configuration.json`) : `PATCH` rend
 * 200 comme Paperless (l'écart assumé du lot api-4 est fermé). Le logo, lui, reste toujours servi par
 * GEDv2 là où Paperless rend 404 tant qu'aucun logo n'a été téléversé : écart assumé restant.
 */
final class ApiQuatreAppConfigTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api4-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDeConfigurationEtDImages(): void
    {
        $donnees = Vecteurs::charger('api-4-app-config');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteApi4();
        $jeton = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');

        // Un seul écart ASSUMÉ restant : le logo de GEDv2 est TOUJOURS servi (Paperless rend 404 tant
        // qu'aucun logo n'a été téléversé). La mise à jour de la configuration, elle, est désormais portée.
        $divergences = [
            'app-config:test_api_replace_app_logo:004' => 200,
        ];

        $passes = 0;
        $assumes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            [$methode, $chemin] = $this->traduire($vecteur);
            $statut = $this->statut($app, $methode, $chemin, $jeton, $methode === 'GET' ? null : ['color_conversion_strategy' => 'RGB']);
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

        Vecteurs::ecrireApi('4-app-config', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '4-app-config',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => $assumes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs d'app-config en écart :\n" . implode("\n", $echecs));
        self::assertSame(1, $assumes, 'seul le logo toujours servi reste un écart assumé');

        // Le favicon est servi, sans jeton.
        $requete = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/favicon');
        $reponse = $app->handle($requete);
        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('image/svg+xml', $reponse->getHeaderLine('Content-Type'));

        // La configuration est INSCRIPTIBLE (lot ged-parite-final-1) : le titre change, se relit, puis se
        // réinitialise aux valeurs du `.env` (DELETE = retrait de la surcharge, zéro suppression).
        [$statut, $corps] = $this->appel($app, 'PATCH', '/api/v1/configuration', $jeton, ['app_title' => 'GED de test finale']);
        self::assertSame(200, $statut);
        self::assertSame('GED de test finale', $corps['app_title'] ?? null);
        self::assertSame('fonds', $corps['source'] ?? null);
        [$relu] = [$this->appel($app, 'GET', '/api/v1/configuration', $jeton)[1]];
        self::assertSame('GED de test finale', $relu['app_title'] ?? null);
        [$reinit, $defaut] = $this->appel($app, 'DELETE', '/api/v1/configuration', $jeton);
        self::assertSame(200, $reinit);
        self::assertSame('env', $defaut['source'] ?? null);

        // Non-accès : sans jeton, la configuration refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'GET', '/api/v1/configuration')[0]);
    }

    /** @param array<string, mixed> $vecteur @return array{0:string, 1:string} */
    private function traduire(array $vecteur): array
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $methode = (string) ($entree['methode'] ?? 'GET');
        $chemin = (string) ($entree['chemin'] ?? '');
        if ($chemin === '/api/config/' || str_starts_with($chemin, '/api/config/')) {
            return [$methode, '/api/v1/configuration'];
        }
        if (str_starts_with($chemin, '/logo')) {
            return ['GET', '/logo'];
        }

        return [$methode, $chemin];
    }

    /**
     * Statut seul : `GET /logo` rend un SVG, pas du JSON.
     *
     * @param array<string, mixed>|null $corps
     */
    private function statut(\Slim\App $app, string $methode, string $chemin, ?string $jeton, ?array $corps = null): int
    {
        $requete = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($methode, $chemin);
        if ($jeton !== null) {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($corps !== null) {
            $requete = $requete->withParsedBody($corps);
        }

        return $app->handle($requete)->getStatusCode();
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteApi4(): array
    {
        $identifiant = 'api4-config-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), 'administrateur']);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}
