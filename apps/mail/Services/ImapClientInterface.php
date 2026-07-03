<?php
/**
 * Interface client IMAP injectable (GAP-034).
 *
 * Permet de substituer l'implémentation réelle (ext-imap) par un mock
 * dans les tests — sans dépendre de l'extension php-imap.
 *
 * Chaque message retourné par fetchUnseen() est un tableau :
 *   ['uid', 'subject', 'from', 'date', 'body', 'attachments' => [['filename','content']]]
 */

namespace KDocs\Apps\Mail\Services;

interface ImapClientInterface
{
    /**
     * Ouvre la connexion IMAP.
     *
     * @param array $account Paramètres (imap_server, imap_port, imap_security, username, password)
     * @return bool True si la connexion est établie
     */
    public function connect(array $account): bool;

    /**
     * Retourne les messages non lus (UNSEEN) de la boîte.
     *
     * @return array<int, array{uid: string, subject: string, from: string, date: string, body: string, attachments: list<array{filename: string, content: string}>}>
     */
    public function fetchUnseen(): array;

    /**
     * Marque un message comme lu (\Seen).
     *
     * @param string $uid UID IMAP du message
     */
    public function markSeen(string $uid): void;
}
