<?php

declare(strict_types=1);

namespace Ged\Tests\Archivage;

use Ged\Archivage\Empreintes;
use Ged\Archivage\ErreurScellement;
use Ged\Archivage\Scellement;
use Ged\Archivage\TsaReelle;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du scellement (clause 1 du lot V2-09) : le scellement écrit dans le dossier, à côté du document,
 * un jeton TSA et son justificatif ; l'échéance vient de la date du document et de la table des durées.
 */
final class ScellementTest extends TestCase
{
    use SupportArchivage;

    protected function setUp(): void
    {
        $this->setUpArchivage();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchivage();
    }

    public function testScellerEcritLeScellementEtLeJetonDansLeDossier(): void
    {
        $id = $this->deposer('facture-synth-1.pdf', ['titre' => 'Facture synthétique 1', 'type' => 'facture', 'date' => '2026-03-01']);

        $resultat = $this->scelleur()->sceller($id, 'olivia');

        $absolu = $this->absolu($id);
        self::assertFileExists(Scellement::cheminJson($absolu), 'le justificatif de scellement doit être à côté du document');
        self::assertFileExists(Scellement::cheminJeton($absolu), 'le jeton TSA doit être à côté du document');

        $scellement = Scellement::lire($absolu, $id);
        self::assertNotNull($scellement);
        self::assertSame($id, $scellement['document_id']);
        self::assertSame('sha256', $scellement['algorithme']);
        self::assertSame(Empreintes::fichier($absolu), $scellement['empreinte_fichier']);
        self::assertSame(Empreintes::scellee($scellement['empreinte_fichier'], $scellement['empreinte_meta']), $scellement['empreinte_scellee']);
        self::assertSame('2026-03-01', $scellement['reference']);
        self::assertSame(10, $scellement['retention_annees']);
        self::assertSame('2036-03-01', $scellement['echeance']);
        self::assertSame('olivia', $scellement['scelle_par']);
        self::assertFalse($resultat['already_sealed']);

        self::assertSame("\x30", substr((string) file_get_contents(Scellement::cheminJeton($absolu)), 0, 1));
    }

    public function testScellerEstIdempotent(): void
    {
        $id = $this->deposer('facture-synth-2.pdf', ['titre' => 'Facture synthétique 2', 'type' => 'facture', 'date' => '2026-03-02']);

        $premier = $this->scelleur()->sceller($id, 'olivia');
        $this->horloge()->avancer('+10 days');
        $second = $this->scelleur()->sceller($id, 'marc');

        self::assertFalse($premier['already_sealed']);
        self::assertTrue($second['already_sealed']);
        self::assertSame($premier['scelle_le'], $second['scelle_le'], 're-sceller ne réécrit pas l\'horodatage');
        self::assertSame('olivia', Scellement::lire($this->absolu($id), $id)['scelle_par'] ?? null);
    }

    public function testEcheanceSansDateSAppuieSurLHorlogeFigee(): void
    {
        $id = $this->deposer('courrier-synth.pdf', ['titre' => 'Courrier synthétique', 'type' => 'courrier']);

        $this->scelleur()->sceller($id, 'olivia');

        self::assertSame('2026-04-01', Scellement::lire($this->absolu($id), $id)['reference'] ?? null);
        self::assertSame('2036-04-01', Scellement::lire($this->absolu($id), $id)['echeance'] ?? null);
    }

    public function testTsaSansUrlRefuseDeScellerEnSilence(): void
    {
        $id = $this->deposer('facture-synth-3.pdf', ['titre' => 'Facture synthétique 3', 'type' => 'facture', 'date' => '2026-03-03']);
        $scelleur = new \Ged\Archivage\Scelleur($this->depot(), new TsaReelle(''), $this->horloge(), $this->retention());

        try {
            $scelleur->sceller($id, 'olivia');
            self::fail('sans TSA joignable, aucun scellement ne doit être posé');
        } catch (ErreurScellement $e) {
            self::assertStringContainsString('TSA_URL', $e->getMessage());
            self::assertFileDoesNotExist(Scellement::cheminJson($this->absolu($id)), 'aucun justificatif ne doit rester');
        }
    }

    public function testUnDocumentDeLaCorbeilleNeSeScellePas(): void
    {
        $id = $this->deposer('facture-synth-4.pdf', ['titre' => 'Facture synthétique 4', 'type' => 'facture', 'date' => '2026-03-04']);
        $this->depot()->miseALaCorbeille($id, 'olivia');

        try {
            $this->scelleur()->sceller($id, 'olivia');
            self::fail('un document à la corbeille ne peut pas être scellé');
        } catch (ErreurScellement $e) {
            self::assertStringContainsString('corbeille', $e->getMessage());
        }
    }

    public function testAucuneSuppressionDansLeCodeDuSecteur(): void
    {
        $dossier = dirname(__DIR__, 3) . '/src/Archivage';
        $fautes = [];
        foreach (glob($dossier . '/*.php') ?: [] as $fichier) {
            if (preg_match('/\bDELETE\s+FROM\b/i', (string) file_get_contents($fichier)) === 1
                || preg_match('/\bunlink\s*\(/i', (string) file_get_contents($fichier)) === 1) {
                $fautes[] = basename($fichier);
            }
        }
        self::assertSame([], $fautes, 'le secteur Archivage ne supprime jamais une donnée utilisateur');
    }
}
