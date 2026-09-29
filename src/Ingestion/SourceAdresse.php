<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Ingestion\Imap\Boite;
use PDO;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Adresse d'ingestion : on envoie (ou transfère) un courriel avec ses pièces jointes à une adresse dédiée,
 * les pièces entrent dans la GED comme si elles avaient été déposées dans le dossier surveillé.
 *
 * - Un message n'est traité qu'une fois (table ingestion_messages, clé boîte + Message-ID) ; s'il est relu
 *   après une panne, ses pièces sont reconnues à leur empreinte et ne créent pas de doublon.
 * - Expéditeurs autorisés : si la liste est renseignée, un message d'un autre expéditeur n'est pas ingéré ;
 *   il est rangé dans le dossier des refusés (rien n'est jamais effacé).
 * - Le message traité est marqué lu et rangé dans le dossier des traités.
 * Les connecteurs lourds (Gmail, Microsoft 365, règles de tri) relèvent de ClearMyMails, pas d'ici.
 */
final class SourceAdresse implements Source
{
    private const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'docx', 'doc', 'xlsx', 'xls', 'odt', 'ods', 'rtf', 'txt'];
    private const TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/tiff' => 'tif'];

    /** @param list<string> $expediteursAutorises adresses ou domaines (@exemple.ch) ; vide = tous */
    public function __construct(
        private readonly Boite $boite,
        private readonly PDO $pdo,
        private readonly string $zoneTravail,
        private readonly array $expediteursAutorises = [],
        private readonly string $dossierTraites = 'GED-traites',
        private readonly string $dossierRefuses = 'GED-refuses',
        private readonly int $maxMessages = 20,
    ) {
    }

    public function relever(): array
    {
        $entrees = [];
        $parseur = new MailMimeParser();
        foreach (array_slice($this->boite->nonLus(), 0, $this->maxMessages) as $uid) {
            $brut = $this->boite->lire($uid);
            $message = $parseur->parse($brut, false);
            $messageId = trim((string) $message->getHeaderValue(HeaderConsts::MESSAGE_ID)) ?: 'sans-id-' . hash('sha256', $brut);

            if ($this->dejaTraite($messageId)) {
                $this->boite->classer($uid, $this->dossierTraites);
                continue;
            }
            $expediteur = strtolower((string) $message->getHeaderValue(HeaderConsts::FROM));
            if (!$this->autorise($expediteur)) {
                $this->noter($messageId, 0);
                $this->boite->classer($uid, $this->dossierRefuses);
                continue;
            }

            $pieces = 0;
            foreach ($message->getAllAttachmentParts() as $part) {
                if ($part->getContentDisposition() === 'inline' && $part->getContentId() !== null && str_starts_with(strtolower((string) $part->getContentType()), 'image/')) {
                    continue;   // logo de signature ou image insérée dans le texte : pas un document
                }
                $nom = (string) ($part->getFilename() ?? '');
                $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
                if (!in_array($ext, self::EXTENSIONS, true)) {
                    $ext = self::TYPES[strtolower((string) $part->getContentType())] ?? '';
                    if ($ext === '') {
                        continue;   // signature, vCard, image de pied de page… : pas un document
                    }
                    $nom = ($nom !== '' ? pathinfo($nom, PATHINFO_FILENAME) : 'piece-jointe') . '.' . $ext;
                }
                $contenu = $part->getContent();
                if ($contenu === null || $contenu === '') {
                    continue;
                }
                $entrees[] = $this->ecrire($contenu, $nom);
                $pieces++;
            }
            $this->noter($messageId, $pieces);
            $this->boite->classer($uid, $this->dossierTraites);
        }

        return $entrees;
    }

    private function ecrire(string $contenu, string $nom): Entree
    {
        if (!is_dir($this->zoneTravail)) {
            mkdir($this->zoneTravail, 0775, true);
        }
        $cible = $this->zoneTravail . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . SourceDossier::nomSur($nom);
        $tmp = $cible . '.partiel';
        if (file_put_contents($tmp, $contenu) === false || !rename($tmp, $cible)) {
            throw new \RuntimeException("Écriture impossible dans la zone de travail : $cible");
        }

        return new Entree($cible, 'adresse', $nom);
    }

    private function autorise(string $expediteur): bool
    {
        if ($this->expediteursAutorises === []) {
            return true;
        }
        foreach ($this->expediteursAutorises as $a) {
            $a = strtolower(trim($a));
            if ($a !== '' && ($expediteur === $a || (str_starts_with($a, '@') && str_ends_with($expediteur, $a)))) {
                return true;
            }
        }

        return false;
    }

    private function dejaTraite(string $messageId): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM ingestion_messages WHERE boite = ? AND message_id = ?');
        $q->execute([$this->boite->nom(), $messageId]);

        return (bool) $q->fetchColumn();
    }

    private function noter(string $messageId, int $pieces): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO ingestion_messages (boite, message_id, pieces) VALUES (?, ?, ?)')
            ->execute([$this->boite->nom(), mb_substr($messageId, 0, 512), min($pieces, 255)]);
    }
}
