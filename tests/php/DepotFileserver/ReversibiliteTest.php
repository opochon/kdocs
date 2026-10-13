<?php

declare(strict_types=1);

namespace Ged\Tests\DepotFileserver;

use Ged\DepotFileserver\Reversibilite;
use PHPUnit\Framework\TestCase;

/**
 * Oracle de la réversibilité : on exporte une arborescence classique, sans aucun élément interne,
 * et on vérifie qu'elle est identique au dépôt. Débrancher la GED laisse le partage utilisable.
 */
final class ReversibiliteTest extends TestCase
{
    private string $fonds;
    private string $export;

    protected function setUp(): void
    {
        $this->fonds = SupportDepot::dossier('reversibilite-fonds');
        $this->export = SupportDepot::dossier('reversibilite-export');
    }

    protected function tearDown(): void
    {
        SupportDepot::supprimer($this->fonds);
        SupportDepot::supprimer($this->export);
    }

    public function testLExportNeContientQueLesDocumentsVisiblesEtEstVerifie(): void
    {
        SupportDepot::ecrire($this->fonds . '/2026/facture.txt', 'facture');
        SupportDepot::ecrire($this->fonds . '/2026/facture.txt.meta.json', '{"titre":"Facture"}');
        SupportDepot::ecrire($this->fonds . '/.depot-fileserver/empreintes.json', '{}');
        SupportDepot::ecrire($this->fonds . '/.facture.txt/v001_deadbeef.txt', 'ancienne');

        $resultat = (new Reversibilite($this->fonds))->exporter($this->export);

        self::assertSame(2, $resultat['exportes']);
        self::assertFileExists($this->export . '/2026/facture.txt');
        self::assertFileExists($this->export . '/2026/facture.txt.meta.json');
        self::assertDirectoryDoesNotExist($this->export . '/.depot-fileserver');
        self::assertDirectoryDoesNotExist($this->export . '/.facture.txt');

        $verification = (new Reversibilite($this->fonds))->verifier($this->export);
        self::assertSame(2, $verification['identiques']);
        self::assertSame([], $verification['ecarts']);
    }

    public function testUnExportAltereEstDetecteAvecLaCause(): void
    {
        SupportDepot::ecrire($this->fonds . '/facture.txt', 'facture');
        (new Reversibilite($this->fonds))->exporter($this->export);
        file_put_contents($this->export . '/facture.txt', 'altere');

        $verification = (new Reversibilite($this->fonds))->verifier($this->export);

        self::assertSame(0, $verification['identiques']);
        self::assertSame(['facture.txt : empreinte différente'], $verification['ecarts']);
    }
}
