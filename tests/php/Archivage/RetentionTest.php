<?php

declare(strict_types=1);

namespace Ged\Tests\Archivage;

use Ged\Archivage\Scellement;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de la rétention (clause 4 du lot V2-09) : échéances justes sur horloge figée, table des durées
 * modifiable, liste « à échéance », et jamais d'effacement automatique : la levée est un acte humain tracé.
 */
final class RetentionTest extends TestCase
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

    public function testEcheanceJusteSurHorlogeFigee(): void
    {
        $id = $this->deposer('facture-retention.pdf', ['titre' => 'Facture rétention', 'type' => 'facture', 'date' => '2026-03-01']);
        $this->scelleur()->sceller($id, 'olivia');

        self::assertSame('2036-03-01', Scellement::lire($this->absolu($id), $id)['echeance'] ?? null);
        self::assertSame([], $this->retention()->aEcheance(), 'avant échéance, la liste est vide');

        $this->horloge()->regler('2036-03-01 00:00:00');

        $liste = $this->retention()->aEcheance();
        self::assertCount(1, $liste);
        self::assertSame($id, $liste[0]['document_id']);
        self::assertSame('2036-03-01', $liste[0]['echeance']);
    }

    public function testTableDeDureesModifiable(): void
    {
        $this->retention()->enregistrerDurees(['contrat' => 5]);
        $id = $this->deposer('contrat-retention.pdf', ['titre' => 'Contrat rétention', 'type' => 'contrat', 'date' => '2026-01-01']);

        $this->scelleur()->sceller($id, 'olivia');

        $scellement = Scellement::lire($this->absolu($id), $id);
        self::assertSame(5, $scellement['retention_annees'] ?? null);
        self::assertSame('2031-01-01', $scellement['echeance'] ?? null);
        self::assertFileExists($this->racine . '/.archivage/durees.json', 'la table des durées vit dans le dossier');
    }

    public function testLeverLeScellementEstTraceEtNeSupprimeRien(): void
    {
        $id = $this->deposer('facture-levee.pdf', ['titre' => 'Facture levée', 'type' => 'facture', 'date' => '2026-03-01']);
        $this->scelleur()->sceller($id, 'olivia');
        $cheminJson = Scellement::cheminJson($this->absolu($id));

        $this->retention()->lever($cheminJson, 'marc');

        self::assertFileExists($this->absolu($id), 'lever le scellement ne supprime jamais le document');
        self::assertFileExists($cheminJson, 'le justificatif reste (zéro suppression)');
        $scellement = Scellement::lire($this->absolu($id), $id);
        self::assertNotNull($scellement['leve_le'] ?? null);
        self::assertSame('marc', $scellement['leve_par'] ?? null);
        self::assertStringContainsString('archivage.levee', (string) file_get_contents($this->racine . '/.archivage/journal.ndjson'));

        // Après la levée, le document redevient mutable (un humain a décidé, le journal le trace).
        $this->depot()->mettreAJour($id, ['titre' => 'Facture levée corrigée'], 'marc');
        self::assertSame('Facture levée corrigée', $this->fiche($id)['meta']['titre'] ?? null);
    }

    public function testAucuneSuppressionAutomatiqueSansDecision(): void
    {
        $id = $this->deposer('facture-worm.pdf', ['titre' => 'Facture WORM', 'type' => 'facture', 'date' => '2026-03-01']);
        $this->scelleur()->sceller($id, 'olivia');

        // Le temps passe largement au-delà de l'échéance : rien ne doit disparaître tout seul.
        $this->horloge()->regler('2100-01-01 00:00:00');
        $this->retention()->aEcheance();

        self::assertFileExists($this->absolu($id));
        self::assertFileExists(Scellement::cheminJson($this->absolu($id)));
        self::assertNull($this->fiche($id)['supprime_le']);
    }
}
