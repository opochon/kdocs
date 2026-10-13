<?php

declare(strict_types=1);

namespace Ged\Tests\DepotFileserver;

use Ged\DepotFileserver\Arborescence;
use Ged\DepotFileserver\DepotSepare;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du dépôt séparé : adressage par contenu, correspondance vers l'emplacement d'origine,
 * idempotence, jamais de suppression. C'est la GARANTIE des versions (le dossier masqué est le confort).
 */
final class DepotSepareTest extends TestCase
{
    private string $fonds;
    private string $depot;

    protected function setUp(): void
    {
        $this->fonds = SupportDepot::dossier('depot-separe-fonds');
        $this->depot = SupportDepot::dossier('depot-separe-garantie');
    }

    protected function tearDown(): void
    {
        SupportDepot::supprimer($this->fonds);
        SupportDepot::supprimer($this->depot);
    }

    public function testUneVersionEstConserveeDansLeDepotSepareAvecSaCorrespondance(): void
    {
        $fichier = $this->fonds . '/2026/facture.txt';
        SupportDepot::ecrire($fichier, 'contenu-v1');
        $empreinte = Arborescence::empreinte($fichier);
        $depot = new DepotSepare($this->depot);

        self::assertFalse($depot->aCopie($empreinte));
        $depot->conserver($fichier, $empreinte, '2026/facture.txt', ['titre' => 'Facture'], '2026-10-13T10:00:00+02:00');

        self::assertTrue($depot->aCopie($empreinte));
        self::assertSame(hash('sha256', 'contenu-v1'), Arborescence::empreinte($depot->cheminObjet($empreinte)));
        $correspondance = $depot->correspondance($empreinte);
        self::assertIsArray($correspondance);
        self::assertSame('2026/facture.txt', $correspondance['vues'][0]['chemin_origine']);
        self::assertSame('Facture', $correspondance['meta']['titre']);
        self::assertSame(['2026/facture.txt'], $depot->origines($empreinte));
    }

    public function testLaConservationEstIdempotenteEtNePerdAucuneOrigine(): void
    {
        $depot = new DepotSepare($this->depot);
        $un = $this->fonds . '/a.txt';
        $deux = $this->fonds . '/b.txt';
        SupportDepot::ecrire($un, 'meme-contenu');
        SupportDepot::ecrire($deux, 'meme-contenu');
        $empreinte = Arborescence::empreinte($un);

        $depot->conserver($un, $empreinte, 'a.txt', [], '2026-10-13T10:00:00+02:00');
        $depot->conserver($deux, $empreinte, 'b.txt', [], '2026-10-13T11:00:00+02:00');
        $depot->conserver($un, $empreinte, 'a.txt', [], '2026-10-13T10:00:00+02:00');

        self::assertSame(1, $depot->compter());
        self::assertSame(['a.txt', 'b.txt'], $depot->origines($empreinte), 'on retrouve où le contenu est passé');
    }

    public function testUneEmpreinteIncoherenteEstRefuseeAvecLaCause(): void
    {
        $fichier = $this->fonds . '/x.txt';
        SupportDepot::ecrire($fichier, 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empreinte incohérente');
        (new DepotSepare($this->depot))->conserver($fichier, str_repeat('0', 64), 'x.txt', [], null);
    }
}
