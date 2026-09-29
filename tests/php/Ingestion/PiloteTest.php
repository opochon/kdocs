<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Base;
use Ged\Config;
use Ged\Contrats\Depot;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Schema;
use Ged\Ingestion\SourceDossier;
use Ged\Ingestion\Travaux;
use PDO;
use PHPUnit\Framework\TestCase;

/** Oracle du secteur Ingestion, chaîne complète avec un moteur et un dépôt simulés (base gedv2_test). */
final class PiloteTest extends TestCase
{
    private PDO $pdo;
    private string $tmp;
    private DepotSimule $depot;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        $this->pdo->exec('DROP TABLE IF EXISTS ingestion_travaux, ingestion_messages');   // base de TEST uniquement
        Schema::installer($this->pdo);
        $this->tmp = sys_get_temp_dir() . '/gedv2-ing-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/entree', 0775, true);
        $this->depot = new DepotSimule();
    }

    private function pilote(Decoupeur $decoupeur): Pilote
    {
        $source = new SourceDossier($this->tmp . '/entree', $this->tmp . '/travail/recus', $this->tmp . '/etat.json');

        return new Pilote(new Travaux($this->pdo), [$source], $decoupeur, $this->depot, null, $this->tmp . '/travail');
    }

    public function testUnFichierEnCoursDEcritureNEstPasPris(): void
    {
        file_put_contents($this->tmp . '/entree/lot.pdf', '%PDF-1.4 a');
        $r = $this->pilote(new DecoupeurSimule(1))->tic(5);
        self::assertSame(0, $r['recus'], 'vu une fois : on attend le relevé suivant');
        self::assertFileExists($this->tmp . '/entree/lot.pdf');
    }

    public function testLotDecoupeEnPiecesPuisRange(): void
    {
        file_put_contents($this->tmp . '/entree/courrier.pdf', '%PDF-1.4 lot');
        $p = $this->pilote(new DecoupeurSimule(2));
        $p->tic(5);                              // premier relevé : stabilité
        $r = $p->tic(5);                         // pris, découpé
        self::assertSame(1, $r['recus']);
        self::assertSame(1, $r['decoupes']);
        self::assertFileDoesNotExist($this->tmp . '/entree/courrier.pdf', 'déplacé dans la zone de travail');
        $r = $p->tic(5);                         // les deux pièces rangées
        self::assertSame(2, $r['ranges']);
        self::assertCount(2, $this->depot->deposes);
        self::assertSame('1-2', $this->depot->deposes[0]['meta']['origine']['pages']);
        self::assertSame('courrier (p. 3)', $this->depot->deposes[1]['meta']['titre']);
        self::assertSame('a_controler', $this->depot->deposes[0]['meta']['statut'], 'sans classeur, rien n’est validé seul');
    }

    public function testLeMemeFichierNEntreJamaisDeuxFois(): void
    {
        $p = $this->pilote(new DecoupeurSimule(1));
        foreach (['a.pdf', 'b.pdf'] as $nom) {
            file_put_contents($this->tmp . '/entree/' . $nom, '%PDF-1.4 identique');
        }
        $p->tic(5);
        $r = $p->tic(5);
        self::assertSame(1, $r['recus']);
        self::assertSame(1, $r['doublons']);
        self::assertCount(1, glob($this->tmp . '/travail/doublons/*') ?: [], 'le doublon est mis de côté, jamais supprimé');
    }

    public function testUnEchecRepeteDevientUneErreurNommee(): void
    {
        file_put_contents($this->tmp . '/entree/casse.pdf', '%PDF-1.4 casse');
        $p = $this->pilote(new DecoupeurSimule(0, 'moteur hors service'));
        $p->tic(5);
        for ($i = 0; $i < Travaux::MAX_ESSAIS; $i++) {
            $p->tic(5);
        }
        $t = $this->pdo->query("SELECT etat, essais, erreur FROM ingestion_travaux")->fetch();
        self::assertSame('erreur', $t['etat']);
        self::assertSame(Travaux::MAX_ESSAIS, (int) $t['essais']);
        self::assertStringContainsString('moteur hors service', (string) $t['erreur']);
    }

    public function testUnVerrouExpireEstRepris(): void
    {
        file_put_contents($this->tmp . '/entree/lot.pdf', '%PDF-1.4 reprise');
        $p = $this->pilote(new DecoupeurSimule(1));
        $p->tic(5);
        (new Travaux($this->pdo))->recevoir(new \Ged\Ingestion\Entree($this->copie('%PDF-1.4 autre'), 'dossier', 'autre.pdf'));
        $this->pdo->exec("UPDATE ingestion_travaux SET reserve_jusqua = NOW() - INTERVAL 1 MINUTE");   // tâche tuée
        $r = $p->tic(5);
        self::assertSame(1, $r['decoupes'], 'un lot au verrou expiré est repris');
    }

    public function testPlages(): void
    {
        self::assertSame('25-28,30', Travaux::plages([30, 25, 26, 27, 28]));
        self::assertSame('7', Travaux::plages([7]));
    }

    private function copie(string $contenu): string
    {
        $f = $this->tmp . '/' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($f, $contenu);

        return $f;
    }
}

/** Moteur simulé : rend N pièces (copies du lot), ou échoue. */
final class DecoupeurSimule implements Decoupeur
{
    public function __construct(private readonly int $pieces, private readonly ?string $panne = null)
    {
    }

    public function decouper(string $fichier, string $dossierSortie): array
    {
        if ($this->panne !== null) {
            throw new \RuntimeException($this->panne);
        }
        @mkdir($dossierSortie, 0775, true);
        $r = [];
        for ($i = 1; $i <= $this->pieces; $i++) {
            $f = "$dossierSortie/piece$i.pdf";
            file_put_contents($f, file_get_contents($fichier) . " piece $i");
            $r[] = ['fichier' => $f, 'pages' => $i === 1 ? [1, 2] : [3], 'texte' => "texte $i", 'analyse' => ['origine' => null]];
        }

        return $r;
    }
}

final class DepotSimule implements Depot
{
    /** @var list<array{fichier:string, meta:array<string, mixed>}> */
    public array $deposes = [];

    public function deposer(string $cheminFichier, array $metadonnees): int
    {
        $this->deposes[] = ['fichier' => $cheminFichier, 'meta' => $metadonnees];

        return count($this->deposes);
    }

    public function mettreAJour(int $idDocument, array $metadonnees, string $auteur): void
    {
    }
}
