<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Socle commun des oracles de la famille M-Files nº 2 (relations) : base gedv2_test remise à neuf pour
 * les tables du Socle, racine documentaire éphémère hors du dépôt (aucune donnée réelle, aucun corpus
 * commité).
 *
 * Différence assumée avec le trait général du Socle : `recherche_textes` n'est PAS supprimée. Cette
 * table est une dépendance d'autres suites (Parite ModeleCourrier) et les tests de cette famille n'en
 * ont pas besoin ; la laisser intacte évite de casser un voisin qui tourne après nous.
 */
trait SupportRelations
{
    private PDO $pdo;
    private string $racine;      // racine documentaire du test (STORAGE_ROOT)
    private Config $config;
    private string|false $storageRootAvant;   // la suite peut imposer STORAGE_ROOT (phpunit.xml) : on la rend

    protected function setUpRelations(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        // Tables du Socle seules (sans `recherche_textes`, dépendance d'autres suites).
        $this->pdo->exec('DROP TABLE IF EXISTS document_collaboration, document_collaboration_evenements, '
            . 'document_notes, document_liens, document_partages, reconstruction_index_jobs, '
            . 'documents, document_versions, document_deplacements');
        Schema::installer($this->pdo);

        $this->storageRootAvant = getenv('STORAGE_ROOT');
        $this->racine = sys_get_temp_dir() . '/gedv2-relations-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');   // Config lit l'environnement d'abord
    }

    protected function tearDownRelations(): void
    {
        $this->storageRootAvant === false ? putenv('STORAGE_ROOT')
            : putenv('STORAGE_ROOT=' . $this->storageRootAvant);
        $this->supprimerRacineRelations($this->racine);
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

    private function supprimerRacineRelations(string $chemin): void
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
