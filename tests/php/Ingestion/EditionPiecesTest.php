<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Contrats\Classeur;
use Ged\Ingestion\EditeurPdf;
use Ged\Ingestion\EditionPieces;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Socle\SupportCorpus;
use PHPUnit\Framework\TestCase;

/** Oracle V2-12a : un lot synthétique garde chaque page exactement une fois à chaque geste. */
final class EditionPiecesTest extends TestCase
{
    use SupportCorpus;

    private DepotFichiers $depot;
    private EditionPieces $edition;
    private EditeurPdf $pdf;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        $this->pdf = EditeurPdf::depuisConfig($this->config);
        \Ged\Classement\Schema::installer($this->pdo);
        $this->depot = new DepotFichiers($this->config);
        $this->edition = new EditionPieces($this->config, $this->depot, new class implements Classeur {
            public function proposer(string $texte, array $analyse, string $nomFichier): array
            {
                return ['titre' => 'Courrier synthétique', 'type' => 'Courrier', 'emetteur' => 'Papeterie Fictive SA',
                    'date' => '2026-10-02', 'montant' => null, 'etiquettes' => [], 'confiance' => 0.4,
                    'source' => 'regle', 'preuve' => 'oracle', 'valide' => false, 'raison' => 'à contrôler'];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    public function testLeParcoursEditionConserveLesPagesEtAnnuleLaDerniereOperation(): void
    {
        $source = $this->racine . '/depots/lot-six-pages.pdf';
        $this->pdf($source, 6);
        $id = $this->depot->deposer($source, [
            'titre' => 'Courrier synthétique', 'type' => 'Courrier', 'date' => '2026-10-02',
            'statut' => 'a_controler', 'texte' => 'Courrier de test', 'origine' => ['lot' => 71, 'pages' => '1-6'],
        ]);

        $this->edition->tourner($id, 3, 90, 'operatrice');
        self::assertSame(90, $this->rotation($this->chemin($id), 3));

        $droite = $this->edition->scinder($id, 3, 'operatrice');
        self::assertSame(3, $this->pages($this->chemin($id)));
        self::assertSame(3, $this->pages($this->chemin($droite)));
        self::assertSame([1, 2, 3, 4, 5, 6], $this->pagesVivantes(71));

        $this->edition->fusionner($id, $droite, 'operatrice');
        self::assertSame(6, $this->pages($this->chemin($id)));
        self::assertNotNull($this->depot->fiche($droite)['supprime_le']);
        self::assertSame([1, 2, 3, 4, 5, 6], $this->pagesVivantes(71));

        $blanche = $this->edition->retirer($id, 6, 'operatrice');
        self::assertSame(5, $this->pages($this->chemin($id)));
        self::assertNotNull($this->depot->fiche($blanche)['supprime_le'], 'la page retirée va dans une corbeille récupérable');

        $this->edition->annuler($id, 'operatrice');
        self::assertSame(6, $this->pages($this->chemin($id)));
        self::assertSame([1, 2, 3, 4, 5, 6], $this->pagesVivantes(71));
        self::assertNotEmpty($this->depot->fiche($id)['meta']['historique_pages'] ?? []);

        // Les annulations remontent une pile : fusion puis scission, sans remettre de page en double.
        $this->edition->annuler($id, 'operatrice');
        $this->edition->annuler($id, 'operatrice');
        self::assertSame(6, $this->pages($this->chemin($id)));
        self::assertNotNull($this->depot->fiche($droite)['supprime_le']);
        self::assertSame([1, 2, 3, 4, 5, 6], $this->pagesVivantes(71));

        $this->depot->mettreAJour($id, ['statut' => 'valide'], 'operatrice');
        $this->expectExceptionMessage('déjà validée');
        $this->edition->tourner($id, 1, 90, 'operatrice');
    }

    private function chemin(int $id): string
    {
        return $this->depot->cheminAbsolu((string) $this->depot->fiche($id)['chemin_relatif']);
    }

    /** @return list<int> */
    private function pagesVivantes(int $lot): array
    {
        $pages = [];
        foreach ($this->depot->lister() as $ligne) {
            $meta = json_decode((string) $ligne['meta'], true) ?: [];
            if ((int) ($meta['origine']['lot'] ?? 0) === $lot) {
                $pages = array_merge($pages, (array) ($meta['origine']['pages_liste'] ?? []));
            }
        }
        sort($pages);

        return array_map('intval', $pages);
    }

    private function pdf(string $fichier, int $pages): void
    {
        $script = "import fitz\n"
            . "doc=fitz.open()\n"
            . '[doc.new_page(width=595,height=842) for _ in range(' . $pages . ")]\n"
            . "doc.save(r'" . str_replace('\\', '\\\\', $fichier) . "')\n";
        $processus = proc_open([$this->config->get('CMD4_PYTHON'), '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($processus);
        fclose($pipes[1]);
        $erreur = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($processus), $erreur);
    }

    private function pages(string $fichier): int
    {
        return (int) ($this->pdf->executer(['action' => 'count', 'source' => $fichier])['pages'] ?? 0);
    }

    private function rotation(string $fichier, int $page): int
    {
        return (int) ($this->pdf->executer(['action' => 'rotation', 'source' => $fichier, 'page' => $page])['rotation'] ?? -1);
    }
}
