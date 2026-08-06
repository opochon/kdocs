<?php

declare(strict_types=1);

namespace Tests\Feature;

use KDocs\Apps\Erpconnect\Controllers\ErpConnectController;
use KDocs\Apps\Erpconnect\Services\ErpConnectService;
use KDocs\Apps\Erpconnect\Services\KTimeClient;
use PDO;

/**
 * Oracle de l'item backlog erp.cle-masquee : « KTIME_GED_API_KEY jamais sérialisée
 * dans un log ou un rapport ».
 *
 * KTimeClient n'interpole jamais la clé dans une URL ou un message (elle ne vit que
 * dans le header X-Api-Key réellement envoyé). Le vrai risque est en aval : le transport
 * HTTP est injectable (fn(method,url,opts)) et n'importe quelle implémentation future —
 * un wrapper curl de diagnostic, par exemple — peut lever une exception dont le message
 * embarque la requête (URL + headers). Cette exception traverse ErpConnectService puis
 * ErpConnectController tels quels : c'est donc à la sortie (réponse JSON du contrôleur)
 * que le masquage doit être garanti, quelle que soit la source du message.
 *
 * Ce test provoque exactement ce chemin avec un transport qui « logue » sa requête dans
 * le message d'exception (motif plausible d'un client bas niveau), puis vérifie que la
 * clé factice n'apparaît nulle part dans la réponse produite par le contrôleur, et que le
 * marqueur de masquage y figure bien. Il couvre aussi directement KTimeClient::redactValue()
 * et ::redactStructure() (la brique réutilisable) pour garantir qu'elles ne dégénèrent pas
 * quand la clé est vide/absente.
 */
class ApiKeyRedactionTest extends ApiTestCase
{
    private const FAKE_KEY = 'FAKE-KTIME-KEY-7f3c9a2b1d';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE documents (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT
        )');

        $this->pdo->exec('CREATE TABLE invoice_line_items (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            line_number INTEGER NOT NULL DEFAULT 1,
            code        TEXT,
            description TEXT NOT NULL,
            quantity    REAL,
            unit_price  REAL,
            tax_rate    REAL
        )');

        $this->pdo->exec('CREATE TABLE erp_links (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id       INTEGER NOT NULL,
            connector         TEXT NOT NULL DEFAULT \'ktime\',
            external_id       INTEGER,
            external_ref      TEXT,
            status            TEXT,
            validation_status TEXT,
            validated_by_name TEXT,
            validated_at      TEXT,
            block_kind        TEXT,
            block_cause       TEXT,
            payload_json      TEXT,
            created_at        TEXT NOT NULL,
            updated_at        TEXT NOT NULL,
            UNIQUE (document_id, connector)
        )');

        $this->pdo->exec('CREATE TABLE invoice_line_allocations (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            line_item_id    INTEGER NOT NULL,
            document_id     INTEGER NOT NULL,
            quantity        REAL NOT NULL,
            allocation_type TEXT NOT NULL,
            erp_ref_type    TEXT,
            erp_ref_id      TEXT,
            erp_ref_label   TEXT,
            status          TEXT NOT NULL DEFAULT \'proposed\',
            created_at      TEXT NOT NULL,
            updated_at      TEXT NOT NULL
        )');
    }

    private function insertDocument(): int
    {
        $this->pdo->prepare('INSERT INTO documents (title) VALUES (?)')->execute(['Facture test']);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Transport qui simule un client HTTP bas niveau « bavard » : il lève une exception
     * dont le message embarque la méthode, l'URL et les headers envoyés (dont X-Api-Key
     * en clair) — comportement plausible d'un wrapper de diagnostic, pas celui, correct,
     * de KTimeClient::curlRequest/streamRequest. Le contrôleur ne doit jamais faire
     * confiance à ce que le transport lui remonte.
     */
    private function makeLeakyTransport(): callable
    {
        return function (string $method, string $url, array $opts): array {
            throw new \RuntimeException(sprintf(
                'Echec transport %s %s headers=%s',
                $method,
                $url,
                json_encode($opts['headers'] ?? [])
            ));
        };
    }

    private function makeController(callable $transport): ErpConnectController
    {
        $service = new ErpConnectService($this->pdo, new KTimeClient($transport));

        return new class ($service) extends ErpConnectController {
            private ErpConnectService $svc;

            public function __construct(ErpConnectService $svc)
            {
                $this->svc = $svc;
            }

            protected function makeService(): ErpConnectService
            {
                return $this->svc;
            }
        };
    }

    /** Pose KTIME_GED_API_KEY=self::FAKE_KEY le temps du callback, puis restaure l'état précédent. */
    private function withFakeApiKey(callable $fn): void
    {
        $hadEnv    = array_key_exists('KTIME_GED_API_KEY', $_ENV);
        $savedEnv  = $_ENV['KTIME_GED_API_KEY'] ?? null;
        $savedEnv2 = getenv('KTIME_GED_API_KEY');

        $_ENV['KTIME_GED_API_KEY'] = self::FAKE_KEY;
        putenv('KTIME_GED_API_KEY=' . self::FAKE_KEY);

        try {
            $fn();
        } finally {
            if ($hadEnv) {
                $_ENV['KTIME_GED_API_KEY'] = $savedEnv;
            } else {
                unset($_ENV['KTIME_GED_API_KEY']);
            }
            if ($savedEnv2 !== false) {
                putenv('KTIME_GED_API_KEY=' . $savedEnv2);
            } else {
                putenv('KTIME_GED_API_KEY');
            }
        }
    }

    // =========================================================================
    // Chemin réel : contrôleur → réponse JSON
    // =========================================================================

    /**
     * submit() : quand le transport lève une exception qui embarque la clé (header
     * X-Api-Key), la réponse JSON du contrôleur ne doit jamais contenir la clé en clair,
     * et doit porter le marqueur de masquage.
     */
    public function testSubmitErrorResponseNeverLeaksApiKey(): void
    {
        $this->withFakeApiKey(function (): void {
            $docId = $this->insertDocument();
            $ctrl  = $this->makeController($this->makeLeakyTransport());

            $response = $ctrl->submit(
                $this->createMockRequest('POST', [], ['supplier_id' => 42]),
                $this->createMockResponse(),
                ['documentId' => (string) $docId]
            );

            $raw = $response->getBodyContents();

            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString(
                self::FAKE_KEY,
                $raw,
                'La réponse JSON du contrôleur ne doit jamais exposer la clé API en clair'
            );
            $this->assertStringContainsString(
                '***MASQUE***',
                $raw,
                'Le marqueur de masquage doit apparaître à la place de la clé'
            );
        });
    }

    /**
     * proposal() : même garantie côté chemin de lecture (buildProposal → health() qui
     * peut aussi propager une exception non KTimeUnavailableException levée par le
     * transport, ex. erreur de configuration).
     */
    public function testProposalErrorResponseNeverLeaksApiKey(): void
    {
        $this->withFakeApiKey(function (): void {
            $docId = $this->insertDocument();
            $ctrl  = $this->makeController($this->makeLeakyTransport());

            $response = $ctrl->proposal(
                $this->createMockRequest('GET'),
                $this->createMockResponse(),
                ['documentId' => (string) $docId]
            );

            $raw = $response->getBodyContents();

            $this->assertStringNotContainsString(self::FAKE_KEY, $raw);
        });
    }

    // =========================================================================
    // Brique réutilisable : KTimeClient::redactValue / ::redactStructure
    // =========================================================================

    public function testRedactValueMasksKeyValue(): void
    {
        $masked = KTimeClient::redactValue('X-Api-Key: ' . self::FAKE_KEY, self::FAKE_KEY);

        $this->assertStringNotContainsString(self::FAKE_KEY, $masked);
        $this->assertStringContainsString('***MASQUE***', $masked);
    }

    /**
     * Ne dégénère pas : clé vide/absente → aucune substitution sauvage (le texte, y compris
     * une chaîne vide, ressort inchangé — pas de remplacement de "" partout dans le texte).
     */
    public function testRedactValueDoesNotDegenerateWhenKeyEmpty(): void
    {
        $this->assertSame('', KTimeClient::redactValue('', ''));

        $text = 'ceci est un texte sans rapport avec la cle API';
        $this->assertSame($text, KTimeClient::redactValue($text, ''));
    }

    public function testRedactStructureMasksHeaderByNameAndByValue(): void
    {
        $structure = [
            'headers' => [
                'Accept'    => 'application/json',
                'X-Api-Key' => self::FAKE_KEY,
            ],
            'note' => 'clé utilisée : ' . self::FAKE_KEY,
        ];

        $masked = KTimeClient::redactStructure($structure, self::FAKE_KEY);

        $this->assertSame('***MASQUE***', $masked['headers']['X-Api-Key']);
        $this->assertStringNotContainsString(self::FAKE_KEY, $masked['note']);
        $this->assertSame('application/json', $masked['headers']['Accept']);
    }
}
