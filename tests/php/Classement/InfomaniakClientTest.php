<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Classement\InfomaniakClient;
use Ged\Config;
use PHPUnit\Framework\TestCase;

/**
 * L'adaptateur IA : modèle google/gemma-4-31B-it, délai 20 s, UNE requête par pièce, repli sans
 * blocage. Le transport HTTP est injecté : ces tests ne touchent JAMAIS le réseau.
 */
final class InfomaniakClientTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
    }

    /** @return array{code:int, corps:string, expire:bool} */
    private static function reponseOk(string $type = 'Facture'): array
    {
        return ['code' => 200, 'corps' => (string) json_encode([
            'choices' => [['message' => ['content' => (string) json_encode([
                'document_type_name' => $type,
                'correspondent_name' => 'Papeterie des Sapins Fictive SA',
                'title' => 'Facture PS-2026-101',
                'document_date' => '2026-01-05',
                'amount' => 700.38,
                'new_tags' => ['fournisseur'],
                'confidence' => 0.91,
            ], JSON_UNESCAPED_UNICODE)]]],
        ]), 'expire' => false];
    }

    public function testModeleDelaiEtLectureDeLaReponse(): void
    {
        $appels = [];
        $client = new InfomaniakClient($this->config, function (string $url, array $entetes, string $corps, int $delai) use (&$appels): array {
            $appels[] = ['url' => $url, 'entetes' => $entetes, 'corps' => $corps, 'delai' => $delai];

            return self::reponseOk();
        });

        self::assertTrue($client->disponible());
        self::assertSame('google/gemma-4-31B-it', $client->modele());

        $r = $client->classer("FACTURE N° PS-2026-101\nTotal TTC CHF 700.38\n", 'facture.pdf', ['Facture', 'Contrat']);

        self::assertNotNull($r);
        self::assertSame('Facture', $r->type);
        self::assertSame('Papeterie des Sapins Fictive SA', $r->emetteur);
        self::assertSame(700.38, $r->montant);
        self::assertSame(['fournisseur'], $r->etiquettes);
        self::assertCount(1, $appels);
        self::assertSame(20, $appels[0]['delai'], 'délai de classement borné à 20 s');
        $envoye = json_decode($appels[0]['corps'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google/gemma-4-31B-it', $envoye['model']);
        self::assertStringContainsString('Facture', (string) $envoye['messages'][1]['content']);
        self::assertStringContainsString("date D'ÉMISSION", (string) $envoye['messages'][1]['content']);
        self::assertStringContainsString('lignes OCR illisibles', (string) $envoye['messages'][1]['content']);
    }

    public function testUneExpirationSeReplieApresUneUniqueRequete(): void
    {
        $appels = 0;
        $client = new InfomaniakClient($this->config, function () use (&$appels): array {
            $appels++;

            return ['code' => 0, 'corps' => '', 'expire' => true];
        });

        $r = $client->classer('RECU la somme de CHF 74.55', 'recu.pdf', ['Reçu']);

        self::assertNull($r);
        self::assertSame(1, $appels, 'une pièce ne déclenche jamais une seconde requête');
        self::assertTrue($client->aExpire());
    }

    public function testExpirationRepeteeSeReplieSansBloquer(): void
    {
        $appels = 0;
        $client = new InfomaniakClient($this->config, function () use (&$appels): array {
            $appels++;

            return ['code' => 0, 'corps' => '', 'expire' => true];
        });

        self::assertNull($client->classer('du texte', 'x.pdf', ['Facture']));
        self::assertSame(1, $appels);
        self::assertTrue($client->aExpire());
    }

    public function testIndisponibleNExpedieRien(): void
    {
        $appels = 0;
        $client = new InfomaniakClient($this->config, function () use (&$appels): array {
            $appels++;

            return self::reponseOk();
        });

        putenv('INFOMANIAK_AI_ENABLED=false');
        try {
            self::assertFalse($client->disponible());
            self::assertNull($client->classer('FACTURE N° 1', 'f.pdf', ['Facture']));
            self::assertSame(0, $appels, 'aucun appel réseau quand l’IA est désactivée');
        } finally {
            putenv('INFOMANIAK_AI_ENABLED');
        }
    }

    public function testUnTypeInconnuNEstPasInvente(): void
    {
        $client = new InfomaniakClient($this->config, fn (): array => self::reponseOk('Bidule'));

        $r = $client->classer('du texte', 'x.pdf', ['Facture', 'Contrat']);

        self::assertNotNull($r);
        self::assertNull($r->type, 'un type hors nomenclature n’est pas repris');
    }

    public function testExtraireJsonEnleveLesFences(): void
    {
        self::assertSame(['type' => 'Facture'], InfomaniakClient::extraireJson("```json\n{\"type\":\"Facture\"}\n```"));
        self::assertNull(InfomaniakClient::extraireJson('pas du json'));
    }
}
