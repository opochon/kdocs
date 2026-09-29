<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Base;
use Ged\Config;
use Ged\Contrats\Depot;
use Ged\Socle\DepotFichiers;
use Ged\Workflows\Fonds;
use Ged\Workflows\HorlogeFigee;
use Ged\Workflows\Moteur;
use PDO;

/**
 * Socle commun des oracles du secteur Workflows : base gedv2_test remise à neuf (tables du secteur seulement),
 * fonds documentaire éphémère hors du dépôt, horloge figée. Aucune donnée réelle.
 */
trait SupportWorkflows
{
    private Config $config;
    private PDO $pdo;
    private string $racine;                    // bac à sable disque du test
    private string $storageRootAvant;
    private HorlogeFigee $horloge;

    protected function setUpWorkflows(string $instant = '2026-04-01 08:00:00'): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);

        // Tables du secteur Workflows uniquement : aucune table d'un autre secteur n'est effacée.
        $this->pdo->exec('DROP TABLE IF EXISTS workflow_journal, workflow_instances, workflow_definitions');
        \Ged\Socle\Schema::installer($this->pdo);        // idempotent : documents, comptes…
        \Ged\Workflows\Schema::installer($this->pdo);

        $this->storageRootAvant = getenv('STORAGE_ROOT');
        $this->racine = sys_get_temp_dir() . '/gedv2-workflows-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');

        $this->horloge = new HorlogeFigee($instant);
    }

    protected function tearDownWorkflows(): void
    {
        $this->storageRootAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageRootAvant);
        $this->supprimer($this->racine);
    }

    /** Dépose une pièce synthétique dans le fonds de test et rend son identifiant d'index. */
    protected function deposer(string $nom, array $meta, string $contenu = 'contenu synthetique'): int
    {
        $source = $this->racine . '/depots/' . $nom;
        file_put_contents($source, $contenu);
        // L'émetteur unique évite toute collision de chemin d'index avec les autres tests (base partagée).
        $meta['emetteur'] = (string) ($meta['emetteur'] ?? 'Émetteur Fictif') . ' ' . bin2hex(random_bytes(3));

        return (new DepotFichiers($this->config))->deposer($source, $meta);
    }

    /** Un moteur branché sur la base et le fonds de test (dépôt réel, horloge figée). */
    protected function moteur(?Depot $depot = null): Moteur
    {
        return new Moteur($this->pdo, $depot ?? new DepotFichiers($this->config), $this->horloge);
    }

    protected function fonds(): Fonds
    {
        return Fonds::depuis($this->config, $this->pdo);
    }

    /**
     * Fiche de document telle que le Socle la passera au moteur (règle ou dépôt).
     *
     * @param array<string, mixed> $surcharge
     * @return array<string, mixed>
     */
    protected function fiche(int $id, array $surcharge = []): array
    {
        return array_merge([
            'id' => $id,
            'titre' => 'Facture AN-7001',
            'type' => 'Facture',
            'emetteur' => 'Atelier Fictif Exemple Sàrl',
            'date' => '2026-03-01',
            'montant' => 12000.0,
            'etiquettes' => [],
            'texte' => 'Facture AN-7001 — prestation fictive',
            'echeance' => null,
            'meta' => [],
        ], $surcharge);
    }

    /** Empreinte sha256 de tout le fonds (chemins relatifs + contenus), dans l'ordre. */
    protected function empreinteFonds(): string
    {
        $racine = getenv('STORAGE_ROOT') ?: '';
        $lignes = [];
        if (is_dir($racine)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                    static fn (string $chemin): bool => !str_starts_with(basename($chemin), '.')
                )
            );
            foreach ($it as $chemin) {
                if (is_file($chemin)) {
                    $lignes[] = str_replace('\\', '/', substr($chemin, strlen($racine) + 1)) . ':' . hash_file('sha256', $chemin);
                }
            }
        }
        sort($lignes);

        return hash('sha256', implode("\n", $lignes));
    }

    /** Empreinte sha256 du contenu de TOUTE la base de test, pour prouver l'absence d'écriture. */
    protected function empreinteBase(): string
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $lignes = [];
        foreach ($tables as $table) {
            $lignes[] = '## ' . $table;
            foreach ($this->pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
                $lignes[] = (string) json_encode($ligne);
            }
        }

        return hash('sha256', implode("\n", $lignes));
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
