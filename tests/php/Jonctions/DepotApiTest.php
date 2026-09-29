<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Ingestion\Travaux;
use Ged\Jonctions\Jetons;
use Ged\Jonctions\Schema;
use Ged\Jonctions\SourceApi;
use Ged\Kernel;
use Ged\Securite\DroitsRoutes;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Oracle V2-08a — API d'entrée sous jeton, porte 1 de la fiche Jonctions.
 *
 * 3 fichiers poussés deux fois → 3 travaux, 0 doublon ; jeton absent, faux ou révoqué → 401 nommé ;
 * fichier trop gros ou type refusé → 413/415 nommés ; le jeton n'est jamais en clair en base ;
 * la source écrit dans la zone de travail et passe par Travaux::recevoir.
 */
final class DepotApiTest extends TestCase
{
    private \PDO $pdo;
    private string $tmp;
    private string $sel;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        // Chaque test isole SES tables et des contenus uniques : gedv2_test est partagée par tous les agents.
        $this->pdo->exec('DROP TABLE IF EXISTS jonctions_jetons');
        Schema::installer($this->pdo);
        SchemaIngestion::installer($this->pdo);
        $this->tmp = sys_get_temp_dir() . '/gedv2-jonctions-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
        $this->sel = bin2hex(random_bytes(8));
        putenv('INGESTION_TRAVAIL=' . $this->tmp . '/ingestion');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM jonctions_jetons');
        $this->pdo->prepare('DELETE FROM ingestion_travaux WHERE source = ? AND nom_origine LIKE ?')
            ->execute(['api', 'test-' . $this->sel . '%']);
        $this->supprimerDossier($this->tmp);
        putenv('INGESTION_TRAVAIL');
    }

    public function testTroisFichiersPoussesDeuxFoisDonnentTroisTravauxSansDoublon(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $jeton = (new Jetons($this->pdo))->creer('cmm-' . $this->sel);
        $ids = [];
        $corps = [];

        // Deux passes : la seconde doit retrouver exactement les mêmes travaux, sans en créer d'autres.
        for ($passe = 0; $passe < 2; ++$passe) {
            foreach (['a', 'b', 'c'] as $lettre) {
                [$code, $reponse] = $this->pousser($app, $jeton, $lettre, "contenu-$lettre-$this->sel");
                self::assertSame(202, $code, json_encode($reponse));
                $ids[$passe][$lettre] = $reponse['travail'];
                $corps[$passe][$lettre] = $reponse;
            }
        }

        self::assertSame($ids[0], $ids[1], 'même empreinte = même travail (idempotent)');
        self::assertSame($corps[0], $corps[1], 'même empreinte = même réponse');

        $empreintes = array_map(
            static fn (string $c): string => hash('sha256', $c),
            ["contenu-a-$this->sel", "contenu-b-$this->sel", "contenu-c-$this->sel"],
        );
        $marques = implode(',', array_fill(0, count($empreintes), '?'));
        $requete = $this->pdo->prepare("SELECT COUNT(*) FROM ingestion_travaux WHERE parent_id = 0 AND empreinte IN ($marques)");
        $requete->execute($empreintes);

        self::assertSame(3, (int) $requete->fetchColumn(), '3 fichiers poussés deux fois = 3 travaux, 0 doublon');
        self::assertCount(3, glob($this->tmp . '/ingestion/api/doublons/*') ?: [], 'les doublons sont mis de côté, jamais supprimés');
    }

    public function testJetonAbsentFauxOuRevoqueEstRefuseEtNomme(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $jetons = new Jetons($this->pdo);

        [$code, $corps] = $this->pousser($app, '', 'a', "absent-$this->sel");
        self::assertSame(401, $code);
        self::assertStringContainsString('jeton absent', $corps['erreur']['cause']);
        self::assertSame('POST /api/v1/depots', $corps['erreur']['etape']);

        [$code, $corps] = $this->pousser($app, str_repeat('f', 64), 'a', "faux-$this->sel");
        self::assertSame(401, $code);
        self::assertStringContainsString('jeton inconnu', $corps['erreur']['cause']);

        $jeton = $jetons->creer('revoque-' . $this->sel);
        $ligne = $jetons->resoudre($jeton);
        self::assertNotNull($ligne);
        self::assertTrue($jetons->revoquer($ligne['id']));
        [$code, $corps] = $this->pousser($app, $jeton, 'a', "revoque-$this->sel");
        self::assertSame(401, $code);
        self::assertStringContainsString('révoqué', $corps['erreur']['cause']);
    }

    public function testFichierTropGrosEstRefuse413EtTypeRefuse415(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $jeton = (new Jetons($this->pdo))->creer('bornes-' . $this->sel);

        $chemin = $this->ecrire("petit-$this->sel");
        $uploaded = new UploadedFile($chemin, 'gros.pdf', 'application/pdf', 26214401);   // 25 Mio + 1
        [$code, $corps] = $this->envoyer($app, $jeton, $uploaded);
        self::assertSame(413, $code);
        self::assertStringContainsString('trop gros', $corps['erreur']['cause']);

        $chemin = $this->ecrire("type-$this->sel");
        $uploaded = new UploadedFile($chemin, 'malveillant.exe', 'application/octet-stream', 64);
        [$code, $corps] = $this->envoyer($app, $jeton, $uploaded);
        self::assertSame(415, $code);
        self::assertStringContainsString('type refusé', $corps['erreur']['cause']);
    }

    public function testSuiviDuTravailRepondEtatEtMetadonneesSousJeton(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $jeton = (new Jetons($this->pdo))->creer('suivi-' . $this->sel);
        $metadonnees = ['titre' => 'Facture synthétique', 'type' => 'facture'];

        $chemin = $this->ecrire("suivi-$this->sel");
        file_put_contents($chemin, "suivi-$this->sel");
        $uploaded = new UploadedFile($chemin, 'suivi.pdf', 'application/pdf', filesize($chemin));
        [$code, $corps] = $this->envoyer($app, $jeton, $uploaded, [
            'metadonnees' => json_encode($metadonnees, JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(202, $code, json_encode($corps));
        $id = $corps['travail'];

        $rep = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/depots/' . $id)
            ->withHeader('Authorization', 'Bearer ' . $jeton));
        $suivi = json_decode((string) $rep->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $rep->getStatusCode(), json_encode($suivi));
        self::assertSame($id, $suivi['travail']);
        self::assertSame('recu', $suivi['etat']);
        self::assertSame('api', $suivi['source']);
        self::assertSame($metadonnees, $suivi['metadonnees']);

        // Sans jeton, le suivi ne fuit pas ; un travail inconnu est nommé, jamais un 500 muet.
        $nu = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/depots/' . $id));
        self::assertSame(401, $nu->getStatusCode());
        $absent = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/depots/999999999')
            ->withHeader('Authorization', 'Bearer ' . $jeton));
        self::assertSame(404, $absent->getStatusCode());
        self::assertStringContainsString('travail introuvable', (string) $absent->getBody());
    }

    public function testLeJetonNEstJamaisEnClairEnBase(): void
    {
        $jeton = (new Jetons($this->pdo))->creer('hache-' . $this->sel);
        $requete = $this->pdo->prepare('SELECT application, empreinte, portee, revoque_le FROM jonctions_jetons WHERE application = ?');
        $requete->execute(['hache-' . $this->sel]);
        $ligne = $requete->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($ligne);
        self::assertSame(hash('sha256', $jeton), $ligne['empreinte']);
        self::assertStringNotContainsString($jeton, (string) $ligne['empreinte']);
        self::assertSame('depot', $ligne['portee']);
        self::assertNull($ligne['revoque_le']);
    }

    public function testLaSourceApiEcritDansLaZoneDeTravailEtPasseParTravaux(): void
    {
        $source = new SourceApi($this->tmp . '/ingestion/api', new Travaux($this->pdo), $this->pdo);
        mkdir($this->tmp . '/ingestion/api', 0775, true);
        $orphelin = $this->tmp . '/ingestion/api/orphelin-' . $this->sel . '.pdf';
        file_put_contents($orphelin, "orphelin-$this->sel");

        // Un fichier présent sans travail (reprise après panne) est relevé une fois puis enregistré.
        $entrees = $source->relever();
        self::assertCount(1, $entrees);
        self::assertSame('api', $entrees[0]->source);
        (new Travaux($this->pdo))->recevoir($entrees[0]);
        self::assertSame([], $source->relever(), 'un travail déjà reçu n’est plus relevé');
    }

    public function testLesRoutesSousJetonSontDeclareesHorsSession(): void
    {
        self::assertSame('publique:api-depot', DroitsRoutes::permissionPour('POST', '/api/v1/depots'));
        self::assertSame('publique:api-depot-suivi', DroitsRoutes::permissionPour('GET', '/api/v1/depots/12'));
        self::assertNull(DroitsRoutes::permissionPour('POST', '/api/v1/autre'));
    }

    /** @return array{int, array<string, mixed>} */
    private function pousser(App $app, string $jeton, string $lettre, string $contenu): array
    {
        $chemin = $this->ecrire("test-$this->sel-$lettre");
        file_put_contents($chemin, $contenu);
        $uploaded = new UploadedFile($chemin, "test-$this->sel-$lettre.pdf", 'application/pdf', strlen($contenu));

        return $this->envoyer($app, $jeton, $uploaded);
    }

    /**
     * @param array<string, mixed> $champs
     * @return array{int, array<string, mixed>}
     */
    private function envoyer(App $app, string $jeton, UploadedFile $fichier, array $champs = []): array
    {
        $requete = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/depots')
            ->withUploadedFiles(['fichier' => $fichier]);
        if ($jeton !== '') {
            $requete = $requete->withHeader('Authorization', 'Bearer ' . $jeton);
        }
        if ($champs !== []) {
            $requete = $requete->withParsedBody($champs);
        }
        $reponse = $app->handle($requete);

        return [$reponse->getStatusCode(), json_decode((string) $reponse->getBody(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function ecrire(string $nom): string
    {
        return $this->tmp . '/' . $nom . '-' . bin2hex(random_bytes(3)) . '.pdf';
    }

    private function supprimerDossier(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        foreach (glob($dossier . '/*') ?: [] as $element) {
            is_dir($element) ? $this->supprimerDossier($element) : unlink($element);
        }
        rmdir($dossier);
    }
}
