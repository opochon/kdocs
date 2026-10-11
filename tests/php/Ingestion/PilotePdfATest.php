<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Ingestion\CopieArchivage;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Entree;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Source;
use Ged\Ingestion\Travaux;
use Ged\Socle\DepotFichiers;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Branchement de la copie d'archivage PDF/A sur la chaîne d'ingestion RÉELLE (dossier et adresse passent
 * par le même `Pilote::ranger`) : après le rangement d'une pièce, la copie est produite à côté ; un échec
 * de copie ne perd jamais le document.
 */
final class PilotePdfATest extends TestCase
{
    use SupportPdfA;

    protected function setUp(): void
    {
        $this->setUpPdfA();
    }

    protected function tearDown(): void
    {
        $this->tearDownPdfA();
    }

    public function testUnePieceRangeeRecoitSaCopiePdfAApresLeDepot(): void
    {
        $this->ghostscriptPdfA();
        $source = $this->bacPdfA() . '/entree/lot.pdf';
        $this->pdfTextePdfA($source, 'INGESTION AVEC COPIE PDFA', 1);

        $id = $this->jouerPilote($source, false);

        $absolu = $this->absoluPdfA($id);
        self::assertFileExists($absolu);
        $copie = CopieArchivage::cheminCopie($absolu);
        self::assertFileExists($copie, 'la pièce rangée reçoit sa copie PDF/A à côté');
        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true);
        self::assertIsArray($meta);
        self::assertSame('ok', $meta['pdfa']['statut'] ?? null, 'le .meta.json enrichi porte le statut de la copie');
        self::assertStringContainsString('.pdfa/', (string) ($meta['pdfa']['chemin'] ?? ''));
    }

    public function testUneCopieEnEchecLaisseLeDocumentIngereEtVisible(): void
    {
        $this->ghostscriptPdfA();
        $id = $this->jouerPilote(null, true);

        $absolu = $this->absoluPdfA($id);
        self::assertFileExists($absolu, 'le document est ingéré même si sa copie échoue');
        self::assertFileDoesNotExist(CopieArchivage::cheminCopie($absolu), 'aucune copie partielle');
        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true);
        self::assertSame('echec', $meta['pdfa']['statut'] ?? null, 'l’échec est nommé dans la fiche');
        self::assertNotSame('', (string) ($meta['pdfa']['raison'] ?? ''));
    }

    /**
     * Joue un tic d'ingestion complet avec un découpeur qui rend UNE pièce : le PDF d'entrée, ou un PDF
     * corrompu. Rend l'identifiant du document rangé.
     */
    private function jouerPilote(?string $sourcePdf, bool $corrompu): int
    {
        $avant = array_map('intval', $this->pdoPdfA()->query('SELECT id FROM documents')->fetchAll(PDO::FETCH_COLUMN));
        $nom = 'ingestion-pdfa-' . bin2hex(random_bytes(4)) . '.pdf';
        $lot = $this->bacPdfA() . '/entree/' . $nom;
        if ($corrompu) {
            $this->pdfCorrompuPdfA($lot);
        } else {
            self::assertNotNull($sourcePdf);
            copy($sourcePdf, $lot);
        }

        $source = new class ($lot, $nom) implements Source {
            private bool $fait = false;

            public function __construct(private readonly string $fichier, private readonly string $nom)
            {
            }

            public function relever(): array
            {
                if ($this->fait) {
                    return [];
                }
                $this->fait = true;

                return [new Entree($this->fichier, 'dossier', $this->nom)];
            }
        };
        $decoupeur = new class implements Decoupeur {
            public function decouper(string $fichier, string $dossierSortie): array
            {
                @mkdir($dossierSortie, 0775, true);
                $piece = $dossierSortie . '/piece1.pdf';
                copy($fichier, $piece);

                return [['fichier' => $piece, 'pages' => [1], 'texte' => 'texte ingere', 'analyse' => []]];
            }
        };
        $pilote = new Pilote(new Travaux($this->pdoPdfA()), [$source], $decoupeur,
            new DepotFichiers($this->configPdfA()), null, $this->bacPdfA() . '/travail', $this->configPdfA());
        $pilote->tic(5);   // stabilité de la source (comme le dossier surveillé)
        $pilote->tic(5);   // reçu et découpé
        $pilote->tic(5);   // rangé (+ copie)

        $apres = array_map('intval', $this->pdoPdfA()->query('SELECT id FROM documents')->fetchAll(PDO::FETCH_COLUMN));
        $nouveaux = array_values(array_diff($apres, $avant));
        if ($nouveaux === []) {
            $travaux = $this->pdoPdfA()->query('SELECT id, parent_id, etat, essais, erreur, nom_origine, chemin FROM ingestion_travaux')->fetchAll(PDO::FETCH_ASSOC);
            $documents = $this->pdoPdfA()->query('SELECT id, chemin_relatif, supprime_le, absent_depuis FROM documents ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
            self::fail('aucun document rangé : ' . json_encode(['avant' => $avant, 'apres' => $apres, 'travaux' => $travaux, 'documents' => $documents], JSON_UNESCAPED_UNICODE));
        }
        self::assertCount(1, $nouveaux, 'exactement un document rangé');
        $id = (int) $nouveaux[0];
        $this->retenirDocumentPdfA($id);

        return $id;
    }
}
