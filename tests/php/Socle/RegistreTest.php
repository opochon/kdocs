<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\Registre;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-04 (correction du juge, 30-09) : la configuration de la GED (nomenclature, vues) vit
 * dans le fonds — `.ged/`, lu et écrit par le Registre — et non en base. Le plafond de 20 tables
 * de l'installateur (oracle V2-01) reste intact ; « le dossier fait foi » s'applique aussi à la
 * configuration : elle suit le fonds, même reconstruit (F12) ou déménagé.
 */
final class RegistreTest extends TestCase
{
    use SupportCorpus;

    protected function setUp(): void
    {
        $this->setUpCorpus();
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testÉcritPuisRelitDepuisUneAutreInstance(): void
    {
        $ecrivain = new Registre($this->config);
        $ecrivain->modifier('temoin', static fn (array $etat): array => $etat + ['passes' => 1]);

        // Un AUTRE objet — un autre service de la même requête, ou le processus voisin : la valeur
        // se relit du disque, le cache se rafraîchit sur la signature du fichier.
        $lecteur = new Registre($this->config);
        self::assertSame(1, $lecteur->lire('temoin')['passes'] ?? null, 'la valeur écrite se relit du fonds');
        self::assertSame([], $lecteur->lire('absent'), 'un registre non posé rend son défaut, sans écrire');
        self::assertFileDoesNotExist($this->racine . '/fonds/.ged/absent.json', 'la lecture n’écrit jamais');
    }

    public function testDeuxModificationsSousVerrouSeCumulent(): void
    {
        $incremente = static fn (array $etat): array => ['n' => ($etat['n'] ?? 0) + 1] + $etat;
        $registre = new Registre($this->config);
        $registre->modifier('compteur', $incremente);
        $registre->modifier('compteur', $incremente);
        $registre->modifier('compteur', $incremente);

        self::assertSame(3, (new Registre($this->config))->lire('compteur')['n'],
            'chaque modification repart de l’état le plus frais du fichier, pas d’une vue périmée');
    }

    public function testUneTransformationQuiLèveNEcritRien(): void
    {
        $registre = new Registre($this->config);
        $registre->modifier('garde', static fn (array $etat): array => $etat + ['pose' => 'oui']);

        try {
            $registre->modifier('garde', static function (array $etat): array {
                throw new \RuntimeException('refus du métier');
            });
            self::fail('la transformation levait : le Registre devait la laisser passer');
        } catch (\RuntimeException) {
            // le métier a refusé : c'est SON erreur, elle remonte à l’appelant
        }

        self::assertSame('oui', (new Registre($this->config))->lire('garde')['pose'],
            'l’état d’avant reste publié : un refus n’écrit rien');
    }

    public function testLeDossierRéservéResteHorsDesDocuments(): void
    {
        (new Registre($this->config))->modifier('nomenclature', static fn (): array => ['types' => []]);

        $dossiers = glob($this->racine . '/fonds/*', GLOB_ONLYDIR) ?: [];
        self::assertSame([], array_values(array_filter($dossiers,
            static fn (string $d): bool => basename($d) !== '.ged')),
            '.ged/ est le seul dossier du fonds de test, et il commence par un point : jamais un document');
    }

    public function testUnJSONIllisibleEstUneErreurNomméeJamaisÉcrasée(): void
    {
        $dossier = $this->racine . '/fonds/.ged';
        mkdir($dossier, 0775, true);
        file_put_contents($dossier . '/casse.json', '{pas du json');

        try {
            (new Registre($this->config))->lire('casse');
            self::fail('un fichier illisible devait être une erreur nommée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('casse.json', $e->getMessage(), 'l’erreur nomme le fichier');
        }
        self::assertSame('{pas du json', (string) file_get_contents($dossier . '/casse.json'),
            'le fichier fait foi : il n’est jamais écrasé en silence');
    }
}
