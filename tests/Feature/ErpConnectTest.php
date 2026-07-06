<?php

declare(strict_types=1);

/**
 * Tests hermétiques du plugin K-ERP Connect.
 *
 * SQLite en mémoire pour la persistance GED (documents, invoice_line_items, erp_links).
 * KTimeClient instancié avec un transport mocké (réponses JSON canned, aucun accès réseau).
 * Le contrôleur est instancié via une sous-classe anonyme qui surcharge makeService().
 *
 * Spec de référence : K-TIME/docs/SPEC-GED-INTEGRATION.md
 */

namespace Tests\Feature;

use KDocs\Apps\Erpconnect\Controllers\ErpConnectController;
use KDocs\Apps\Erpconnect\Services\ErpConnectService;
use KDocs\Apps\Erpconnect\Services\KTimeClient;
use KDocs\Core\PluginRegistry;
use PDO;

class ErpConnectTest extends ApiTestCase
{
    private PDO $pdo;

    // -------------------------------------------------------------------------
    // Réponses JSON K-Time (canned — conformes à la spec §3)
    // -------------------------------------------------------------------------

    /** §3.1 : health OK */
    private const KTIME_HEALTH_OK = ['ok' => true, 'api_version' => 1, 'capabilities' => ['received-invoices', 'ventilation', 'lookup']];

    /** §3.1 : health KO (K-Time indisponible) */
    private const KTIME_HEALTH_KO = ['ok' => false, 'api_version' => 0, 'capabilities' => []];

    /** §3.2 : lookup — fournisseur connu */
    private const KTIME_LOOKUP_KNOWN = ['matches' => [
        ['id' => 42, 'ad_numero' => 87, 'name' => 'Fournisseur SA', 'confidence' => 0.95, 'roles' => ['supplier']],
    ]];

    /** §3.2 : lookup — fournisseur inconnu */
    private const KTIME_LOOKUP_UNKNOWN = ['matches' => []];

    /** §3.3 : ventilation fournisseur 42 */
    private const KTIME_VENTILATION = [
        'supplier_id' => 42,
        'articles' => [
            ['product_id' => 7, 'code' => 'VIS-40', 'supplier_ref' => 'F-889', 'frequency' => 12, 'avg_price' => 4.50, 'ventilation' => 'stock'],
            ['product_id' => 8, 'code' => 'MAN-01', 'supplier_ref' => 'F-100', 'frequency' => 3,  'avg_price' => 120.0, 'ventilation' => 'fiche_travail'],
        ],
        'ventilation_types' => ['stock', 'vente_comptant', 'facture', 'fiche_travail', 'non_introduit'],
    ];

    /** §3.4 : facture n'existe pas encore */
    private const KTIME_EXISTS_NO = ['exists' => false];

    /** §3.4 : facture déjà existante */
    private const KTIME_EXISTS_YES = ['exists' => true, 'match' => ['id' => 311, 'status' => 'a_payer', 'invoice_date' => '2026-06-12', 'total_ttc' => 1234.50, 'source' => 'qr']];

    /** §3.5 : création réussie (première fois) */
    private const KTIME_CREATED = ['id' => 512, 'status' => 'draft', 'validation_status' => 'pending', 'duplicate' => false];

    /** §3.5 : création idempotente (duplicate) */
    private const KTIME_CREATED_DUP = ['id' => 512, 'status' => 'draft', 'validation_status' => 'pending', 'duplicate' => true];

    /** §3.6 : statut facture — validée (bon pour accord) */
    private const KTIME_INVOICE_VALIDATED = [
        'id'               => 512,
        'external_ref'     => 'ged:doc:1',
        'status'           => 'a_payer',
        'validation_status' => 'validated',
        'validated_by'     => ['id' => 3, 'name' => 'Olivier P.'],
        'validated_at'     => '2026-07-03 17:40:12',
    ];

    /** §3.6 : statut facture — en attente */
    private const KTIME_INVOICE_PENDING = [
        'id'               => 512,
        'external_ref'     => 'ged:doc:1',
        'status'           => 'draft',
        'validation_status' => 'pending',
        'validated_by'     => null,
        'validated_at'     => null,
    ];

    // -------------------------------------------------------------------------
    // setUp / helpers
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS documents (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS invoice_line_items (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id      INTEGER NOT NULL,
            line_number      INTEGER NOT NULL DEFAULT 1,
            code             TEXT,
            description      TEXT NOT NULL,
            quantity         REAL,
            unit_price       REAL,
            tax_rate         REAL,
            tax_amount       REAL,
            line_total       REAL,
            unit             TEXT,
            discount_percent REAL,
            compte_comptable TEXT,
            centre_cout      TEXT,
            projet           TEXT,
            raw_text         TEXT
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS invoice_extraction_results (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id       INTEGER NOT NULL,
            extraction_type   TEXT NOT NULL DEFAULT \'header\',
            raw_response      TEXT NOT NULL,
            parsed_data       TEXT,
            model_used        TEXT,
            tokens_used       INTEGER,
            extraction_time_ms INTEGER,
            success           INTEGER NOT NULL DEFAULT 1,
            error_message     TEXT
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS erp_links (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id       INTEGER NOT NULL,
            connector         TEXT NOT NULL DEFAULT \'ktime\',
            external_id       INTEGER,
            external_ref      TEXT,
            status            TEXT,
            validation_status TEXT,
            validated_by_name TEXT,
            validated_at      TEXT,
            payload_json      TEXT,
            created_at        TEXT NOT NULL,
            updated_at        TEXT NOT NULL,
            UNIQUE (document_id, connector)
        )');
    }

    /** Insère un document minimal et retourne son ID. */
    private function insertDocument(string $title = 'Facture test'): int
    {
        $this->pdo->prepare('INSERT INTO documents (title) VALUES (?)')
            ->execute([$title]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Insère une ligne de facture pour un document donné. */
    private function insertLine(int $documentId, array $data = []): int
    {
        $this->pdo->prepare(
            'INSERT INTO invoice_line_items
                (document_id, line_number, code, description, quantity, unit_price, tax_rate)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $documentId,
            $data['line_number'] ?? 1,
            $data['code']        ?? 'F-889',
            $data['description'] ?? 'Vis 40mm',
            $data['quantity']    ?? 100.0,
            $data['unit_price']  ?? 4.50,
            $data['tax_rate']    ?? 8.1,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Simule une extraction CMD v4 / IA : insère un résultat dans invoice_extraction_results
     * pour que fetchHeader() retourne les champs en-tête (supplier_name, invoice_number…).
     */
    private function insertExtractionResult(
        int $documentId,
        string $supplierName,
        string $invoiceNumber = 'F-2026-889',
        float $totalTtc = 1081.0,
        string $invoiceDate = '2026-07-01'
    ): void {
        $parsedData = json_encode([
            'supplier'       => $supplierName,
            'invoice_number' => $invoiceNumber,
            'invoice_date'   => $invoiceDate,
            'total_ttc'      => $totalTtc,
        ]);
        $this->pdo->prepare(
            'INSERT INTO invoice_extraction_results
                (document_id, extraction_type, raw_response, parsed_data, success)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$documentId, 'header', $parsedData, $parsedData, 1]);
    }

    /** Insère un lien erp_links (pour les tests refreshStatus). */
    private function insertErpLink(int $documentId, int $externalId = 512, string $status = 'draft'): void
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO erp_links
                (document_id, connector, external_id, external_ref, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $documentId, 'ktime', $externalId,
            'ged:doc:' . $documentId, $status, $now, $now,
        ]);
    }

    /**
     * Construit un transport mock K-Time à partir d'une map URL-pattern → réponse.
     * La map est parcourue dans l'ordre ; le premier pattern qui match l'URL est retourné.
     *
     * @param array<string, array<string,mixed>> $routes  clé = pattern, valeur = body array
     */
    private function makeTransport(array $routes): callable
    {
        return static function (string $method, string $url, array $opts) use ($routes): array {
            foreach ($routes as $pattern => $body) {
                if (str_contains($url, $pattern)) {
                    return ['status' => 200, 'body' => json_encode($body)];
                }
            }
            // Aucune route correspondante → 404 (ne déclenche pas KTimeUnavailableException)
            return ['status' => 404, 'body' => json_encode(['error' => 'not found'])];
        };
    }

    /** Transport qui simule K-Time indisponible (status 0 = réseau). */
    private function makeUnavailableTransport(): callable
    {
        return static function (string $method, string $url, array $opts): array {
            return ['status' => 0, 'body' => ''];
        };
    }

    /** Construit un service avec transport mocké. */
    private function makeService(callable $transport): ErpConnectService
    {
        return new ErpConnectService($this->pdo, new KTimeClient($transport));
    }

    /** Construit un contrôleur dont makeService() retourne le service mocké. */
    private function makeController(ErpConnectService $service): ErpConnectController
    {
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

    // =========================================================================
    // analyzeDocument
    // =========================================================================

    /**
     * Vérifie que analyzeDocument retourne les lignes depuis la BDD quand elles existent.
     */
    public function testAnalyzeDocumentFromDb(): void
    {
        $transport = $this->makeTransport([]);   // K-Time non consulté pour ce chemin
        $service   = $this->makeService($transport);

        $docId = $this->insertDocument('Facture ABC');
        $this->insertLine($docId, ['description' => 'Vis 40mm', 'quantity' => 100.0, 'unit_price' => 4.50, 'code' => 'F-889']);
        $this->insertLine($docId, ['description' => 'Écrou M6',  'quantity' => 50.0,  'unit_price' => 1.20, 'code' => 'F-100', 'line_number' => 2]);

        $result = $service->analyzeDocument($docId);

        $this->assertSame('db', $result['source']);
        $this->assertCount(2, $result['lines']);
        $this->assertSame('Vis 40mm', $result['lines'][0]['description']);
        $this->assertSame('Écrou M6',  $result['lines'][1]['description']);
    }

    /**
     * Vérifie que analyzeDocument retourne source='empty' quand aucune ligne n'existe
     * et que CMD v4 est désactivé (CMD_V4_ENABLED non posé en test).
     */
    public function testAnalyzeDocumentEmptyWhenNoLines(): void
    {
        $service = $this->makeService($this->makeTransport([]));
        $docId   = $this->insertDocument();

        $result = $service->analyzeDocument($docId);

        $this->assertSame('empty', $result['source']);
        $this->assertSame([], $result['lines']);
    }

    // =========================================================================
    // buildProposal — fournisseur connu
    // =========================================================================

    /**
     * Vérifie que buildProposal retourne un fournisseur connu avec ventilation par ligne.
     * Spec §3.2 (lookup) + §3.3 (ventilation) + §3.4 (exists).
     */
    public function testBuildProposalKnownSupplier(): void
    {
        $transport = $this->makeTransport([
            '/api/ged/health'                    => self::KTIME_HEALTH_OK,
            '/api/ged/suppliers/lookup'          => self::KTIME_LOOKUP_KNOWN,
            '/api/ged/suppliers/42/ventilation'  => self::KTIME_VENTILATION,
            '/api/ged/received-invoices/exists'  => self::KTIME_EXISTS_NO,
        ]);

        $docId = $this->insertDocument();
        $this->insertLine($docId, ['code' => 'F-889', 'description' => 'Vis 40mm']);
        // Simule l'extraction CMD v4 : supplier_name disponible → criteria non vide → lookup déclenché
        $this->insertExtractionResult($docId, 'Fournisseur SA', 'F-2026-889', 1081.0);

        $proposal = $this->makeService($transport)->buildProposal($docId);

        // K-Time disponible
        $this->assertTrue($proposal['ktime_available']);

        // Fournisseur reconnu
        $this->assertTrue($proposal['supplier']['known']);
        $this->assertSame('Fournisseur SA', $proposal['supplier']['match']['name']);
        $this->assertSame(42, $proposal['supplier']['match']['id']);

        // Facture nouvelle
        $this->assertIsArray($proposal['invoice_exists']);
        $this->assertFalse($proposal['invoice_exists']['exists']);

        // Ventilation mappée par code article
        $this->assertCount(1, $proposal['lines']);
        $this->assertSame('stock', $proposal['lines'][0]['ventilation'],
            'La ligne code F-889 doit être ventilée "stock" (map supplier_ref)');
        $this->assertSame([], $proposal['lines'][0]['options'],
            'Aucune option action pour une ligne déjà ventilée');
    }

    /**
     * Vérifie la ventilation pour un article avec ventilation 'non_introduit'.
     */
    public function testBuildProposalLineNotIntroducedHasOptions(): void
    {
        $transport = $this->makeTransport([
            '/api/ged/health'                   => self::KTIME_HEALTH_OK,
            '/api/ged/suppliers/lookup'         => self::KTIME_LOOKUP_KNOWN,
            '/api/ged/suppliers/42/ventilation' => self::KTIME_VENTILATION,
            '/api/ged/received-invoices/exists' => self::KTIME_EXISTS_NO,
        ]);

        $docId = $this->insertDocument();
        // Code inconnu de la ventilation → non_introduit
        $this->insertLine($docId, ['code' => 'INCONNU-99', 'description' => 'Produit inconnu']);
        // Supplier connu → lookup déclenché → ventilation appelée → code INCONNU-99 absent → non_introduit
        $this->insertExtractionResult($docId, 'Fournisseur SA', 'F-2026-889', 1081.0);

        $proposal = $this->makeService($transport)->buildProposal($docId);

        $line = $proposal['lines'][0];
        $this->assertSame('non_introduit', $line['ventilation']);
        $this->assertContains('stock',        $line['options']);
        $this->assertContains('fiche_travail', $line['options']);
        $this->assertContains('article_recu', $line['options']);
    }

    // =========================================================================
    // buildProposal — fournisseur inconnu
    // =========================================================================

    /**
     * Vérifie que buildProposal retourne supplier.known = false quand le lookup ne trouve rien.
     */
    public function testBuildProposalUnknownSupplier(): void
    {
        $transport = $this->makeTransport([
            '/api/ged/health'           => self::KTIME_HEALTH_OK,
            '/api/ged/suppliers/lookup' => self::KTIME_LOOKUP_UNKNOWN,
        ]);

        $docId = $this->insertDocument();
        $this->insertLine($docId);
        // Supplier name présent → lookup déclenché → retourne empty matches → known=false
        $this->insertExtractionResult($docId, 'Inconnu SARL', 'X-001', 500.0);

        $proposal = $this->makeService($transport)->buildProposal($docId);

        $this->assertTrue($proposal['ktime_available']);
        $this->assertFalse($proposal['supplier']['known']);
        $this->assertNull($proposal['supplier']['match']);
        // Toutes les lignes sont non_introduit (ventilationMap vide — aucun fournisseur trouvé)
        $this->assertSame('non_introduit', $proposal['lines'][0]['ventilation']);
    }

    // =========================================================================
    // buildProposal — K-Time indisponible
    // =========================================================================

    /**
     * Vérifie que buildProposal retourne ktime_available=false sans lever d'exception
     * quand K-Time est injoignable (transport retourne status 0).
     */
    public function testBuildProposalKTimeUnavailable(): void
    {
        $service = $this->makeService($this->makeUnavailableTransport());

        $docId = $this->insertDocument();
        $this->insertLine($docId);

        $proposal = $service->buildProposal($docId);

        $this->assertFalse($proposal['ktime_available']);
        $this->assertFalse($proposal['supplier']['known']);
        // Les lignes sont présentes mais non ventilées (options affichées côté UI)
        $this->assertCount(1, $proposal['lines']);
        $this->assertSame('non_introduit', $proposal['lines'][0]['ventilation']);
    }

    // =========================================================================
    // buildProposal — facture déjà existante
    // =========================================================================

    public function testBuildProposalInvoiceAlreadyExists(): void
    {
        $transport = $this->makeTransport([
            '/api/ged/health'                    => self::KTIME_HEALTH_OK,
            '/api/ged/suppliers/lookup'          => self::KTIME_LOOKUP_KNOWN,
            '/api/ged/suppliers/42/ventilation'  => self::KTIME_VENTILATION,
            '/api/ged/received-invoices/exists'  => self::KTIME_EXISTS_YES,
        ]);

        $docId = $this->insertDocument();
        $this->insertLine($docId);
        // Supplier name + invoice_number → lookup + invoiceExists déclenchés
        $this->insertExtractionResult($docId, 'Fournisseur SA', 'F-2026-889', 1234.50);

        $proposal = $this->makeService($transport)->buildProposal($docId);

        $this->assertTrue($proposal['ktime_available']);
        $this->assertTrue($proposal['supplier']['known']);
        // invoice_exists doit être non null (invoice_number non vide → appel invoiceExists)
        $this->assertIsArray($proposal['invoice_exists']);
        $this->assertTrue($proposal['invoice_exists']['exists']);
        $this->assertSame(311, $proposal['invoice_exists']['match']['id']);
    }

    // =========================================================================
    // submitToKTime
    // =========================================================================

    /**
     * Vérifie que submitToKTime construit un payload conforme (external_ref ged:doc:N)
     * et persiste le lien erp_links.
     */
    public function testSubmitToKTimeCreatesErpLink(): void
    {
        $capturedPayload = null;

        // Transport qui capture le payload envoyé
        $transport = function (string $method, string $url, array $opts) use (&$capturedPayload): array {
            if ($method === 'POST' && str_contains($url, '/api/ged/received-invoices')) {
                $capturedPayload = $opts['body'];
                return ['status' => 200, 'body' => json_encode(self::KTIME_CREATED)];
            }
            return ['status' => 404, 'body' => '{}'];
        };

        $docId = $this->insertDocument('Facture Fournisseur SA');
        $this->insertLine($docId, ['code' => 'F-889', 'description' => 'Vis 40mm', 'quantity' => 100.0, 'unit_price' => 4.50]);

        $choices = [
            'supplier_id' => 42,
            'total_ht'    => 1000.0,
            'currency'    => 'CHF',
            'lines'       => [],
        ];

        $result = $this->makeService($transport)->submitToKTime($docId, $choices);

        // Réponse K-Time
        $this->assertSame(512, $result['id']);
        $this->assertSame('draft', $result['status']);
        $this->assertFalse($result['duplicate']);

        // Payload conforme (external_ref spec §3.5)
        $this->assertIsArray($capturedPayload);
        $this->assertSame('ged:doc:' . $docId, $capturedPayload['external_ref']);
        $this->assertSame(['id' => 42], $capturedPayload['supplier']);
        $this->assertSame('CHF', $capturedPayload['currency']);

        // Lien persisté dans erp_links
        $row = $this->pdo->query(
            "SELECT * FROM erp_links WHERE document_id = {$docId} AND connector = 'ktime'"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'Le lien erp_links doit être persisté');
        $this->assertSame(512, (int) $row['external_id']);
        $this->assertSame('ged:doc:' . $docId, $row['external_ref']);
        $this->assertSame('draft', $row['status']);
        $this->assertNull($row['validation_status']);
    }

    /**
     * Vérifie l'idempotence : un second appel à submitToKTime met à jour erp_links
     * sans créer de doublon (même external_ref).
     */
    public function testSubmitToKTimeIsIdempotent(): void
    {
        $callCount = 0;

        $transport = function (string $method, string $url, array $opts) use (&$callCount): array {
            if ($method === 'POST' && str_contains($url, '/api/ged/received-invoices')) {
                $callCount++;
                $body = $callCount > 1 ? self::KTIME_CREATED_DUP : self::KTIME_CREATED;
                return ['status' => 200, 'body' => json_encode($body)];
            }
            return ['status' => 404, 'body' => '{}'];
        };

        $docId   = $this->insertDocument();
        $this->insertLine($docId);
        $service = $this->makeService($transport);
        $choices = ['supplier_id' => 42, 'total_ht' => 500.0, 'currency' => 'CHF', 'lines' => []];

        $service->submitToKTime($docId, $choices);
        $result2 = $service->submitToKTime($docId, $choices);

        // Second appel : K-Time renvoie duplicate=true
        $this->assertTrue($result2['duplicate']);

        // Une seule ligne dans erp_links (contrainte UNIQUE respectée)
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM erp_links WHERE document_id = {$docId}"
        )->fetchColumn();
        $this->assertSame(1, $count, 'Il ne doit exister qu\'un seul lien erp_links par document');
    }

    // =========================================================================
    // refreshStatus
    // =========================================================================

    /**
     * Vérifie que refreshStatus met à jour erp_links avec le statut validé
     * et retourne bon_pour_accord=true.
     */
    public function testRefreshStatusUpdatesToValidated(): void
    {
        $transport = $this->makeTransport([
            '/api/ged/received-invoices/512' => self::KTIME_INVOICE_VALIDATED,
        ]);

        $docId = $this->insertDocument();
        $this->insertErpLink($docId, 512, 'draft');

        $result = $this->makeService($transport)->refreshStatus($docId);

        // Résultat enrichi
        $this->assertTrue($result['bon_pour_accord']);
        $this->assertSame('Olivier P.', $result['validated_by_name']);
        $this->assertSame('2026-07-03 17:40:12', $result['validated_at']);
        $this->assertSame($docId, $result['document_id']);

        // erp_links mis à jour
        $row = $this->pdo->query(
            "SELECT * FROM erp_links WHERE document_id = {$docId} AND connector = 'ktime'"
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('validated', $row['validation_status']);
        $this->assertSame('Olivier P.', $row['validated_by_name']);
        $this->assertSame('2026-07-03 17:40:12', $row['validated_at']);
        $this->assertSame('a_payer', $row['status']);
    }

    /**
     * Vérifie que refreshStatus retourne une erreur propre si aucun lien ERP n'existe.
     */
    public function testRefreshStatusNoLinkReturnsError(): void
    {
        $transport = $this->makeTransport([]);
        $docId     = $this->insertDocument();

        $result = $this->makeService($transport)->refreshStatus($docId);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame($docId, $result['document_id']);
    }

    /**
     * Vérifie que refreshStatus conserve status pending si non encore validé.
     */
    public function testRefreshStatusPendingDoesNotSetBonPourAccord(): void
    {
        $transport = $this->makeTransport([
            '/api/ged/received-invoices/512' => self::KTIME_INVOICE_PENDING,
        ]);

        $docId = $this->insertDocument();
        $this->insertErpLink($docId, 512, 'draft');

        $result = $this->makeService($transport)->refreshStatus($docId);

        $this->assertFalse($result['bon_pour_accord']);
    }

    // =========================================================================
    // Contrôleur — routes
    // =========================================================================

    /**
     * Vérifie que le contrôleur proposal retourne 200 JSON avec ktime_available.
     */
    public function testControllerProposalReturns200(): void
    {
        $transport  = $this->makeTransport([
            '/api/ged/health'           => self::KTIME_HEALTH_OK,
            '/api/ged/suppliers/lookup' => self::KTIME_LOOKUP_UNKNOWN,
        ]);

        $docId = $this->insertDocument();
        $this->insertLine($docId);

        $ctrl     = $this->makeController($this->makeService($transport));
        $response = $ctrl->proposal(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['documentId' => (string) $docId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);
        $this->assertArrayHasKey('ktime_available', $data);
        $this->assertArrayHasKey('supplier', $data);
        $this->assertArrayHasKey('lines', $data);
    }

    /**
     * Vérifie que le contrôleur proposal retourne 400 pour un documentId invalide.
     */
    public function testControllerProposalInvalidId(): void
    {
        $ctrl     = $this->makeController($this->makeService($this->makeTransport([])));
        $response = $ctrl->proposal(
            $this->createMockRequest('GET'),
            $this->createMockResponse(),
            ['documentId' => '0']
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * Vérifie que le contrôleur submit retourne 503 quand K-Time est indisponible.
     */
    public function testControllerSubmitReturns503WhenKTimeDown(): void
    {
        $docId = $this->insertDocument();
        $this->insertLine($docId);

        $ctrl     = $this->makeController($this->makeService($this->makeUnavailableTransport()));
        $response = $ctrl->submit(
            $this->createMockRequest('POST', [], ['supplier_id' => 42]),
            $this->createMockResponse(),
            ['documentId' => (string) $docId]
        );

        $this->assertSame(503, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);
        $this->assertFalse($data['ktime_available']);
    }

    /**
     * Vérifie que le contrôleur submit retourne 200 avec id K-Time quand tout va bien.
     */
    public function testControllerSubmitReturns200(): void
    {
        $transport = function (string $method, string $url, array $opts): array {
            if ($method === 'POST' && str_contains($url, '/api/ged/received-invoices')) {
                return ['status' => 200, 'body' => json_encode(self::KTIME_CREATED)];
            }
            return ['status' => 404, 'body' => '{}'];
        };

        $docId = $this->insertDocument();
        $this->insertLine($docId);

        $ctrl     = $this->makeController($this->makeService($transport));
        $response = $ctrl->submit(
            $this->createMockRequest('POST', [], ['supplier_id' => 42, 'currency' => 'CHF']),
            $this->createMockResponse(),
            ['documentId' => (string) $docId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->assertJsonResponse($response);
        $this->assertSame(512, $data['id']);
    }

    /**
     * Vérifie que le contrôleur refresh retourne 503 quand K-Time est indisponible.
     */
    public function testControllerRefreshReturns503WhenKTimeDown(): void
    {
        $docId = $this->insertDocument();
        $this->insertErpLink($docId, 512);

        $ctrl     = $this->makeController($this->makeService($this->makeUnavailableTransport()));
        $response = $ctrl->refresh(
            $this->createMockRequest('POST'),
            $this->createMockResponse(),
            ['documentId' => (string) $docId]
        );

        $this->assertSame(503, $response->getStatusCode());
    }

    // =========================================================================
    // PluginRegistry
    // =========================================================================

    /**
     * Vérifie que le plugin est désactivé par défaut (ERPCONNECT_APP_ENABLED non posé).
     */
    public function testPluginRegistryDefaultsToDisabledForErpConnect(): void
    {
        $this->assertFalse(PluginRegistry::isEnabled('erpconnect'));
    }
}
