<?php

declare(strict_types=1);

namespace Ged\Tests\Archivage;

use Ged\Archivage\HorlogeFigee;
use Ged\Archivage\Retention;
use Ged\Archivage\Scelleur;
use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Indexeur;
use PDO;

/**
 * Socle commun des oracles du secteur Archivage : base gedv2_test (jamais une autre), fonds éphémère hors du
 * dépôt, horloge figée. Aucune donnée réelle, aucun nom de client.
 *
 * Règle de partage : ce socle NE SUPPRIME AUCUNE table. Il installe (idempotent) les schémas dont il dépend et
 * nettoie uniquement les lignes qu'il a lui-même posées. L'indexeur marque `absent_depuis` les lignes hors de son
 * fonds : leurs valeurs sont photographiées puis restaurées, pour ne jamais toucher l'état des autres secteurs.
 */
trait SupportArchivage
{
    private Config $config;
    private PDO $pdo;
    private string $bac;                 // bac à sable disque du test
    private string $racine;              // racine documentaire (STORAGE_ROOT)
    private string|false $storageRootAvant;
    private HorlogeFigee $horloge;
    /** @var list<int> identifiants de documents posés par le test, nettoyés au tearDown */
    private array $documentsPoses = [];

    protected function setUpArchivage(string $instant = '2026-04-01 08:00:00'): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);

        // Installation idempotente : jamais un DROP sur une table d'un autre secteur.
        \Ged\Socle\Schema::installer($this->pdo);
        \Ged\Workflows\Schema::installer($this->pdo);

        $this->storageRootAvant = getenv('STORAGE_ROOT');
        $this->bac = sys_get_temp_dir() . '/gedv2-archivage-' . bin2hex(random_bytes(4));
        $this->racine = $this->bac . '/fonds';
        mkdir($this->bac . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine);

        $this->horloge = new HorlogeFigee($instant);
    }

    protected function tearDownArchivage(): void
    {
        foreach ($this->documentsPoses as $id) {
            $this->pdo->exec('DELETE FROM document_deplacements WHERE document_id = ' . $id);
            $this->pdo->exec('DELETE FROM document_versions WHERE document_id = ' . $id);
            $this->pdo->exec('DELETE FROM documents WHERE id = ' . $id);
        }
        $this->documentsPoses = [];

        $this->storageRootAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageRootAvant);
        $this->supprimer($this->bac);
    }

    protected function horloge(): HorlogeFigee
    {
        return $this->horloge;
    }

    protected function configArchivage(): Config
    {
        return $this->config;
    }

    protected function racineArchivage(): string
    {
        return $this->racine;
    }

    protected function depot(): DepotFichiers
    {
        return new DepotFichiers($this->config);
    }

    protected function retention(): Retention
    {
        return new Retention($this->racine, $this->horloge);
    }

    /**
     * Un scelleur de test : TSA simulée (aucun réseau), horloge figée, dépôt réel du fonds éphémère.
     */
    protected function scelleur(): Scelleur
    {
        return new Scelleur($this->depot(), new \Ged\Archivage\TsaSimulee($this->horloge), $this->horloge, $this->retention());
    }

    /**
     * Dépose une pièce synthétique et retient son identifiant pour le nettoyage.
     *
     * @param array<string, mixed> $meta
     */
    protected function deposer(string $nom, array $meta, string $contenu = 'contenu synthétique'): int
    {
        $meta['emetteur'] = (string) ($meta['emetteur'] ?? 'Émetteur Fictif') . ' ' . bin2hex(random_bytes(3));
        $source = $this->bac . '/depots/' . $nom;
        file_put_contents($source, $contenu);
        $id = $this->depot()->deposer($source, $meta);
        $this->documentsPoses[] = $id;

        return $id;
    }

    /** Chemin absolu du document indexé sous son identifiant. */
    protected function absolu(int $idDocument): string
    {
        $relatif = (string) $this->pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $idDocument)->fetchColumn();

        return $this->racine . '/' . $relatif;
    }

    /** La ligne d'index en base (meta décodé). */
    protected function fiche(int $idDocument): array
    {
        $ligne = $this->pdo->query('SELECT * FROM documents WHERE id = ' . $idDocument)->fetch();
        self::assertIsArray($ligne);
        $ligne['meta'] = json_decode((string) $ligne['meta'], true) ?: [];

        return $ligne;
    }

    /**
     * Passe l'indexeur et rend ses statistiques. Les drapeaux `absent_depuis` / `supprime_le` des lignes
     * étrangères au fonds du test sont restaurés ensuite : le test ne modifie que ses propres documents.
     *
     * @return array<string, mixed>
     */
    protected function indexer(int $budgetS = 20): array
    {
        $avant = $this->pdo->query('SELECT id, absent_depuis, supprime_le, supprime_par FROM documents')->fetchAll();
        $stats = (new Indexeur($this->config))->passer($budgetS);
        $poses = array_flip($this->documentsPoses);   // ids du test : leurs drapeaux peuvent changer
        foreach ($avant as $ligne) {
            if (isset($poses[(int) $ligne['id']])) {
                continue;
            }
            $this->pdo->prepare('UPDATE documents SET absent_depuis = ?, supprime_le = ?, supprime_par = ? WHERE id = ?')
                ->execute([$ligne['absent_depuis'], $ligne['supprime_le'], $ligne['supprime_par'], (int) $ligne['id']]);
        }

        return $stats;
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
