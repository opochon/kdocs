<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Config;
use Ged\Contrats\Depot;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Fabrique;
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
        \Ged\Classement\Schema::installer($this->pdo);
        $this->tmp = sys_get_temp_dir() . '/gedv2-ing-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/entree', 0775, true);
        $this->depot = new DepotSimule();
    }

    private function pilote(Decoupeur $decoupeur): Pilote
    {
        $source = new SourceDossier($this->tmp . '/entree', $this->tmp . '/travail/recus', $this->tmp . '/etat.json');

        return new Pilote(new Travaux($this->pdo), [$source], $decoupeur, $this->depot, null, $this->tmp . '/travail');
    }

    public function testMemoireDEtatDansUnDossierQuiNExistePasEncore(): void
    {
        // Régression trouvée par le banc F1 : zone de travail neuve → la mémoire n'était jamais écrite → rien n'entrait.
        file_put_contents($this->tmp . '/entree/lot.pdf', '%PDF-1.4 a');
        $source = new SourceDossier($this->tmp . '/entree', $this->tmp . '/neuf/recus', $this->tmp . '/neuf/etat.json');
        self::assertSame([], $source->relever());
        self::assertCount(1, $source->relever(), 'stable au second relevé, donc pris');
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
        // Toutes ses pièces rangées : le lot n'est plus « en cours de lecture » (défaut trouvé par le banc de charge H-6).
        self::assertSame(0, (new Travaux($this->pdo))->bilan()['en_cours']);
    }

    public function testUnePlageVisionQuiSeRecouvreRangeChaquePageUneSeuleFoisDansLaPieceLaMieuxLue(): void
    {
        file_put_contents($this->tmp . '/entree/vision.pdf', '%PDF-1.4 lot');
        $decoupeur = new class implements Decoupeur {
            public function decouper(string $fichier, string $sortie): array
            {
                mkdir($sortie, 0775, true);
                copy($fichier, $sortie . '/a.pdf');
                file_put_contents($sortie . '/b.pdf', '%PDF-1.4 seconde sortie du moteur');
                return [
                    // La vision a rattaché la frontière 86 à A, mais seul le texte de B porte le
                    // marqueur de cette page. C'est le cas réel : les plages, et non deux PDF
                    // identiques, se recouvrent.
                    ['fichier' => $sortie . '/a.pdf', 'pages' => [85, 86], 'texte' => "Page 85\nFacture A", 'analyse' => []],
                    ['fichier' => $sortie . '/b.pdf', 'pages' => [86], 'texte' => "Page 86\nSuite de facture", 'analyse' => []],
                ];
            }
        };
        $p = $this->pilote($decoupeur);
        $p->tic(5);
        $p->tic(5);
        $p->tic(5);

        // Ce Pilote est celui que Fabrique donne à tools/tache.php : la porte couvre donc le chemin
        // de rangement réel, pas uniquement la sortie brute de Cmd4Decoupeur.
        self::assertCount(2, $this->depot->deposes);
        self::assertSame('85', $this->depot->deposes[0]['meta']['origine']['pages']);
        self::assertSame('86', $this->depot->deposes[1]['meta']['origine']['pages']);
    }

    public function testInvariantDeDecoupeRepareUnePageVisionDoubleEtUnePageSauteeEtAvertit(): void
    {
        // Sortie Vision synthétique R2g : 2 est annoncé deux fois et 3 n'est annoncé par personne.
        // La page manquante prolonge la première séquence ; elle ne peut jamais disparaître ni être dupliquée.
        file_put_contents($this->tmp . '/entree/invariant.pdf', "%PDF-1.4\n/Type /Page\n/Type /Page\n/Type /Page\n/Type /Page\n");
        $decoupeur = new class implements Decoupeur {
            public function decouper(string $fichier, string $sortie): array
            {
                mkdir($sortie, 0775, true);
                copy($fichier, $sortie . '/a.pdf');
                file_put_contents($sortie . '/b.pdf', '%PDF-1.4');

                return [
                    ['fichier' => $sortie . '/a.pdf', 'pages' => [1, 2], 'texte' => "Page 1\nPage 2", 'analyse' => []],
                    ['fichier' => $sortie . '/b.pdf', 'pages' => [2, 4], 'texte' => "Page 4", 'analyse' => []],
                ];
            }
        };
        $p = $this->pilote($decoupeur);
        $p->tic(5);
        $r = $p->tic(5);
        $p->tic(5);

        self::assertSame(['1-3', '4'], array_column(array_map(
            static fn (array $depot): array => $depot['meta']['origine'], $this->depot->deposes,
        ), 'pages'));
        self::assertNotEmpty($r['alertes']);
        self::assertStringContainsString('invariant de découpe', implode("\n", $r['alertes']));
    }

    public function testLaFabriqueBrancheLeClasseurEtLeReglageVision(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        putenv('CMD4_VISION_ENABLED=1');
        try {
            $pilote = Fabrique::pilote($config, $this->pdo, $this->depot, Fabrique::classeur($config, $this->pdo));
            $decoupeur = (new \ReflectionProperty($pilote, 'decoupeur'))->getValue($pilote);
            self::assertTrue((new \ReflectionProperty($decoupeur, 'vision'))->getValue($decoupeur));
            self::assertInstanceOf(ClasseurGed::class, Fabrique::classeur($config, $this->pdo));
        } finally {
            putenv('CMD4_VISION_ENABLED');
        }
    }

    public function testRangementEcritLesPropositionsDeClassementDansLesMetadonnees(): void
    {
        $source = new class ($this->copie('%PDF-1.4 classement')) implements \Ged\Ingestion\Source {
            private bool $fait = false;

            public function __construct(private readonly string $fichier)
            {
            }

            public function relever(): array
            {
                if ($this->fait) {
                    return [];
                }
                $this->fait = true;

                return [new \Ged\Ingestion\Entree($this->fichier, 'dossier', 'scan.pdf')];
            }
        };
        $decoupeur = new class () implements Decoupeur {
            public function decouper(string $fichier, string $sortie): array
            {
                mkdir($sortie, 0775, true);
                $piece = $sortie . '/piece.pdf';
                copy($fichier, $piece);

                return [['fichier' => $piece, 'pages' => [1],
                    'texte' => "**Papeterie Fictive SA**\nFacture N° F-2026-01\nDate : 05.01.2026",
                    'analyse' => ['origine' => null]]];
            }
        };
        $config = new Config(dirname(__DIR__, 3));
        $pilote = new Pilote(new Travaux($this->pdo), [$source], $decoupeur, $this->depot,
            new ClasseurGed($config, $this->pdo, new ClientIaNul()), $this->tmp . '/travail');
        $pilote->tic(5);
        $pilote->tic(5);

        $meta = $this->depot->deposes[0]['meta'];
        self::assertSame('Facture', $meta['type']);
        self::assertSame('Papeterie Fictive SA', $meta['emetteur']);
        self::assertSame('2026-01-05', $meta['date']);
        self::assertSame('F-2026-01', $meta['titre']);
    }

    public function testDepotReussiDeclencheLeWorkflowDocumentDepose(): void
    {
        // Oracle inter-secteurs : une notification ne dépend pas du vrai fonds, mais prouve que le
        // Pilote appelle bien l'écouteur APRÈS le dépôt et avec l'identifiant rendu par celui-ci.
        $this->pdo->exec('DROP TABLE IF EXISTS workflow_journal, workflow_instances, workflow_definitions');
        \Ged\Workflows\Schema::installer($this->pdo);
        (new \Ged\Workflows\Regles($this->pdo))->creer([
            'nom' => 'Trace le dépôt', 'declencheur' => 'document_depose', 'conditions' => [],
            'actions' => [['type' => 'notifier', 'destinataire' => 'archive', 'message' => 'Reçu {titre}']], 'actif' => true,
        ]);
        $config = new Config(dirname(__DIR__, 3));
        $source = new class ($this->copie('%PDF-1.4 workflow')) implements \Ged\Ingestion\Source {
            private bool $fait = false;

            public function __construct(private readonly string $fichier)
            {
            }

            public function relever(): array
            {
                if ($this->fait) {
                    return [];
                }
                $this->fait = true;

                return [new \Ged\Ingestion\Entree($this->fichier, 'dossier', 'workflow.pdf')];
            }
        };
        $pilote = new Pilote(new Travaux($this->pdo), [$source], new DecoupeurSimule(1), $this->depot,
            null, $this->tmp . '/travail', $config);
        $pilote->tic(5);
        $pilote->tic(5);

        $notifications = (int) $this->pdo->query("SELECT COUNT(*) FROM workflow_journal WHERE genre = 'notification'")->fetchColumn();
        $this->pdo->exec('DROP TABLE IF EXISTS workflow_journal, workflow_instances, workflow_definitions');
        self::assertSame(1, $notifications);
    }

    public function testTacheIngestionSynchroniseAussiLIndexPleinTexte(): void
    {
        $cles = ['INGESTION_DOSSIER', 'INGESTION_TRAVAIL', 'STORAGE_ROOT'];
        $avant = array_combine($cles, array_map('getenv', $cles));
        $bac = $this->tmp . '/tache';
        mkdir($bac . '/entree', 0775, true);
        foreach (['INGESTION_DOSSIER' => $bac . '/entree', 'INGESTION_TRAVAIL' => $bac . '/travail', 'STORAGE_ROOT' => $bac . '/fonds'] as $cle => $valeur) {
            putenv("$cle=$valeur");
        }
        try {
            $config = new Config(dirname(__DIR__, 3));
            \Ged\Socle\Schema::installer($this->pdo);
            $titre = 'Index tache ' . bin2hex(random_bytes(4));
            $source = $bac . '/a-indexer.pdf';
            file_put_contents($source, '%PDF-1.4 index synthétique');
            (new \Ged\Socle\DepotFichiers($config))->deposer($source, [
                'titre' => $titre, 'source' => 'test', 'texte' => 'mot-cle-indexable-synthetique', 'statut' => 'a_controler',
            ]);

            $commande = [PHP_BINARY, dirname(__DIR__, 3) . '/tools/tache.php', 'ingestion', '--budget=1', '--tours=1', '--limite=10'];
            $processus = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux);
            self::assertIsResource($processus);
            $sortie = stream_get_contents($tuyaux[1]);
            $erreur = stream_get_contents($tuyaux[2]);
            fclose($tuyaux[1]);
            fclose($tuyaux[2]);
            self::assertSame(0, proc_close($processus), $erreur . $sortie);
            self::assertStringContainsString('ingestion-indexation', $sortie);
            $requete = $this->pdo->prepare('SELECT COUNT(*) FROM recherche_textes WHERE titre = ? AND absent_le IS NULL');
            $requete->execute([$titre]);
            self::assertSame(1, (int) $requete->fetchColumn());
        } finally {
            foreach ($avant as $cle => $valeur) {
                $valeur === false ? putenv($cle) : putenv("$cle=$valeur");
            }
        }
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

    public function fiche(int $idDocument): ?array
    {
        return null;
    }

    public function lister(): array
    {
        return [];
    }

    public function cheminAbsolu(string $cheminRelatif): string
    {
        return $cheminRelatif;
    }
}
