<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Migrations;
use Ged\Socle\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Migrations versionnées du secteur Socle : montée idempotente et retour non destructif sur gedv2_test.
 * Le retour est REFUSÉ dès qu'un partage viserait une version précise : la trace ne disparaît pas en silence.
 */
final class MigrationModeleTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        $this->pdo->exec('DROP TABLE IF EXISTS document_collaboration');   // base de TEST uniquement
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS document_collaboration');
        Schema::installer($this->pdo);   // état final : schéma neuf, comme après l'installateur
    }

    public function testMonteEtRedescendLaVersionPartagee(): void
    {
        $this->tableV1();
        self::assertSame(1, Migrations::version($this->pdo), 'table de base = version 1');
        self::assertFalse(Migrations::colonneExiste($this->pdo, 'document_collaboration', 'version'));

        Migrations::monter($this->pdo);
        self::assertSame(2, Migrations::version($this->pdo));
        self::assertTrue(Migrations::colonneExiste($this->pdo, 'document_collaboration', 'version'));
        Migrations::monter($this->pdo);   // idempotent
        self::assertSame(2, Migrations::version($this->pdo));

        Migrations::retour($this->pdo, 1);
        self::assertSame(1, Migrations::version($this->pdo));
        self::assertFalse(Migrations::colonneExiste($this->pdo, 'document_collaboration', 'version'));
    }

    public function testLeRetourRefuseDePerdreUnPartageVersionne(): void
    {
        $this->tableV1();
        Migrations::monter($this->pdo);
        $this->pdo->exec("INSERT INTO document_collaboration (document_id, type, version) VALUES (1, 'partage', 2)");

        try {
            Migrations::retour($this->pdo, 1);
            self::fail('le retour devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('trace', $e->getMessage());
        }
        self::assertTrue(Migrations::colonneExiste($this->pdo, 'document_collaboration', 'version'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM document_collaboration')->fetchColumn());
    }

    public function testUnRetourVersUneVersionInconnueEstRefuse(): void
    {
        $this->tableV1();
        Migrations::monter($this->pdo);

        $this->expectException(\RuntimeException::class);
        Migrations::retour($this->pdo, 7);
    }

    public function testUneBaseSansTableEstEnVersionZero(): void
    {
        self::assertSame(0, Migrations::version($this->pdo));
        Migrations::monter($this->pdo);
        self::assertSame(2, Migrations::version($this->pdo));
    }

    private function tableV1(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE document_collaboration (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              document_id INT UNSIGNED NOT NULL,
              type VARCHAR(20) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
