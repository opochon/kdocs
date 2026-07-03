<?php
/**
 * Synchronisation IMAP → GED (GAP-034).
 *
 * Import les messages non lus d'un compte mail_accounts vers la table documents.
 * Déduplication via mail_sync_log (UNIQUE account_id + message_uid) :
 * un message déjà importé est ignoré même si re-marqué UNSEEN côté serveur.
 *
 * Injection :
 *   - \PDO injectable pour les tests hermétiques SQLite (pas de Database::getInstance())
 *   - ImapClientInterface injectable → les tests n'ont jamais besoin de ext-imap
 */

namespace KDocs\Apps\Mail\Services;

use KDocs\Core\Database;

class MailSyncService
{
    private \PDO $db;
    private ImapClientInterface $imap;

    /**
     * @param \PDO|null              $db         Base de données (null = singleton)
     * @param ImapClientInterface|null $imapClient Client IMAP (null = NativeImapClient)
     */
    public function __construct(?\PDO $db = null, ?ImapClientInterface $imapClient = null)
    {
        $this->db   = $db   ?? Database::getInstance();
        $this->imap = $imapClient ?? new NativeImapClient();
    }

    /**
     * Importe les messages non lus du compte IMAP vers la table documents.
     *
     * Les messages déjà présents dans mail_sync_log (déduplication par UID)
     * sont ignorés. Chaque nouveau message crée une ligne documents et une
     * ligne mail_sync_log, puis est marqué \Seen.
     *
     * @param int $accountId ID dans mail_accounts
     * @return array{imported: int, document_ids: list<int>}
     * @throws \InvalidArgumentException Si le compte est introuvable
     */
    public function syncImapMailbox(int $accountId): array
    {
        // 1. Récupérer le compte
        $stmt = $this->db->prepare('SELECT * FROM mail_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($account === false) {
            throw new \InvalidArgumentException("Compte mail {$accountId} introuvable");
        }

        // 2. Connexion IMAP
        $connected = $this->imap->connect([
            'imap_server'   => $account['imap_server']   ?? 'localhost',
            'imap_port'     => $account['imap_port']     ?? 993,
            'imap_security' => $account['imap_security'] ?? 'ssl',
            'username'      => $account['username']      ?? '',
            'password'      => $account['password_encrypted'] ?? '',
        ]);

        if (!$connected) {
            return ['imported' => 0, 'document_ids' => []];
        }

        // 3. Fetch des messages non lus
        $messages = $this->imap->fetchUnseen();

        $imported    = 0;
        $documentIds = [];

        foreach ($messages as $msg) {
            $uid = (string) ($msg['uid'] ?? '');

            // Déduplication
            $checkStmt = $this->db->prepare(
                'SELECT id FROM mail_sync_log WHERE account_id = ? AND message_uid = ?'
            );
            $checkStmt->execute([$accountId, $uid]);
            if ($checkStmt->fetchColumn() !== false) {
                // Déjà importé — marque quand même comme lu et passe au suivant
                $this->imap->markSeen($uid);
                continue;
            }

            // Création du document
            $createdAt = !empty($msg['date']) ? $msg['date'] : date('Y-m-d H:i:s');
            $now       = date('Y-m-d H:i:s');

            $insertDoc = $this->db->prepare(
                'INSERT INTO documents (title, content, created_at) VALUES (?, ?, ?)'
            );
            $insertDoc->execute([
                $msg['subject'] ?? '(sans objet)',
                $msg['body']    ?? '',
                $createdAt,
            ]);
            $docId = (int) $this->db->lastInsertId();

            // Enregistrement dans mail_sync_log
            $logStmt = $this->db->prepare(
                'INSERT INTO mail_sync_log (account_id, message_uid, document_id, synced_at)
                 VALUES (?, ?, ?, ?)'
            );
            $logStmt->execute([$accountId, $uid, $docId, $now]);

            // Marquer comme lu côté serveur IMAP
            $this->imap->markSeen($uid);

            $documentIds[] = $docId;
            $imported++;
        }

        return ['imported' => $imported, 'document_ids' => $documentIds];
    }
}
