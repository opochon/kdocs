<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Decoupeur;
use Ged\Ingestion\Entree;
use Ged\Ingestion\Imap\ConnexionDifferee;
use Ged\Ingestion\Imap\ImapBoite;
use Ged\Ingestion\Pilote;
use Ged\Ingestion\Schema;
use Ged\Ingestion\Source;
use Ged\Ingestion\SourceAdresse;
use Ged\Ingestion\Travaux;
use PHPUnit\Framework\TestCase;

/** Une porte d'entrée en panne n'arrête pas les autres ; sans dépôt, les pièces prêtes attendent. */
final class PiloteAlertesTest extends TestCase
{
    public function testBoiteInjoignableIsoleeEtSansDepotRienNeSePerd(): void
    {
        $config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $config->get('DB_NAME'));
        Base::assurerBase($config);
        $pdo = Base::pdo($config);
        $pdo->exec('DROP TABLE IF EXISTS ingestion_travaux, ingestion_messages');   // base de TEST uniquement
        Schema::installer($pdo);
        $tmp = sys_get_temp_dir() . '/gedv2-al-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        file_put_contents($tmp . '/lot.pdf', '%PDF lot');

        // Port fermé : la connexion échoue au relevé, pas à la construction.
        $morte = new SourceAdresse(new ConnexionDifferee(static fn () => new ImapBoite('127.0.0.1', 9, 'x', 'y', 'tcp', 'INBOX', 2), 'x@127.0.0.1'), $pdo, $tmp . '/t');
        $vivante = new class ($tmp . '/lot.pdf') implements Source {
            private bool $fait = false;

            public function __construct(private readonly string $f)
            {
            }

            public function relever(): array
            {
                if ($this->fait) {
                    return [];
                }
                $this->fait = true;

                return [new Entree($this->f, 'dossier', 'lot.pdf')];
            }
        };
        $decoupeur = new class () implements Decoupeur {
            public function decouper(string $fichier, string $dossierSortie): array
            {
                @mkdir($dossierSortie, 0775, true);
                copy($fichier, $dossierSortie . '/p1.pdf');

                return [['fichier' => $dossierSortie . '/p1.pdf', 'pages' => [1], 'texte' => 'Facture', 'analyse' => []]];
            }
        };

        $pilote = new Pilote(new Travaux($pdo), [$morte, $vivante], $decoupeur, null, null, $tmp . '/t');
        $r = $pilote->tic(5);
        self::assertSame(1, $r['recus'], 'le dossier est relevé malgré la boîte en panne');
        self::assertSame(1, $r['decoupes']);
        self::assertCount(1, $r['alertes']);
        self::assertStringStartsWith('SourceAdresse : Connexion impossible', $r['alertes'][0]);

        $r = $pilote->tic(5);
        self::assertSame(0, $r['ranges'], 'sans dépôt, rien n\'est rangé…');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM ingestion_travaux WHERE etat = 'pret'")->fetchColumn(), '…et la pièce attend, intacte');
    }
}
