<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\DepotFichiers;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-01 §2 : Depot::deposer range le fichier (année/type/émetteur), écrit le `.meta.json` à côté,
 * crée la version 1, indexe — et mettreAJour déplace et trace. Portage prouvé de GEDv1
 * (StoragePathGenerator + FilesystemIndexer), bug des extensions bureautiques corrigé.
 */
final class RangementTest extends TestCase
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

    public function testDepotRangeFichierMetaEtVersion1(): void
    {
        $source = $this->pieceADeposer('facture-an-5001.pdf', '%PDF-1.4 facture AN-5001');
        $id = $this->depot->deposer($source, [
            'titre' => 'Facture AN-5001', 'type' => 'facture', 'emetteur' => 'Atelier X',
            'date' => '2026-09-29', 'montant' => 512.4, 'etiquettes' => ['urgent'], 'source' => 'ecran',
            'origine' => ['lot' => 7, 'pages' => '1-2'],
        ]);

        $rel = '2026/facture/Atelier_X/Facture_AN-5001.pdf';
        self::assertGreaterThan(0, $id);
        self::assertFileExists($this->racine . '/fonds/' . $rel, 'le fichier est rangé année/type/émetteur');
        self::assertFileExists($source, 'le fichier source n’est jamais supprimé par le dépôt');
        self::assertSame('%PDF-1.4 facture AN-5001', file_get_contents($this->racine . '/fonds/' . $rel));

        $meta = $this->meta($rel);
        self::assertSame('Facture AN-5001', $meta['titre']);
        self::assertSame('facture', $meta['type']);
        self::assertSame(['urgent'], $meta['etiquettes']);
        self::assertSame(['lot' => 7, 'pages' => '1-2'], $meta['origine'], 'les clés du secteur appelant passent telles quelles');

        self::assertSame(['v001_' . substr(hash('sha256', '%PDF-1.4 facture AN-5001'), 0, 8) . '.pdf'], $this->versions($rel),
            'la version 1 est archivée dès le dépôt');
        self::assertStringContainsString('facture AN-5001', (string) file_get_contents(
            dirname($this->racine . '/fonds/' . $rel) . '/.versions/' . basename($rel) . '/' . $this->versions($rel)[0]));

        $ligne = $this->pdo->query('SELECT * FROM documents WHERE id = ' . $id)->fetch();
        self::assertSame($rel, $ligne['chemin_relatif']);
        self::assertSame(hash('sha256', '%PDF-1.4 facture AN-5001'), $ligne['empreinte']);
        self::assertSame('2026-09-29', $ligne['date_document']);
        self::assertSame('512.40', $ligne['montant']);
        self::assertNull($ligne['supprime_le'], 'un dépôt n’est jamais à la corbeille');
    }

    public function testSansTypeLeDepotVaAClasser(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('note.pdf', 'note sans type'), [
            'titre' => 'Note sans type', 'date' => '2026-01-15',
        ]);
        $ligne = $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetch();
        self::assertSame('2026/A_classer/Note_sans_type.pdf', $ligne['chemin_relatif']);
    }

    public function testDateAbsenteNePrendJamaisLAnneeCouranteEtBruitAutomatiqueNeCreePasDeDossier(): void
    {
        $idSansDate = $this->depot->deposer($this->pieceADeposer('sans-date.pdf', 'sans date'), [
            'titre' => 'Pièce sans date', 'type' => 'Facture', 'emetteur' => 'Atelier Exemple SA',
        ]);
        $idBruit = $this->depot->deposer($this->pieceADeposer('bruit.pdf', 'bruit'), [
            'titre' => 'Pièce OCR à contrôler', 'type' => 'Contrat',
            'emetteur' => 'Si la banque exerce son droit contractuel', 'statut' => 'a_controler',
            'classement' => ['source' => 'regle'],
        ]);
        $chemins = $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id IN (' . $idSansDate . ',' . $idBruit . ') ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame('Sans_date/Facture/Atelier_Exemple_SA/Pièce_sans_date.pdf', $chemins[0]);
        self::assertSame('Sans_date/Contrat/Pièce_OCR_à_contrôler.pdf', $chemins[1]);
    }

    public function testUnEmetteurNeCreJamaisDeuxNiveaux(): void
    {
        // Le cas réel GEDv1 : « ISELI ENERGIE AG / ISELI ENERGIE SA » créait deux niveaux de dossiers.
        $this->depot->deposer($this->pieceADeposer('a.pdf', 'a'), [
            'titre' => 'A', 'type' => 'facture', 'emetteur' => 'ISELI ENERGIE AG / ISELI SA', 'date' => '2026-02-02',
        ]);
        $this->depot->deposer($this->pieceADeposer('b.pdf', 'b'), [
            'titre' => 'B', 'type' => 'facture', 'emetteur' => 'Photo 2000 S.A.', 'date' => '2026-02-02',
        ]);
        $this->depot->deposer($this->pieceADeposer('c.pdf', 'c'), [
            'titre' => 'C', 'type' => 'facture', 'emetteur' => 'CON', 'date' => '2026-02-02',
        ]);
        $chemins = $this->pdo->query('SELECT chemin_relatif FROM documents ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame('2026/facture/ISELI_ENERGIE_AG___ISELI_SA/A.pdf', $chemins[0], 'le séparateur devient un segment unique');
        self::assertSame('2026/facture/Photo_2000_S.A/B.pdf', $chemins[1], 'jamais de point final (Windows le refuse)');
        self::assertSame('2026/facture/CON_/C.pdf', $chemins[2], 'nom réservé Windows suffixé');
    }

    public function testDeuxDepotsDeMemeNomDansLeMemeDossierSeDistinguent(): void
    {
        foreach (['première', 'deuxième'] as $i => $contenu) {
            $this->depot->deposer($this->pieceADeposer("facture-$i.pdf", $contenu), [
                'titre' => 'Facture dupliquée', 'type' => 'facture', 'emetteur' => 'Atelier X', 'date' => '2026-03-03',
            ]);
        }
        $chemins = $this->pdo->query('SELECT chemin_relatif FROM documents ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame('2026/facture/Atelier_X/Facture_dupliquée.pdf', $chemins[0], 'les accents passent (NTFS est en Unicode)');
        self::assertSame('2026/facture/Atelier_X/Facture_dupliquée-2.pdf', $chemins[1]);
    }

    public function testExtensionsBureautiquesSontReconnues(): void
    {
        // Le trou de GEDv1 : xls, xlsx, odt, rtf n’étaient jamais indexés. Ici ils ont leur MIME.
        $attendus = [
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'rtf' => 'application/rtf',
        ];
        foreach ($attendus as $ext => $mime) {
            $id = $this->depot->deposer($this->pieceADeposer("table.$ext", "contenu $ext"), ['titre' => "Table $ext"]);
            $ligne = $this->pdo->query('SELECT mime FROM documents WHERE id = ' . $id)->fetch();
            self::assertSame($mime, $ligne['mime'], "MIME de $ext");
        }
    }

    public function testMiseAJourDeplaceFichierMetaVersionsEtTrace(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('contrat.pdf', 'contrat v1'), [
            'titre' => 'Contrat maintenance', 'type' => 'facture', 'emetteur' => 'Atelier X', 'date' => '2026-04-04',
        ]);
        $avant = '2026/facture/Atelier_X/Contrat_maintenance.pdf';

        $this->depot->mettreAJour($id, ['type' => 'contrat', 'titre' => 'Contrat maintenance'], 'olivia');

        $apres = '2026/contrat/Atelier_X/Contrat_maintenance.pdf';
        self::assertFileDoesNotExist($this->racine . '/fonds/' . $avant);
        self::assertFileExists($this->racine . '/fonds/' . $apres, 'le fichier suit son nouveau classement');
        self::assertFileExists($this->racine . '/fonds/' . $apres . '.meta.json', 'les métadonnées suivent');
        self::assertSame(['v001_' . substr(hash('sha256', 'contrat v1'), 0, 8) . '.pdf'], $this->versions($apres),
            'les versions suivent le déplacement');

        $trace = $this->pdo->query('SELECT * FROM document_deplacements WHERE document_id = ' . $id)->fetch();
        self::assertSame($avant, $trace['chemin_avant']);
        self::assertSame($apres, $trace['chemin_apres']);
        self::assertSame('olivia', $trace['auteur']);
        self::assertSame('contrat', $this->meta($apres)['type']);
    }

    public function testMiseAJourDuSeulTitreNeDeplacePas(): void
    {
        $id = $this->depot->deposer($this->pieceADeposer('f.pdf', 'f'), [
            'titre' => 'Ancien titre', 'type' => 'facture', 'emetteur' => 'Atelier X', 'date' => '2026-05-05',
        ]);
        $this->depot->mettreAJour($id, ['titre' => 'Nouveau titre'], 'olivia');
        // Le nom du fichier est fixé au dépôt : un titre changé vit dans le .meta.json, le document ne bouge pas.
        self::assertSame('2026/facture/Atelier_X/Ancien_titre.pdf',
            $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn());
        self::assertSame('Nouveau titre', $this->meta('2026/facture/Atelier_X/Ancien_titre.pdf')['titre']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM document_deplacements')->fetchColumn(),
            'un titre qui ne change pas le rangement ne trace pas de déplacement');
    }

    public function testDepotDunFichierAbsentEstUneErreurNommee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dépôt');
        $this->depot->deposer($this->racine . '/nulle/part.pdf', ['titre' => 'Invisible']);
    }
}
