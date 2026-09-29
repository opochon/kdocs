<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

require_once __DIR__ . '/SourceContrat.php';

use Ged\Ingestion\SourceAdresse;

final class SourceAdresseContratTest extends SourceAvecBaseContrat
{
    protected function releverDeuxFois(): array
    {
        $message = "From: Emetteur <emetteur@exemple.test>\r\nTo: ged@exemple.test\r\nSubject: Document\r\n"
            . "Message-ID: <contrat-source@exemple.test>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=frontiere\r\n\r\n"
            . "--frontiere\r\nContent-Type: application/pdf; name=piece.pdf\r\nContent-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=piece.pdf\r\n\r\n" . base64_encode('%PDF contrat') . "\r\n--frontiere--\r\n";
        $premier = (new SourceAdresse(new BoiteContrat([1 => $message]), $this->pdo, $this->tmp . '/travail'))->relever();
        $rejeu = (new SourceAdresse(new BoiteContrat([2 => $message]), $this->pdo, $this->tmp . '/travail'))->relever();

        return [$premier, $rejeu];
    }
}
