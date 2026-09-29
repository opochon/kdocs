<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use Ged\Socle\Export;
use Ged\Socle\Indexeur;
use Ged\Socle\Reconstructeur;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-01 §5 (flux F12) : la base n'est qu'un index. Vidée puis reconstruite depuis le dossier,
 * elle rend le même contenu — preuve par le hash d'un export canonique. La commande
 * `php tools/reconstruire-index.php` est l'outil opérateur, testé ici comme le juge la rejouera.
 */
final class ReconstructionTest extends TestCase
{
    use SupportCorpus;

    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->depot = new DepotFichiers($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    /** Un fonds réaliste : un document neuf, un modifié hors application (v2), un à la corbeille. */
    private function peupler(): array
    {
        $neuf = $this->depot->deposer($this->pieceADeposer('facture.pdf', 'facture contenu'), [
            'titre' => 'Facture énergie', 'type' => 'facture', 'emetteur' => 'Atelier X',
            'date' => '2026-09-10', 'montant' => 99.5, 'etiquettes' => ['urgent', 'bureau'], 'source' => 'ecran',
        ]);

        $modifie = $this->depot->deposer($this->pieceADeposer('pv.pdf', 'pv v1'), [
            'titre' => 'PV réception', 'type' => 'pv', 'emetteur' => 'Cabinet Y', 'date' => '2026-09-11',
        ]);
        file_put_contents($this->racine . '/fonds/2026/pv/Cabinet_Y/PV_réception.pdf', 'pv v2 réécrit');
        $indexeur = new Indexeur($this->config);
        $indexeur->passer();
        $indexeur->passer();

        $sorti = $this->depot->deposer($this->pieceADeposer('brouillon.pdf', 'brouillon'), ['titre' => 'Brouillon']);
        $this->depot->miseALaCorbeille($sorti, 'olivia');

        return ['neuf' => $neuf, 'modifie' => $modifie, 'sorti' => $sorti];
    }

    public function testLaBaseVideePuisReconstruiteRendLeMemeContenu(): void
    {
        $this->peupler();
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn());

        $avant = Export::hacher($this->pdo);
        $stats = (new Reconstructeur($this->config))->reconstruire();
        $apres = Export::hacher($this->pdo);

        self::assertSame($avant, $apres, 'l’index reconstruit depuis le dossier est identique, hash pour hash');
        self::assertSame(2, $stats['documents'], 'les deux documents vivants reviennent du fonds');
        self::assertSame(1, $stats['corbeille'], 'le document à la corbeille est toujours dans le dossier');
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn());
        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM document_versions')->fetchColumn(),
            'v1 de chaque document (3), et la v2 du PV');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM documents WHERE supprime_le IS NOT NULL')->fetchColumn(),
            'le drapeau corbeille est reconstruit depuis l’emplacement du fichier');
    }

    public function testLesMetadonneesReviennentDuDossierPasDeLaMemoire(): void
    {
        $ids = $this->peupler();
        $avant = $this->depot->fiche($ids['neuf']);

        (new Reconstructeur($this->config))->reconstruire();

        $apres = $this->pdo->query('SELECT titre, type, date_document, montant, etiquettes, meta FROM documents'
            . " WHERE chemin_relatif = '2026/facture/Atelier_X/Facture_énergie.pdf'")->fetch();
        self::assertSame('Facture énergie', $apres['titre']);
        self::assertSame('facture', $apres['type']);
        self::assertSame('2026-09-10', $apres['date_document']);
        self::assertSame('99.50', $apres['montant']);
        self::assertSame(['urgent', 'bureau'], json_decode((string) $apres['etiquettes'], true));
        self::assertSame($avant['meta'], json_decode((string) $apres['meta'], true), 'le .meta.json refait l’index à l’identique');
    }

    public function testLaReconstructionRefuseDePerdreUnDocumentAbsentDuFonds(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('unique.pdf', 'unique'), ['titre' => 'Unique']);
        unlink($this->racine . '/fonds/2026/A_classer/Unique.pdf');
        $indexeur = new Indexeur($this->config);
        $indexeur->passer();
        $indexeur->passer();

        $reconstructeur = new Reconstructeur($this->config);
        try {
            $reconstructeur->reconstruire();
            self::fail('la reconstruction devait refuser : un document vivant est absent du dossier');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reconstruction', $e->getMessage());
            self::assertStringContainsString('absent', $e->getMessage(), 'l’erreur se nomme');
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(),
            'rien n’a été détruit pendant le refus');
    }

    public function testLaCommandeReconstruireIndexRendLeMemeHash(): void
    {
        $this->peupler();
        $racineDepot = dirname(__DIR__, 3);
        $avant = Export::hacher($this->pdo);

        $commande = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($racineDepot . '/tools/reconstruire-index.php')
            . ' gedv2_test 2>&1';
        exec($commande, $sorties, $code);

        self::assertSame(0, $code, "la commande devait réussir : " . implode("\n", $sorties));
        self::assertStringContainsString('identique', implode("\n", $sorties), 'le verdict est écrit noir sur blanc');
        self::assertSame($avant, Export::hacher($this->pdo), 'après la commande, l’index est celui du dossier');
    }
}
