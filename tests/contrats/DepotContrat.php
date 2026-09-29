<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

use Ged\Base;
use Ged\Config;
use Ged\Contrats\Depot;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/** Propriétés minimales de tout dépôt : le fonds prime et l'index ne fait que le décrire. */
abstract class DepotContrat extends TestCase
{
    protected PDO $pdo;
    protected string $tmp;
    private string|false $stockageAvant;

    protected function setUp(): void
    {
        $this->stockageAvant = getenv('STORAGE_ROOT');
        $this->tmp = sys_get_temp_dir() . '/gedv2-contrat-depot-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/sources', 0775, true);
        putenv('STORAGE_ROOT=' . $this->tmp . '/fonds');
        $config = new Config(dirname(__DIR__, 2));
        self::assertSame('gedv2_test', $config->get('DB_NAME'));
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        Schema::installer($this->pdo);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->stockageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->stockageAvant);
        $this->supprimer($this->tmp);
    }

    abstract protected function depot(): Depot;

    public function testDeposerLaisseUnePieceAutoDescriptiveEtIndexee(): void
    {
        $source = $this->tmp . '/sources/facture-synthetique.pdf';
        file_put_contents($source, '%PDF-1.4 contenu synthétique');
        $depot = $this->depot();

        $id = $depot->deposer($source, [
            'titre' => 'Facture synthétique', 'type' => 'Facture', 'emetteur' => 'Atelier Exemple SA',
            'date' => '2026-09-29', 'source' => 'dossier',
        ]);

        self::assertGreaterThan(0, $id);
        self::assertFileExists($source, 'le dépôt ne supprime jamais la source');
        $ligne = $this->pdo->prepare('SELECT chemin_relatif, meta FROM documents WHERE id = ?');
        $ligne->execute([$id]);
        $index = $ligne->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($index, 'le document est indexé');
        self::assertInstanceOf(DepotFichiers::class, $depot, 'implémentation déclarée du contrat');
        $fichier = $this->tmp . '/fonds/' . $index['chemin_relatif'];
        self::assertFileExists($fichier, 'le fichier lisible vit dans le fonds');
        self::assertFileExists($fichier . '.meta.json', 'les métadonnées sont à côté du fichier');
        self::assertSame(json_decode((string) $index['meta'], true), json_decode((string) file_get_contents($fichier . '.meta.json'), true));
        $versions = $this->pdo->prepare('SELECT numero FROM document_versions WHERE document_id = ?');
        $versions->execute([$id]);
        self::assertSame([1], array_map('intval', $versions->fetchAll(PDO::FETCH_COLUMN)), 'la version 1 est posée au dépôt');
    }

    private function supprimer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dossier);
    }
}
