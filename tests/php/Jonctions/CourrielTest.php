<?php

declare(strict_types=1);

namespace Ged\Tests\Jonctions;

use Ged\Jonctions\Courriel;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-08b — un `.eml` est un document (section 3 du contrat CMM).
 *
 * Texte indexé = en-têtes + corps textuel ; aperçu = HTML nettoyé (aucun script, aucune ressource
 * distante, aucun gestionnaire d'événement) ; pièces jointes listées.
 */
final class CourrielTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testUnEmlDonneTexteIndexeApercuNettoyeEtPiecesListees(): void
    {
        $chemin = $this->piece('message-' . $this->sel . '.eml', $this->eml());
        $courriel = Courriel::lire($chemin);
        self::assertNotNull($courriel);

        // Texte indexé : en-têtes + corps textuel.
        self::assertSame('alice@exemple.test', $courriel['entetes']['de']);
        self::assertSame('Facture ' . $this->sel, $courriel['entetes']['objet']);
        self::assertStringContainsString('Objet', $courriel['texte']);
        self::assertStringContainsString('CORPS-' . $this->sel, $courriel['texte']);

        // Aperçu nettoyé : aucun script, aucune ressource distante, aucun attribut d'événement.
        $html = (string) $courriel['html'];
        self::assertStringContainsString('CORPS-' . $this->sel, $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('charge.png', $html, 'l’image distante est retirée');
        self::assertStringNotContainsString('href=', $html, 'aucun lien sortant conservé');

        // Pièces jointes listées.
        self::assertSame('facture-' . $this->sel . '.pdf', $courriel['pieces'][0]['nom']);
        self::assertStringContainsString('facture-' . $this->sel . '.pdf', $html);
    }

    public function testUnMessageSimpleSansMultipartEstLu(): void
    {
        $eml = "From: Alice <alice@exemple.test>\r\nTo: bob@exemple.test\r\nSubject: Simple {$this->sel}\r\n"
            . "Date: Tue, 30 Sep 2026 10:00:00 +0200\r\nMessage-ID: <simple-{$this->sel}@exemple.test>\r\n\r\n"
            . "Bonjour {$this->sel},\r\nMerci.\r\n";
        $courriel = Courriel::lire($this->piece('simple-' . $this->sel . '.eml', $eml));
        self::assertNotNull($courriel, 'un message simple reste un document lisible');
        self::assertStringContainsString($this->sel, $courriel['texte']);
        self::assertSame('Simple ' . $this->sel, $courriel['entetes']['objet']);
    }

    /** Un message multipart synthétique : alternative texte/HTML + une pièce PDF. */
    private function eml(): string
    {
        $corps = "Bonjour,\n\nVoici le CORPS-{$this->sel} demandé.\n\nCordialement.";
        $html = '<html><body><p>Bonjour,</p><p>CORPS-' . $this->sel . ' '
            . '<a href="https://courriel.exemple.test/piece">voir</a></p>'
            . '<p><img src="https://courriel.exemple.test/charge.png" alt="logo"></p>'
            . '<script>window.__cmm_xss=1</script><p onclick="alert(1)">Fin</p></body></html>';
        $piece = chunk_split(base64_encode('%PDF-1.4 piece jointe ' . $this->sel));

        return "From: Alice Exemple <alice@exemple.test>\r\n"
            . "To: Bob Exemple <bob@exemple.test>\r\n"
            . "Subject: Facture {$this->sel}\r\n"
            . "Date: Tue, 30 Sep 2026 09:00:00 +0200\r\n"
            . "Message-ID: <message-{$this->sel}@exemple.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"LIMITE\"\r\n\r\n"
            . "--LIMITE\r\n"
            . "Content-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
            . "--ALT\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$corps}\r\n"
            . "--ALT\r\nContent-Type: text/html; charset=utf-8\r\n\r\n{$html}\r\n"
            . "--ALT--\r\n"
            . "--LIMITE\r\nContent-Type: application/pdf; name=\"facture-{$this->sel}.pdf\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"facture-{$this->sel}.pdf\"\r\n\r\n{$piece}\r\n"
            . "--LIMITE--\r\n";
    }
}
