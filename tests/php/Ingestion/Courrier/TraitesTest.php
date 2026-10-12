<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion\Courrier;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Courrier\Traites;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Journal des courriels traités : un message vu une fois ne l'est plus jamais (idempotence), le journal se
 * filtre comme Paperless et « purger » MARQUE la ligne, il ne la supprime pas.
 */
final class TraitesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 4));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        \Ged\Ingestion\Schema::installer($this->pdo);
        $this->pdo->exec("DELETE FROM ingestion_messages WHERE message_id LIKE 'courrier:%'");
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM ingestion_messages WHERE message_id LIKE 'courrier:%'");
    }

    public function testUnMessageVUUneFoisNEstPlusJamaisTraite(): void
    {
        $traites = new Traites($this->pdo);
        self::assertFalse($traites->existe('c', 'r', 'INBOX', '42'));
        $traites->enregistrer('c', 'r', 'INBOX', '42', 'Objet', new \DateTimeImmutable('2026-04-10'), Traites::SUCCES, null, new \DateTimeImmutable('2026-04-10'));
        self::assertTrue($traites->existe('c', 'r', 'INBOX', '42'));
        // Un autre dossier ou une autre règle restent distincts.
        self::assertFalse($traites->existe('c', 'r', 'Autre', '42'));
        self::assertFalse($traites->existe('c', 'autre', 'INBOX', '42'));

        // Un second enregistrement du même message met à jour la ligne, sans en créer une seconde.
        $traites->enregistrer('c', 'r', 'INBOX', '42', 'Objet', new \DateTimeImmutable('2026-04-10'), Traites::ECHEC, 'panne', new \DateTimeImmutable('2026-04-11'));
        $journal = $traites->journal(10);
        self::assertCount(1, $journal);
        self::assertSame(Traites::ECHEC, (string) $journal[0]['statut']);
        self::assertSame('panne', (string) $journal[0]['erreur']);
    }

    public function testLeJournalSeFiltreParRegleEtParStatut(): void
    {
        $traites = new Traites($this->pdo);
        $traites->enregistrer('c', 'factures', 'INBOX', '1', 'A', new \DateTimeImmutable('2026-04-10'), Traites::SUCCES, null, new \DateTimeImmutable('2026-04-10'));
        $traites->enregistrer('c', 'factures', 'INBOX', '2', 'B', new \DateTimeImmutable('2026-04-10'), Traites::ECHEC, 'boum', new \DateTimeImmutable('2026-04-10'));
        $traites->enregistrer('c', 'spam', 'INBOX', '3', 'C', new \DateTimeImmutable('2026-04-10'), Traites::SUCCES, null, new \DateTimeImmutable('2026-04-10'));

        self::assertCount(2, $traites->journal(10, 'factures'));
        self::assertCount(1, $traites->journal(10, null, Traites::ECHEC));
        self::assertCount(3, $traites->journal(10));
    }

    public function testPurgerMarqueLesLignesSansJamaisLesSupprimer(): void
    {
        $traites = new Traites($this->pdo);
        $traites->enregistrer('c', 'r', 'INBOX', '1', 'A', new \DateTimeImmutable('2026-04-10'), Traites::SUCCES, null, new \DateTimeImmutable('2026-04-10'));
        $id = (int) $this->pdo->query("SELECT id FROM ingestion_messages WHERE message_id LIKE 'courrier:%'")->fetchColumn();

        $marques = $traites->purger([$id]);
        self::assertSame([$id], $marques);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ingestion_messages WHERE id = ' . $id)->fetchColumn(), 'la ligne vit encore');
        self::assertSame(Traites::PURGE, (string) $this->pdo->query('SELECT statut FROM ingestion_messages WHERE id = ' . $id)->fetchColumn());
    }
}
