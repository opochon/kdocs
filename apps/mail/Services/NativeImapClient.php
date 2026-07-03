<?php
/**
 * Implémentation réelle du client IMAP via l'extension php-imap (GAP-034).
 *
 * Protégée par un guard : si ext-imap n'est pas chargée, le constructeur
 * lève une RuntimeException claire pour éviter de masquer la dépendance.
 *
 * Note : les tests n'instancient JAMAIS cette classe — ils injectent un mock
 * de ImapClientInterface.
 */

namespace KDocs\Apps\Mail\Services;

class NativeImapClient implements ImapClientInterface
{
    /** @var resource|false Connexion IMAP courante */
    private $mailbox = false;

    public function __construct()
    {
        if (!extension_loaded('imap')) {
            throw new \RuntimeException(
                'L\'extension PHP imap est requise pour NativeImapClient. '
                . 'Activez-la dans php.ini (extension=imap) ou injectez un ImapClientInterface mock.'
            );
        }
    }

    /** {@inheritDoc} */
    public function connect(array $account): bool
    {
        $security = match ($account['imap_security'] ?? 'ssl') {
            'ssl'  => '/ssl',
            'tls'  => '/tls',
            default => '/notls',
        };

        $dsn = sprintf(
            '{%s:%d/imap%s}INBOX',
            $account['imap_server'],
            (int) ($account['imap_port'] ?? 993),
            $security
        );

        $this->mailbox = @imap_open($dsn, $account['username'], $account['password'] ?? '');

        return $this->mailbox !== false;
    }

    /** {@inheritDoc} */
    public function fetchUnseen(): array
    {
        if ($this->mailbox === false) {
            return [];
        }

        $msgNums = imap_search($this->mailbox, 'UNSEEN');
        if ($msgNums === false) {
            return [];
        }

        $messages = [];
        foreach ($msgNums as $num) {
            $header  = imap_headerinfo($this->mailbox, $num);
            $uid     = (string) imap_uid($this->mailbox, $num);
            $subject = isset($header->subject)
                ? imap_utf8($header->subject)
                : '(sans objet)';
            $from = isset($header->from[0])
                ? $header->from[0]->mailbox . '@' . $header->from[0]->host
                : '';
            $date = $header->date ?? '';
            $body = imap_body($this->mailbox, $num);

            $messages[] = [
                'uid'         => $uid,
                'subject'     => $subject,
                'from'        => $from,
                'date'        => $date ? date('Y-m-d H:i:s', strtotime($date)) : date('Y-m-d H:i:s'),
                'body'        => $body,
                'attachments' => [],
            ];
        }

        return $messages;
    }

    /** {@inheritDoc} */
    public function markSeen(string $uid): void
    {
        if ($this->mailbox !== false) {
            imap_setflag_full($this->mailbox, $uid, '\\Seen', ST_UID);
        }
    }
}
