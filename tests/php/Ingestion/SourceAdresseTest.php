<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Imap\Boite;
use Ged\Ingestion\Imap\ImapBoite;
use Ged\Ingestion\Imap\ImapErreur;
use Ged\Ingestion\Schema;
use Ged\Ingestion\SourceAdresse;
use PDO;
use PHPUnit\Framework\TestCase;

/** Oracle de l'adresse d'ingestion : pièces utiles seulement, une seule fois, jamais d'effacement. */
final class SourceAdresseTest extends TestCase
{
    private PDO $pdo;
    private string $tmp;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        $this->pdo->exec('DROP TABLE IF EXISTS ingestion_travaux, ingestion_messages');   // base de TEST uniquement
        Schema::installer($this->pdo);
        $this->tmp = sys_get_temp_dir() . '/gedv2-adr-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/boite', 0775, true);
    }

    /** Courriel MIME : pièces [nom, type, contenu, inline?]. */
    private static function courriel(string $de, string $id, array $pieces): string
    {
        $f = 'frontiere-' . md5($id);
        $m = "From: Expéditeur <$de>\r\nTo: ged@exemple.test\r\nSubject: Documents\r\nMessage-ID: <$id>\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$f\"\r\n\r\n"
            . "--$f\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBonjour, ci-joint.\r\n";
        foreach ($pieces as [$nom, $type, $contenu, $inline]) {
            $m .= "--$f\r\nContent-Type: $type; name=\"$nom\"\r\nContent-Transfer-Encoding: base64\r\n"
                . ($inline ? "Content-Disposition: inline; filename=\"$nom\"\r\nContent-ID: <logo@exemple.test>\r\n" : "Content-Disposition: attachment; filename=\"$nom\"\r\n")
                . "\r\n" . chunk_split(base64_encode($contenu)) . "\r\n";
        }

        return $m . "--$f--\r\n";
    }

    public function testNeGardeQueLesDocumentsEtRangeLeMessage(): void
    {
        $boite = new BoiteMemoire([7 => self::courriel('compta@fournisseur.test', 'a1@fournisseur.test', [
            ['facture-F-104.pdf', 'application/pdf', '%PDF-1.4 facture', false],
            ['logo.png', 'image/png', "\x89PNG logo", true],
            ['carte.vcf', 'text/vcard', 'BEGIN:VCARD', false],
        ])]);
        $entrees = (new SourceAdresse($boite, $this->pdo, $this->tmp . '/travail'))->relever();

        self::assertCount(1, $entrees, 'seule la facture est un document');
        self::assertSame('facture-F-104.pdf', $entrees[0]->nomOrigine);
        self::assertSame('adresse', $entrees[0]->source);
        self::assertSame('%PDF-1.4 facture', file_get_contents($entrees[0]->chemin));
        self::assertSame([7 => 'GED-traites'], $boite->classes);
    }

    public function testUnMessageRelevéDeuxFoisNEntreQuUneFois(): void
    {
        $brut = self::courriel('a@exemple.test', 'meme@exemple.test', [['a.pdf', 'application/pdf', '%PDF a', false]]);
        $source = new SourceAdresse(new BoiteMemoire([1 => $brut]), $this->pdo, $this->tmp . '/travail');
        self::assertCount(1, $source->relever());
        $encore = new BoiteMemoire([2 => $brut]);   // ex. copie remise non lue dans la boîte
        self::assertCount(0, (new SourceAdresse($encore, $this->pdo, $this->tmp . '/travail'))->relever());
        self::assertSame([2 => 'GED-traites'], $encore->classes, 'rangé quand même, pour ne plus être relu');
    }

    public function testExpediteurNonAutoriseRefuseSansEffacer(): void
    {
        $boite = new BoiteMemoire([
            1 => self::courriel('inconnu@ailleurs.test', 'x@ailleurs.test', [['a.pdf', 'application/pdf', '%PDF x', false]]),
            2 => self::courriel('olivier@maison.test', 'y@maison.test', [['b.pdf', 'application/pdf', '%PDF y', false]]),
        ]);
        $entrees = (new SourceAdresse($boite, $this->pdo, $this->tmp . '/travail', ['@maison.test']))->relever();
        self::assertCount(1, $entrees);
        self::assertSame('b.pdf', $entrees[0]->nomOrigine);
        self::assertSame([1 => 'GED-refuses', 2 => 'GED-traites'], $boite->classes);
    }

    /** Client IMAP réel contre un faux serveur local : protocole, littéraux, MOVE, et aucune commande d'effacement. */
    public function testClientImapContreServeurLocal(): void
    {
        file_put_contents($this->tmp . '/boite/01.eml', self::courriel('a@exemple.test', 'i1@exemple.test', [['releve.pdf', 'application/pdf', '%PDF-1.4 ' . str_repeat('x', 5000), false]]));
        file_put_contents($this->tmp . '/boite/02.eml', self::courriel('b@exemple.test', 'i2@exemple.test', [['scan.jpg', 'image/jpeg', "\xFF\xD8 photo", false]]));
        foreach (['MOVE' => '', 'COPY' => 'sansMOVE'] as $attendu => $option) {
            @unlink($this->tmp . '/boite/journal.json');
            $this->pdo->exec('DELETE FROM ingestion_messages');   // table de TEST
            [$proc, $port] = $this->serveur($option);
            $boite = new ImapBoite('127.0.0.1', $port, 'ged@exemple.test', 'bon-mot', 'tcp');
            $entrees = (new SourceAdresse($boite, $this->pdo, $this->tmp . '/travail'))->relever();
            $boite->fermer();
            proc_close($proc);

            self::assertSame(['releve.pdf', 'scan.jpg'], array_map(fn ($e) => $e->nomOrigine, $entrees));
            self::assertSame(5009, filesize($entrees[0]->chemin), 'littéral IMAP lu en entier');
            $journal = json_decode((string) file_get_contents($this->tmp . '/boite/journal.json'), true);
            self::assertSame(['1' => 'GED-traites', '2' => 'GED-traites'], $journal['ranges']);
            self::assertSame([1, 2], $journal['lus']);
            $tout = implode("\n", $journal['commandes']);
            self::assertStringContainsString("UID $attendu 1", $tout);
            self::assertStringNotContainsStringIgnoringCase('EXPUNGE', $tout);
            self::assertStringNotContainsStringIgnoringCase('Deleted', $tout);
            self::assertStringNotContainsString('bon-mot', $tout);
        }
    }

    public function testMauvaisIdentifiantsDonnentUneErreurClaireSansLeMotDePasse(): void
    {
        [$proc, $port] = $this->serveur('');
        try {
            new ImapBoite('127.0.0.1', $port, 'ged@exemple.test', 'mauvais-secret', 'tcp');
            self::fail('connexion acceptée');
        } catch (ImapErreur $e) {
            self::assertStringContainsString('refuses', $e->getMessage());
            self::assertStringNotContainsString('mauvais-secret', $e->getMessage());
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /** @return array{0: resource, 1: int} */
    private function serveur(string $option): array
    {
        // Port 0 : le système choisit un port libre (un port tiré au hasard pouvait être déjà pris sur le poste).
        $cmd = [PHP_BINARY, dirname(__DIR__, 2) . '/outils/faux-imap.php', '0', $this->tmp . '/boite'];
        if ($option !== '') {
            $cmd[] = $option;
        }
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux);
        self::assertIsResource($proc);
        $ligne = (string) fgets($tuyaux[1]);
        if (preg_match('/^pret (\d+)\n$/', $ligne) !== 1) {
            self::fail('faux serveur IMAP non démarré : ' . trim((string) stream_get_contents($tuyaux[2])));
        }
        $port = (int) substr(trim($ligne), 5);

        return [$proc, $port];
    }
}

/** Boîte en mémoire : messages par UID, garde trace des rangements. */
final class BoiteMemoire implements Boite
{
    /** @var array<int, string> */
    public array $classes = [];

    /** @param array<int, string> $messages */
    public function __construct(private array $messages)
    {
    }

    public function nom(): string
    {
        return 'ged@exemple.test';
    }

    public function nonLus(): array
    {
        return array_values(array_diff(array_keys($this->messages), array_keys($this->classes)));
    }

    public function lire(int $uid): string
    {
        return $this->messages[$uid];
    }

    public function classer(int $uid, string $dossier): void
    {
        $this->classes[$uid] = $dossier;
    }

    public function fermer(): void
    {
    }
}
