<?php

declare(strict_types=1);

namespace Ged\Tests\Ui;

use Ged\Kernel;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\DepotFichiers;
use Ged\Socle\ReconstructionIndex;
use Ged\Socle\Registre;
use Ged\Tests\Socle\SupportCorpus;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Oracle de la finition du 11-10 : le bilan de l'écran Application affiche l'ÉCART quand des documents du
 * périmètre ont disparu du fonds PENDANT la reconstruction (champ `ecart` posé par
 * `ReconstructionIndex::avancer`, en plus des absents du lancement). Avant code, le gabarit ne rendait
 * rien pour ce champ : l'utilisateur voyait « terminée » sans savoir que des documents annoncés n'avaient
 * pas pu être relus. L'écran est rendu par la route réelle, comme un utilisateur, et on assène sur le HTML
 * qu'il voit — jamais sur le seul état du service.
 */
final class ApplicationReconstructionEcartTest extends TestCase
{
    use SupportCorpus;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        SchemaRecherche::installer($this->pdo);
        Registre::oublier();
    }

    protected function tearDown(): void
    {
        Registre::oublier();
        $this->tearDownCorpus();
    }

    private function deposer(DepotFichiers $depot, int $n): void
    {
        $depot->deposer($this->pieceADeposer("piece-$n.txt", "contenu synthétique $n"), [
            'titre' => "Pièce $n", 'type' => 'Facture', 'emetteur' => 'Atelier Fictif SA',
            'date' => '2026-10-11', 'texte' => "contenu synthétique $n", 'statut' => 'valide',
        ]);
    }

    /** Dépose `$nombre` documents puis lance la reconstruction ; rend l'état de lancement. */
    private function reconstruire(int $nombre): array
    {
        $depot = new DepotFichiers($this->config);
        for ($i = 1; $i <= $nombre; $i++) {
            $this->deposer($depot, $i);
        }

        return (new ReconstructionIndex($this->config))->lancer('admin@exemple.test');
    }

    /**
     * Mène la reconstruction à son terme par tranches et rend l'état final.
     *
     * @param array<string, mixed> $lance
     * @return array<string, mixed>
     */
    private function jusquaLaFin(ReconstructionIndex $job, array $lance, int $lot = 2): array
    {
        $etat = $lance;
        for ($tranches = 0; ($etat['etat'] ?? '') === 'en_cours'; $tranches++) {
            self::assertLessThan(10, $tranches, 'la reconstruction ne doit jamais rester bloquée en cours');
            $etat = $job->avancer((int) $lance['id'], $lot);
        }

        return $etat;
    }

    /** @return array{code:int, html:string} */
    private function ecran(): array
    {
        $reponse = Kernel::creer(dirname(__DIR__, 3))->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/application'));

        return ['code' => $reponse->getStatusCode(), 'html' => (string) $reponse->getBody()];
    }

    public function testLeBilanAfficheLEcartQuandUnFichierDisparaitPendantLaReconstruction(): void
    {
        $job = new ReconstructionIndex($this->config);
        $lance = $this->reconstruire(5);
        // Un document du périmètre disparaît APRÈS le lancement : sa ligne d'index reste, mais plus rien
        // ne peut le relire — c'est exactement le cas que `avancer()` compte dans `ecart`.
        $disparait = IndexPleinTexte::depuis($this->config, $this->pdo)->aRelire()[0]->chemin;
        unlink($this->racine . '/fonds/' . $disparait);

        $etat = $this->jusquaLaFin($job, $lance);
        self::assertSame('termine', $etat['etat']);
        self::assertSame(1, (int) $etat['ecart'], 'le fichier disparu en cours de route est compté');

        ['code' => $code, 'html' => $html] = $this->ecran();
        self::assertSame(200, $code, $html);
        self::assertStringContainsString('data-testid="reconstruction-ecart"', $html,
            'le bilan doit afficher l’écart des documents disparus pendant la reconstruction');
        self::assertMatchesRegularExpression(
            '/data-testid="reconstruction-ecart".*?1\s+document/s',
            $html,
            'l’écart affiché porte son compte (1 document), lisible par l’utilisateur');
    }

    public function testLEcartResteAbsentQuandLaReconstructionEstCompleteSansDisparition(): void
    {
        $job = new ReconstructionIndex($this->config);
        $lance = $this->reconstruire(3);

        $etat = $this->jusquaLaFin($job, $lance);
        self::assertSame('termine', $etat['etat']);
        self::assertSame(0, (int) ($etat['ecart'] ?? 0), 'aucun écart quand rien ne disparaît');

        ['code' => $code, 'html' => $html] = $this->ecran();
        self::assertSame(200, $code, $html);
        self::assertStringContainsString('data-testid="reconstruction-terminee"', $html);
        self::assertStringNotContainsString('data-testid="reconstruction-ecart"', $html,
            'un écart nul ne doit rien afficher : le bilan ne crie pas pour rien');
    }
}
