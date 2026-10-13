<?php

declare(strict_types=1);

namespace Ged\Tests\DepotFileserver;

use Ged\DepotFileserver\Arborescence;
use Ged\DepotFileserver\Versions;
use PHPUnit\Framework\TestCase;

/**
 * Oracle des versions : dossier masqué `.<nom du fichier>` à côté du document, version adressée par
 * contenu, idempotente, jamais écrasée ; la restauration archive d'abord le contenu courant.
 */
final class VersionsTest extends TestCase
{
    private string $fonds;

    protected function setUp(): void
    {
        $this->fonds = SupportDepot::dossier('versions');
    }

    protected function tearDown(): void
    {
        SupportDepot::supprimer($this->fonds);
    }

    public function testLesVersionsViventDansLeDossierMasqueALaPlaceDuFichier(): void
    {
        $fichier = $this->fonds . '/2026/facture.txt';
        SupportDepot::ecrire($fichier, 'v1');
        $versions = new Versions();

        $archive = $versions->archiver($fichier, 1, Arborescence::empreinte($fichier));

        self::assertSame($this->fonds . '/2026/.facture.txt/v001_' . substr(hash('sha256', 'v1'), 0, 8) . '.txt', $archive);
        self::assertFileExists($archive);
        self::assertSame('v1', (string) file_get_contents($archive));
        self::assertSame(2, $versions->numeroSuivant($fichier));
    }

    public function testArchiverDeuxFoisLeMemeContenuNeDoublonnePas(): void
    {
        $fichier = $this->fonds . '/facture.txt';
        SupportDepot::ecrire($fichier, 'v1');
        $versions = new Versions();
        $empreinte = Arborescence::empreinte($fichier);

        $premier = $versions->archiver($fichier, 1, $empreinte);
        $second = $versions->archiver($fichier, 1, $empreinte);

        self::assertSame($premier, $second);
        self::assertCount(1, $versions->lister($fichier));
    }

    public function testRestaurerArchiveLeContenuCourantPuisRestitueLaVersionChoisie(): void
    {
        $fichier = $this->fonds . '/contrat.txt';
        SupportDepot::ecrire($fichier, 'v1');
        $versions = new Versions();
        $v1 = Arborescence::empreinte($fichier);
        $versions->archiver($fichier, 1, $v1);

        SupportDepot::ecrire($fichier, 'v2');
        $restauree = $versions->restaurer($fichier, substr($v1, 0, 8));

        self::assertSame('v1', (string) file_get_contents($fichier));
        self::assertSame($v1, $restauree);
        $noms = array_column($versions->lister($fichier), 'empreinte');
        self::assertContains(substr($v1, 0, 8), $noms, 'la v1 reste archivée');
        self::assertContains(substr(hash('sha256', 'v2'), 0, 8), $noms, 'la v2 courante a été archivée avant restauration');
    }

    public function testRestaurerUneVersionInconnueEstRefusee(): void
    {
        $fichier = $this->fonds . '/contrat.txt';
        SupportDepot::ecrire($fichier, 'v1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('version introuvable');
        (new Versions())->restaurer($fichier, 'deadbeef');
    }
}
