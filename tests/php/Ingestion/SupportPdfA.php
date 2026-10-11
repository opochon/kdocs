<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Fabrique;
use Ged\Socle\DepotFichiers;
use PDO;

/**
 * Socle commun des oracles de la copie d'archivage PDF/A (lot ged-pdfa-1, secteur Ingestion).
 *
 * Base gedv2_test uniquement, fonds éphémère hors du dépôt, corpus 100 % synthétique. Le trait N'EFFACE
 * aucune table d'un autre secteur : il installe (idempotent) les schémas utiles et nettoie seulement les
 * lignes de documents qu'il a lui-même posées.
 */
trait SupportPdfA
{
    private Config $config;
    private PDO $pdo;
    private string $bac;                 // bac à sable disque du test
    private string $racine;              // racine documentaire (STORAGE_ROOT)
    private string|false $storageRootAvant;
    /** @var list<int> identifiants de documents posés par le test, nettoyés au tearDown */
    private array $documentsPoses = [];

    protected function setUpPdfA(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        \Ged\Socle\Schema::installer($this->pdo);
        // Tables du SECTEUR Ingestion : remises à neuf comme le fait déjà PiloteTest, pour qu'un reste d'un
        // run précédent (empreinte identique) ne fasse pas passer un fichier neuf pour un doublon.
        $this->pdo->exec('DROP TABLE IF EXISTS ingestion_travaux, ingestion_messages');
        \Ged\Ingestion\Schema::installer($this->pdo);

        $this->storageRootAvant = getenv('STORAGE_ROOT');
        $this->bac = sys_get_temp_dir() . '/gedv2-pdfa-' . bin2hex(random_bytes(4));
        $this->racine = $this->bac . '/fonds';
        mkdir($this->bac . '/depots', 0775, true);
        mkdir($this->bac . '/entree', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine);
    }

    protected function tearDownPdfA(): void
    {
        foreach ($this->documentsPoses as $id) {
            $this->pdo->exec('DELETE FROM document_deplacements WHERE document_id = ' . $id);
            $this->pdo->exec('DELETE FROM document_versions WHERE document_id = ' . $id);
            $this->pdo->exec('DELETE FROM documents WHERE id = ' . $id);
        }
        $this->documentsPoses = [];
        $this->storageRootAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageRootAvant);
        $this->supprimerBac($this->bac);
    }

    protected function configPdfA(): Config
    {
        return $this->config;
    }

    protected function fondsPdfA(): string
    {
        return $this->racine;
    }

    protected function bacPdfA(): string
    {
        return $this->bac;
    }

    protected function depotPdfA(): DepotFichiers
    {
        return new DepotFichiers($this->config);
    }

    /** Chemin absolu d'un document du fonds éphémère, sous son identifiant d'index. */
    protected function absoluPdfA(int $idDocument): string
    {
        $relatif = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $idDocument)->fetchColumn();

        return $this->racine . '/' . $relatif;
    }

    /** Chemin relatif (fonds) d'un document sous son identifiant d'index. */
    protected function relatifPdfA(int $idDocument): string
    {
        return (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $idDocument)->fetchColumn();
    }

    /**
     * Dépose un fichier synthétique dans le fonds éphémère et retient son identifiant pour le nettoyage.
     *
     * @param array<string, mixed> $meta
     */
    protected function deposerPdfA(string $nom, string $contenu, array $meta = []): int
    {
        $meta['titre'] ??= pathinfo($nom, PATHINFO_FILENAME);
        $meta['emetteur'] = (string) ($meta['emetteur'] ?? 'Atelier Fictif SA') . ' ' . bin2hex(random_bytes(3));
        $source = $this->bac . '/depots/' . $nom;
        file_put_contents($source, $contenu);
        $id = $this->depotPdfA()->deposer($source, $meta);
        $this->documentsPoses[] = $id;

        return $id;
    }

    /** @return list<int> */
    protected function identifiantsPosesPdfA(): array
    {
        return $this->documentsPoses;
    }

    protected function pdoPdfA(): PDO
    {
        return $this->pdo;
    }

    /** Retient un document rangé par un autre chemin que `deposerPdfA` (ex. le Pilote) pour le nettoyage. */
    protected function retenirDocumentPdfA(int $idDocument): void
    {
        $this->documentsPoses[] = $idDocument;
    }

    protected function ghostscriptPdfA(): ?string
    {
        $gs = \Ged\Apercu\Outils::ghostscript();
        if ($gs === null) {
            self::markTestSkipped('Ghostscript absent de ce poste : la copie PDF/A est testée sur le poste de recette.');
        }

        return $gs;
    }

    /**
     * Un PDF texte synthétique d'une ou plusieurs pages, avec un marqueur lisible.
     * Générateur minimal indépendant du service testé (le corpus ne doit pas dépendre du code jugé).
     */
    protected function pdfTextePdfA(string $fichier, string $marqueur, int $pages = 1): void
    {
        $objets = [];
        $kids = [];
        $numero = 3;
        for ($i = 0; $i < $pages; $i++) {
            $page = $numero++;
            $contenu = $numero++;
            $kids[] = "$page 0 R";
            $flux = "BT /F1 18 Tf 60 780 Td (" . self::echappePdf($marqueur . ' - page ' . ($i + 1)) . ") Tj ET\n";
            $objets[$contenu] = "<< /Length " . strlen($flux) . " >>\nstream\n$flux\nendstream";
            $objets[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                . "/Resources << /Font << /F1 9 0 R >> >> /Contents $contenu 0 R >>";
        }
        $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objets[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pages . ' >>';
        $objets[9] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objets);
        $this->ecrirePdfMinimal($fichier, $objets);
    }

    /** Une image JPEG synthétique (GD), sans texte. */
    protected function imageJpegPdfA(string $fichier): void
    {
        $img = imagecreatetruecolor(600, 800);
        $blanc = imagecolorallocate($img, 255, 255, 255);
        $encre = imagecolorallocate($img, 30, 40, 60);
        imagefill($img, 0, 0, $blanc);
        imagestring($img, 5, 40, 40, 'RECU FICTIF', $encre);
        imagestring($img, 4, 40, 80, 'Document synthetique de test', $encre);
        imagejpeg($img, $fichier, 85);
        imagedestroy($img);
    }

    /** Un PDF « scanné » : une page, une image JPEG plein cadre, AUCUN texte. */
    protected function pdfScannePdfA(string $fichier): void
    {
        $jpegFichier = $this->bac . '/depots/scan-source.jpg';
        $this->imageJpegPdfA($jpegFichier);
        $jpeg = (string) file_get_contents($jpegFichier);
        [$w, $h] = [600, 800];
        $objets = [];
        $objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objets[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objets[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] "
            . '/Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>';
        $objets[4] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB "
            . '/BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
        $contenu = "q $w 0 0 $h 0 0 cm /Im0 Do Q";
        $objets[5] = "<< /Length " . strlen($contenu) . " >>\nstream\n$contenu\nendstream";
        $this->ecrirePdfMinimal($fichier, $objets);
    }

    /** Un PDF corrompu : en-tête valide, contenu illisible. */
    protected function pdfCorrompuPdfA(string $fichier): void
    {
        file_put_contents($fichier, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "ceci n'est pas un PDF valide : xref absente et flux tronque\n%%EOF\n");
    }

    /** Un vrai PDF chiffré (mot de passe utilisateur non vide), via Ghostscript. */
    protected function pdfChiffrePdfA(string $fichier, string $source): void
    {
        $gs = $this->ghostscriptPdfA();
        $temporaire = $this->bac . '/depots/clair.pdf';
        $this->pdfTextePdfA($temporaire, 'DOCUMENT SYNTHETIQUE CHIFFRE', 1);
        $commande = [$gs, '-dBATCH', '-dNOPAUSE', '-sDEVICE=pdfwrite', '-sOwnerPassword=owner-synthetique',
            '-sUserPassword=user-synthetique', '-dEncryptionR=3', '-dKeyLength=128',
            '-sOutputFile=' . $fichier, $temporaire];
        $processus = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux);
        if (!is_resource($processus)) {
            self::markTestSkipped('Ghostscript indisponible pour fabriquer un PDF chiffré.');
        }
        stream_get_contents($tuyaux[1]);
        stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);
        proc_close($processus);
        self::assertFileExists($fichier, 'le PDF chiffré de test doit avoir été produit');
        self::assertStringContainsString('/Encrypt', (string) file_get_contents($fichier), 'le PDF de test doit être chiffré');
    }

    /** Texte extrait par Ghostscript (txtwrite) : prouve que la couche texte du PDF est conservée. */
    protected function texteExtraitPdfA(string $pdf): string
    {
        $gs = $this->ghostscriptPdfA();
        $sortie = $this->bac . '/texte-' . bin2hex(random_bytes(4)) . '.txt';
        $commande = [$gs, '-q', '-dBATCH', '-dNOPAUSE', '-sDEVICE=txtwrite', '-sOutputFile=' . $sortie, $pdf];
        $processus = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux);
        if (!is_resource($processus)) {
            return '';
        }
        stream_get_contents($tuyaux[1]);
        stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);
        proc_close($processus);

        return is_file($sortie) ? (string) file_get_contents($sortie) : '';
    }

    /**
     * Écrit un PDF minimal à partir d'objets numérotés (générateur de test, jamais utilisé par le service).
     *
     * @param array<int, string> $objets
     */
    private function ecrirePdfMinimal(string $fichier, array $objets): void
    {
        $dossier = dirname($fichier);
        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        ksort($objets);
        $max = max(array_keys($objets));
        $sortie = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objets as $n => $corps) {
            $offsets[$n] = strlen($sortie);
            $sortie .= "$n 0 obj\n$corps\nendobj\n";
        }
        $xref = strlen($sortie);
        $sortie .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) {
            $sortie .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
        }
        $sortie .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        file_put_contents($fichier, $sortie);
    }

    private static function echappePdf(string $texte): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texte);
    }

    private function supprimerBac(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($chemin);
    }
}
