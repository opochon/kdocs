<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Socle commun des oracles du secteur : base gedv2_test remise à neuf, racine documentaire éphémère
 * hors du dépôt (aucune donnée réelle, aucun corpus commité).
 */
trait SupportCorpus
{
    private PDO $pdo;
    private string $racine;      // racine documentaire du test (STORAGE_ROOT)
    private Config $config;
    private string|false $storageRootAvant;   // la suite peut imposer STORAGE_ROOT (phpunit.xml) : on la rend

    protected function setUpCorpus(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        // Tables du Socle seules : les autres secteurs gèrent les leurs (base de TEST uniquement).
        $this->pdo->exec('DROP TABLE IF EXISTS recherche_textes, document_collaboration_evenements, document_notes, document_liens, document_partages, reconstruction_index_jobs, documents, document_versions, document_deplacements');
        Schema::installer($this->pdo);

        $this->storageRootAvant = getenv('STORAGE_ROOT');
        $this->racine = sys_get_temp_dir() . '/gedv2-socle-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');   // Config lit l'environnement d'abord
    }

    protected function tearDownCorpus(): void
    {
        // Restituer la valeur d'avant (la suite phpunit peut l'imposer pour d'autres secteurs).
        $this->storageRootAvant === false ? putenv('STORAGE_ROOT')
            : putenv('STORAGE_ROOT=' . $this->storageRootAvant);
        $this->supprimer($this->racine);
    }

    /** Un fichier source à déposer, hors de la racine documentaire (comme une pièce extraite d'un lot). */
    private function pieceADeposer(string $nom, string $contenu): string
    {
        $f = $this->racine . '/depots/' . $nom;
        file_put_contents($f, $contenu);

        return $f;
    }

    /** Lit et décode le `<fichier>.meta.json` rangé à côté du document. */
    private function meta(string $cheminRel): array
    {
        $f = $this->racine . '/fonds/' . $cheminRel . '.meta.json';
        self::assertFileExists($f, "métadonnées attendues à côté de $cheminRel");

        return json_decode((string) file_get_contents($f), true) ?: [];
    }

    /** Liste les archives d'un document : noms de fichiers dans `.versions/<fichier>/`. */
    private function versions(string $cheminRel): array
    {
        $d = dirname($this->racine . '/fonds/' . $cheminRel) . '/.versions/' . basename($cheminRel);
        $noms = glob($d . '/*') ?: [];
        sort($noms);

        return array_map(static fn (string $f): string => basename($f), $noms);
    }

    private function supprimer(string $chemin): void
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
