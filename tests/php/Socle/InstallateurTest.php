<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-01 §1 : `php tools/installer.php gedv2_test --admin=admin@gedv2.test` pose exactement 22 tables,
 * refuse une base peuplée sans --remplacer, et fait alors un dump AVANT de remplacer.
 * La commande réelle est exécutée comme le juge la rejouera.
 */
final class InstallateurTest extends TestCase
{
    /** Décision V2-13 : plafond explicite de 22, journal d'audit des droits inclus. */
    private const TABLES_ATTENDUES = [
        'classement_choix', 'comptes', 'document_collaboration', 'document_deplacements', 'document_versions', 'documents',
        'ingestion_messages', 'ingestion_travaux', 'installation', 'jonctions_jetons', 'recherche_textes',
        'securite_audit', 'securite_dossiers', 'securite_dossiers_droits', 'securite_groupes',
        'securite_groupes_comptes', 'securite_permissions', 'securite_roles', 'securite_roles_permissions',
        'workflow_definitions', 'workflow_instances', 'workflow_journal',
    ];

    private string $depot;        // racine du dépôt
    private string $sauvegardes;  // var/sauvegardes du dépôt
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->depot = dirname(__DIR__, 3);
        $this->sauvegardes = $this->depot . '/var/sauvegardes';
        $config = new \Ged\Config($this->depot);
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'base de test uniquement');
        \Ged\Base::assurerBase($config);
        $this->pdo = \Ged\Base::pdo($config);
        $this->viderBase();   // base de TEST : on repart d'un schéma vierge à chaque cas
    }

    protected function tearDown(): void
    {
        // État final laissé à la suite : une installation fraîche, comme après un vrai installateur.
        $this->viderBase();
        $this->installer('--admin=admin@gedv2.test');
    }

    /** Les tables de TOUT secteur présent, pour repartir d'une base vierge entre chaque cas (base de TEST). */
    private function viderBase(): void
    {
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $this->pdo->exec("DROP TABLE `$table`");
        }
    }

    /** @return array{int, string} code de sortie, sortie complète */
    private function installer(string $args): array
    {
        $commande = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->depot . '/tools/installer.php')
            . ' gedv2_test ' . $args . ' 2>&1';
        exec($commande, $sorties, $code);

        return [$code, implode("\n", $sorties)];
    }

    public function testPoseUnSchemaNeufAvecCompteAdmin(): void
    {
        [$code, $sortie] = $this->installer('--admin=admin@gedv2.test');

        self::assertSame(0, $code, "l’installateur devait réussir : $sortie");
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(self::TABLES_ATTENDUES, $tables, 'le schéma neuf contient exactement les tables des secteurs présents');

        $compte = $this->pdo->query('SELECT identifiant, role FROM comptes')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['identifiant' => 'admin@gedv2.test', 'role' => 'admin'], $compte, 'le compte admin existe, un seul');
        self::assertStringContainsString('admin', $sortie);
    }

    public function testRefuseUneBasePeupleeSansRemplacer(): void
    {
        $this->installer('--admin=admin@gedv2.test');   // base peuplée : le schéma + le compte

        [$code, $sortie] = $this->installer('--admin=autre@gedv2.test');

        self::assertNotSame(0, $code, 'refuser, pas écraser');
        self::assertStringContainsString('peuplée', $sortie, 'l’erreur se nomme');
        self::assertStringContainsString('--remplacer', $sortie, 'et dit la sortie de secours');
        self::assertSame(['admin@gedv2.test'], $this->pdo->query('SELECT identifiant FROM comptes')->fetchAll(\PDO::FETCH_COLUMN),
            'rien n’a changé : ni le compte, ni le schéma');
    }

    public function testRemplacerFaitUnDumpDabordPuisReposeLeSchema(): void
    {
        $this->installer('--admin=admin@gedv2.test');
        $this->pdo->exec("INSERT INTO documents (chemin_relatif, nom, titre, empreinte, taille, mime, meta)"
            . " VALUES ('2026/x.pdf', 'x.pdf', 'Ancien', '" . str_repeat('a', 64) . "', 1, 'application/pdf', '{}')");

        [$code, $sortie] = $this->installer('--admin=admin@gedv2.test --remplacer');

        self::assertSame(0, $code, $sortie);
        self::assertStringContainsString('Dump', $sortie, 'le dump est annoncé dans la sortie');
        $dumps = glob($this->sauvegardes . '/gedv2_test-*.sql') ?: [];
        self::assertNotFalse($dumps, 'un dump existe sous var/sauvegardes/');
        self::assertStringContainsString('Ancien', (string) file_get_contents($dumps[count($dumps) - 1]),
            'le dump contient les données d’avant remplacement');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn(),
            'la base est neuve, l’ancienne ligne a vécu dans le dump');
        self::assertSame(self::TABLES_ATTENDUES, $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN),
            'la réinitialisation repose aussi les tables V2-12d');
        self::assertSame(['admin@gedv2.test'], $this->pdo->query('SELECT identifiant FROM comptes')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testUneBaseInconnueEstRefusee(): void
    {
        $commande = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->depot . '/tools/installer.php')
            . ' kdocs_prod 2>&1';
        exec($commande, $sorties, $code);
        self::assertNotSame(0, $code, 'jamais une autre base que gedv2 et gedv2_test');
        self::assertStringContainsString('gedv2', implode("\n", $sorties));
    }

    public function testLePlafondVingtDeuxTablesEstExplicite(): void
    {
        [$code, $sortie] = $this->installer('--admin=admin@gedv2.test');

        self::assertSame(0, $code, $sortie);
        self::assertCount(22, $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(self::TABLES_ATTENDUES, $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
