<?php

declare(strict_types=1);

namespace Ged\Tests\DepotFileserver;

use Ged\DepotFileserver\Arborescence;
use Ged\DepotFileserver\DepotSepare;
use Ged\DepotFileserver\HorlogeFigee;
use Ged\DepotFileserver\Manifeste;
use Ged\DepotFileserver\Veille;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de la veille : détection des modifications, déplacements et suppressions faits HORS GED,
 * traçabilité au journal, versions et garantie. Le dossier est la source de vérité.
 */
final class VeilleTest extends TestCase
{
    private string $fonds;
    private string $garantie;

    protected function setUp(): void
    {
        $this->fonds = SupportDepot::dossier('veille-fonds');
        $this->garantie = SupportDepot::dossier('veille-garantie');
    }

    protected function tearDown(): void
    {
        SupportDepot::supprimer($this->fonds);
        SupportDepot::supprimer($this->garantie);
    }

    private function veille(string $instant = '2026-10-13T10:00:00+02:00'): Veille
    {
        return new Veille($this->fonds, new DepotSepare($this->garantie), new HorlogeFigee($instant));
    }

    /** @return array<string, array<string,mixed>> */
    private function manifeste(): array
    {
        return (new Manifeste($this->fonds))->lire()['fichiers'];
    }

    public function testLeScanInitialVersionneEtGarantitTousLesFichiers(): void
    {
        SupportDepot::ecrire($this->fonds . '/2026/facture.txt', 'v1');
        SupportDepot::ecrire($this->fonds . '/2026/contrat.txt', 'c1');

        $stats = $this->veille()->passer();

        self::assertSame(2, $stats['fichiers']);
        self::assertSame(2, $stats['nouveaux']);
        self::assertSame(2, $stats['versions']);
        self::assertFileExists($this->fonds . '/2026/.facture.txt/v001_' . substr(hash('sha256', 'v1'), 0, 8) . '.txt');
        self::assertTrue((new DepotSepare($this->garantie))->aCopie(hash('sha256', 'v1')));
    }

    public function testUneModificationHorsGedArchiveLaVersionPrecedenteDansUnDossierMasque(): void
    {
        $fichier = $this->fonds . '/2026/facture.txt';
        SupportDepot::ecrire($fichier, 'v1');
        $this->veille()->passer();

        SupportDepot::ecrire($fichier, 'v2');
        $stats = $this->veille('2026-10-13T11:00:00+02:00')->passer();

        self::assertSame(1, $stats['modifies']);
        self::assertSame('v2', (string) file_get_contents($fichier));
        $dossierVersions = $this->fonds . '/2026/.facture.txt';
        self::assertFileExists($dossierVersions . '/v001_' . substr(hash('sha256', 'v1'), 0, 8) . '.txt');
        self::assertFileExists($dossierVersions . '/v002_' . substr(hash('sha256', 'v2'), 0, 8) . '.txt');
        self::assertSame('v1', (string) file_get_contents($dossierVersions . '/v001_' . substr(hash('sha256', 'v1'), 0, 8) . '.txt'));

        $depot = new DepotSepare($this->garantie);
        self::assertTrue($depot->aCopie(hash('sha256', 'v1')), 'l’ancienne version reste garantie');
        self::assertTrue($depot->aCopie(hash('sha256', 'v2')));

        $journal = $this->veille()->journal();
        self::assertSame('modifie', $journal[0]['type']);
        self::assertSame('2026/facture.txt', $journal[0]['chemin']);
        self::assertSame(hash('sha256', 'v1'), $journal[0]['ancienne_empreinte']);
    }

    public function testUnFichierDeplaceHorsGedEstRetrouveParSonEmpreinte(): void
    {
        SupportDepot::ecrire($this->fonds . '/2026/facture.txt', 'v1');
        $this->veille()->passer();

        SupportDepot::ecrire($this->fonds . '/2026/archives/facture.txt', 'v1');
        @unlink($this->fonds . '/2026/facture.txt');
        $stats = $this->veille('2026-10-13T11:00:00+02:00')->passer();

        self::assertSame(1, $stats['deplaces']);
        self::assertSame(0, $stats['supprimes']);
        $journal = $this->veille()->journal();
        self::assertSame('deplace', $journal[0]['type']);
        self::assertSame('2026/archives/facture.txt', $journal[0]['chemin']);
        self::assertSame('2026/facture.txt', $journal[0]['ancien_chemin']);
        self::assertArrayHasKey('2026/archives/facture.txt', $this->manifeste());
    }

    public function testUnFichierSupprimeHorsGedResteAuManifesteEtDansLeDepotSepare(): void
    {
        $fichier = $this->fonds . '/contrat.txt';
        SupportDepot::ecrire($fichier, 'important');
        $this->veille()->passer();
        @unlink($fichier);

        $stats = $this->veille('2026-10-13T11:00:00+02:00')->passer();

        self::assertSame(1, $stats['supprimes']);
        $manifeste = $this->manifeste();
        self::assertArrayHasKey('contrat.txt', $manifeste);
        self::assertSame('absent', $manifeste['contrat.txt']['etat']);
        self::assertSame('2026-10-13T11:00:00+02:00', $manifeste['contrat.txt']['absent_depuis']);
        self::assertTrue((new DepotSepare($this->garantie))->aCopie(hash('sha256', 'important')));
        self::assertSame('supprime', $this->veille()->journal()[0]['type']);
    }

    public function testUneSuppressionNestPasSignaleeDeuxFois(): void
    {
        SupportDepot::ecrire($this->fonds . '/contrat.txt', 'x');
        $this->veille()->passer();
        @unlink($this->fonds . '/contrat.txt');

        $premier = $this->veille('2026-10-13T11:00:00+02:00')->passer();
        $second = $this->veille('2026-10-13T12:00:00+02:00')->passer();

        self::assertSame(1, $premier['supprimes']);
        self::assertSame(0, $second['supprimes']);
    }

    public function testLaGarantieDetecteUnContenuManquant(): void
    {
        $fichier = $this->fonds . '/facture.txt';
        SupportDepot::ecrire($fichier, 'v1');
        $this->veille()->passer();

        $depot = new DepotSepare($this->garantie);
        self::assertSame([], $this->veille()->verifierGarantie()['manquants']);

        @unlink($depot->cheminObjet(hash('sha256', 'v1')));
        $verification = $this->veille()->verifierGarantie();

        self::assertSame(1, $verification['verifies']);
        self::assertSame(['facture.txt'], $verification['manquants']);
    }

    public function testLeJournalEtLeDernierReleveSontEcritsDansLeDossierInterne(): void
    {
        SupportDepot::ecrire($this->fonds . '/a.txt', 'a');
        $this->veille()->passer();

        self::assertFileExists($this->fonds . '/' . Arborescence::INTERNE . '/empreintes.json');
        self::assertFileExists($this->fonds . '/' . Arborescence::INTERNE . '/journal.ndjson');
        self::assertSame(1, $this->veille()->dernierReleve()['evenements']);
    }
}
