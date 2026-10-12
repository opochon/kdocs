<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion\Courrier;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\ErreurIngestion;
use Ged\Ingestion\Migrations;
use Ged\Ingestion\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Migrations versionnées du journal des courriels traités : montée idempotente et retour non destructif sur
 * gedv2_test. Le retour est REFUSÉ dès qu'une ligne porterait une règle : la trace ne disparaît pas en silence.
 */
final class MigrationCourrierTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 4));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        $this->pdo->exec('DROP TABLE IF EXISTS ingestion_messages');   // base de TEST uniquement
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ingestion_messages');
        Schema::installer($this->pdo);   // état final : schéma neuf, comme après l'installateur
    }

    public function testMonteEtRedescendLeJournal(): void
    {
        $this->tableV1();
        self::assertSame(1, Migrations::version($this->pdo), 'table de base = version 1');
        self::assertFalse(Migrations::colonneExiste($this->pdo, 'ingestion_messages', 'regle'));

        Migrations::monter($this->pdo);
        self::assertSame(2, Migrations::version($this->pdo));
        foreach (Schema::COLONNES_COURRIER as $colonne) {
            self::assertTrue(Migrations::colonneExiste($this->pdo, 'ingestion_messages', $colonne), "colonne $colonne");
        }
        Migrations::monter($this->pdo);   // idempotent
        self::assertSame(2, Migrations::version($this->pdo));

        Migrations::retour($this->pdo, 1);
        self::assertSame(1, Migrations::version($this->pdo));
        self::assertFalse(Migrations::colonneExiste($this->pdo, 'ingestion_messages', 'regle'));
    }

    public function testLeRetourRefuseDePerdreUneTraceDeCourrier(): void
    {
        $this->tableV1();
        Migrations::monter($this->pdo);
        $this->pdo->exec("INSERT INTO ingestion_messages (boite, message_id, pieces, regle, statut)"
            . " VALUES ('compte@exemple.test', 'courrier:trace', 0, 'ma-regle', 'SUCCESS')");

        try {
            Migrations::retour($this->pdo, 1);
            self::fail('le retour devait être refusé');
        } catch (ErreurIngestion $e) {
            self::assertStringContainsString('trace', $e->getMessage());
        }
        // Rien n'a bougé : la colonne et la ligne sont toujours là.
        self::assertTrue(Migrations::colonneExiste($this->pdo, 'ingestion_messages', 'regle'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ingestion_messages')->fetchColumn());
    }

    public function testUnRetourVersUneVersionInconnueEstRefuse(): void
    {
        $this->tableV1();
        Migrations::monter($this->pdo);

        $this->expectException(ErreurIngestion::class);
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
            CREATE TABLE ingestion_messages (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              boite VARCHAR(255) NOT NULL,
              message_id VARCHAR(512) NOT NULL,
              pieces TINYINT UNSIGNED NOT NULL DEFAULT 0,
              recu_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_message (boite(150), message_id(300))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
