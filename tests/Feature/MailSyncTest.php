<?php

declare(strict_types=1);

/**
 * GAP-034 — MailSyncService : import IMAP → documents.
 *
 * Hermétique : SQLite en mémoire + mock ImapClientInterface.
 * Aucune dépendance à ext-imap ni à Database::getInstance().
 */

namespace Tests\Feature;

use KDocs\Apps\Mail\Services\ImapClientInterface;
use KDocs\Apps\Mail\Services\MailSyncService;
use PHPUnit\Framework\TestCase;

class MailSyncTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Schéma minimal
        $this->db->exec('CREATE TABLE mail_accounts (
            id INTEGER PRIMARY KEY,
            name TEXT,
            imap_server TEXT NOT NULL DEFAULT "imap.example.com",
            imap_port INTEGER NOT NULL DEFAULT 993,
            imap_security TEXT NOT NULL DEFAULT "ssl",
            username TEXT NOT NULL DEFAULT "",
            password_encrypted TEXT NOT NULL DEFAULT ""
        )');

        $this->db->exec('CREATE TABLE documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            content TEXT,
            created_at TEXT
        )');

        $this->db->exec('CREATE TABLE mail_sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            message_uid TEXT NOT NULL,
            document_id INTEGER,
            synced_at TEXT NOT NULL,
            UNIQUE (account_id, message_uid)
        )');

        // Insérer un compte mail de test
        $this->db->exec(
            "INSERT INTO mail_accounts (id, name, imap_server, username) VALUES (1, 'Test', 'imap.test.local', 'user@test.local')"
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Crée un mock ImapClientInterface qui se connecte avec succès
     * et retourne les messages fournis via fetchUnseen().
     */
    private function makeImapMock(array $messages, int $expectMarkSeenCalls = 0): ImapClientInterface
    {
        $mock = $this->createMock(ImapClientInterface::class);
        $mock->method('connect')->willReturn(true);
        $mock->method('fetchUnseen')->willReturn($messages);

        if ($expectMarkSeenCalls > 0) {
            $mock->expects($this->exactly($expectMarkSeenCalls))
                 ->method('markSeen');
        }

        return $mock;
    }

    /**
     * Instancie MailSyncService avec les dépendances injectées.
     */
    private function makeService(ImapClientInterface $imap): MailSyncService
    {
        return new MailSyncService($this->db, $imap);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testSyncImporteDeuxMessages(): void
    {
        $messages = [
            [
                'uid'         => 'uid-001',
                'subject'     => 'Facture janvier 2026',
                'from'        => 'fournisseur@example.com',
                'date'        => '2026-01-10 09:00:00',
                'body'        => 'Corps du message 1',
                'attachments' => [],
            ],
            [
                'uid'         => 'uid-002',
                'subject'     => 'Contrat de service',
                'from'        => 'partenaire@example.com',
                'date'        => '2026-01-11 14:30:00',
                'body'        => 'Corps du message 2',
                'attachments' => [],
            ],
        ];

        // markSeen doit être appelé 2 fois (une fois par message)
        $imap    = $this->makeImapMock($messages, 2);
        $service = $this->makeService($imap);

        $result = $service->syncImapMailbox(1);

        $this->assertSame(2, $result['imported'], 'Deux messages doivent être importés');
        $this->assertCount(2, $result['document_ids']);

        // Vérifier les titres en base
        $docs = $this->db->query('SELECT title FROM documents ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('Facture janvier 2026', $docs);
        $this->assertContains('Contrat de service', $docs);

        // Vérifier les lignes mail_sync_log
        $logCount = (int) $this->db->query('SELECT COUNT(*) FROM mail_sync_log')->fetchColumn();
        $this->assertSame(2, $logCount);
    }

    public function testResyncIgnoreDejaImportes(): void
    {
        $messages = [
            [
                'uid'         => 'uid-999',
                'subject'     => 'Message unique',
                'from'        => 'test@example.com',
                'date'        => '2026-02-01 08:00:00',
                'body'        => 'Contenu',
                'attachments' => [],
            ],
        ];

        $imap    = $this->createMock(ImapClientInterface::class);
        $imap->method('connect')->willReturn(true);
        $imap->method('fetchUnseen')->willReturn($messages);
        $imap->method('markSeen'); // pas de contrainte sur le nombre d'appels

        $service = $this->makeService($imap);

        // Premier sync → imported=1
        $first = $service->syncImapMailbox(1);
        $this->assertSame(1, $first['imported']);

        // Deuxième sync avec le même UID → imported=0 (déduplication)
        $second = $service->syncImapMailbox(1);
        $this->assertSame(0, $second['imported'], 'Re-sync doit retourner imported=0 (dédup)');
        $this->assertSame([], $second['document_ids']);

        // Un seul document en base
        $docCount = (int) $this->db->query('SELECT COUNT(*) FROM documents')->fetchColumn();
        $this->assertSame(1, $docCount);
    }

    public function testMarkSeenAppelePourChaquemessage(): void
    {
        $messages = [
            [
                'uid'     => 'uid-A',
                'subject' => 'Alpha',
                'from'    => 'a@b.com',
                'date'    => '2026-03-01 00:00:00',
                'body'    => '',
                'attachments' => [],
            ],
            [
                'uid'     => 'uid-B',
                'subject' => 'Beta',
                'from'    => 'b@c.com',
                'date'    => '2026-03-02 00:00:00',
                'body'    => '',
                'attachments' => [],
            ],
        ];

        $imap = $this->createMock(ImapClientInterface::class);
        $imap->method('connect')->willReturn(true);
        $imap->method('fetchUnseen')->willReturn($messages);
        $imap->expects($this->exactly(2))->method('markSeen');

        $this->makeService($imap)->syncImapMailbox(1);
    }

    public function testCompteInconnuLeveException(): void
    {
        $imap = $this->createMock(ImapClientInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->makeService($imap)->syncImapMailbox(999);
    }

    public function testConnexionImapEchoueeRetourneZero(): void
    {
        $imap = $this->createMock(ImapClientInterface::class);
        $imap->method('connect')->willReturn(false);
        $imap->expects($this->never())->method('fetchUnseen');

        $result = $this->makeService($imap)->syncImapMailbox(1);

        $this->assertSame(0, $result['imported']);
        $this->assertSame([], $result['document_ids']);
    }
}
