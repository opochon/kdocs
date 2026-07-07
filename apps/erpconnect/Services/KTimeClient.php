<?php

declare(strict_types=1);

namespace KDocs\Apps\Erpconnect\Services;

/**
 * Client HTTP vers l'API K-Time /api/ged/* (spec §3 de SPEC-GED-INTEGRATION.md).
 *
 * Transport injectable :
 *   fn(string $method, string $url, array $opts): array{status:int, body:string}
 * pour tests hermétiques SANS réseau ; défaut curl (fallback stream).
 *
 * Config env :
 *   KTIME_URL          (défaut http://127.0.0.1:8090)
 *   KTIME_GED_API_KEY  (header X-Api-Key)
 */
class KTimeClient
{
    private const TIMEOUT_CONNECT = 5;
    private const TIMEOUT_READ    = 5;

    /** @var callable|null */
    private $transport;

    /**
     * @param callable|null $transport fn(string $method, string $url, array $opts): array{status:int,body:string}
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    // -------------------------------------------------------------------------
    // Endpoints publics (spec §3)
    // -------------------------------------------------------------------------

    /**
     * GET /api/ged/health — §3.1
     *
     * Ne lève jamais KTimeUnavailableException : retourne ['ok' => false] si KTime est down.
     *
     * @return array{ok: bool, api_version: int, capabilities: list<string>}
     */
    public function health(): array
    {
        try {
            return $this->request('GET', '/api/ged/health');
        } catch (KTimeUnavailableException) {
            return ['ok' => false, 'api_version' => 0, 'capabilities' => []];
        }
    }

    /**
     * GET /api/ged/suppliers/lookup?q=…|iban=…|ad_numero=… — §3.2
     *
     * @param array{q?:string, iban?:string, ad_numero?:string} $criteria
     * @return array{matches: list<array<string,mixed>>}
     * @throws KTimeUnavailableException
     */
    public function lookupSupplier(array $criteria): array
    {
        $qs = http_build_query(array_filter($criteria, static fn ($v) => $v !== null && $v !== ''));
        return $this->request('GET', '/api/ged/suppliers/lookup?' . $qs);
    }

    /**
     * GET /api/ged/suppliers/{id}/ventilation — §3.3
     *
     * @return array{supplier_id: int, articles: list<array<string,mixed>>, ventilation_types: list<string>}
     * @throws KTimeUnavailableException
     */
    public function ventilation(int $supplierId): array
    {
        return $this->request('GET', '/api/ged/suppliers/' . $supplierId . '/ventilation');
    }

    /**
     * GET /api/ged/received-invoices/exists?supplier_id=…&supplier_ref=…&amount=… — §3.4
     *
     * @return array{exists: bool, match?: array<string,mixed>}
     * @throws KTimeUnavailableException
     */
    public function invoiceExists(int $supplierId, string $supplierRef, float $amount): array
    {
        $qs = http_build_query([
            'supplier_id'  => $supplierId,
            'supplier_ref' => $supplierRef,
            'amount'       => $amount,
        ]);
        return $this->request('GET', '/api/ged/received-invoices/exists?' . $qs);
    }

    /**
     * POST /api/ged/received-invoices — §3.5
     *
     * Idempotent : le même external_ref retourne la facture existante (duplicate: true).
     *
     * @param array<string, mixed> $payload
     * @return array{id: int, status: string, validation_status: string, duplicate: bool}
     * @throws KTimeUnavailableException
     */
    public function createReceivedInvoice(array $payload): array
    {
        return $this->request('POST', '/api/ged/received-invoices', $payload);
    }

    /**
     * GET /api/ged/received-invoices/{id} — §3.6
     *
     * @return array{id: int, external_ref: string, status: string,
     *               validation_status: string|null,
     *               validated_by: array{id:int,name:string}|null,
     *               validated_at: string|null}
     * @throws KTimeUnavailableException
     */
    public function getReceivedInvoice(int $id): array
    {
        return $this->request('GET', '/api/ged/received-invoices/' . $id);
    }

    /**
     * POST /api/ged/received-invoices/{id}/block — GED-6
     * Demande de blocage AVEC cause (kind ∈ note_credit|correction_facture|blocage_paiement).
     *
     * @return array{ok:bool, id:int, validation_status:string, block:array<string,mixed>|null}
     * @throws KTimeUnavailableException
     */
    public function blockReceivedInvoice(int $id, string $kind, string $cause): array
    {
        return $this->request('POST', '/api/ged/received-invoices/' . $id . '/block', [
            'kind'  => $kind,
            'cause' => $cause,
        ]);
    }

    /**
     * POST /api/ged/received-invoices/{id}/partial-validate — GED-6
     *
     * @param list<int> $confirmedAllocationIds
     * @return array{ok:bool, id:int, validation_status:string, confirmed:int, pending:int}
     * @throws KTimeUnavailableException
     */
    public function partialValidate(int $id, array $confirmedAllocationIds, ?string $note = null): array
    {
        return $this->request('POST', '/api/ged/received-invoices/' . $id . '/partial-validate', [
            'confirmed_allocation_ids' => array_values($confirmedAllocationIds),
            'note'                     => $note,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Exécute une requête HTTP via le transport injecté ou curl/stream.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws KTimeUnavailableException si status 0 (réseau) ou >= 500
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url  = $this->baseUrl() . $path;
        $opts = [
            'headers'         => [
                'Accept'    => 'application/json',
                'X-Api-Key' => $this->apiKey(),
            ],
            'timeout_connect' => self::TIMEOUT_CONNECT,
            'timeout_read'    => self::TIMEOUT_READ,
            'body'            => $body,
        ];

        $raw = $this->transport !== null
            ? ($this->transport)($method, $url, $opts)
            : $this->curlRequest($method, $url, $opts);

        if ($raw['status'] === 0 || $raw['status'] >= 500) {
            throw new KTimeUnavailableException(
                sprintf('K-Time indisponible : %s %s → HTTP %d', $method, $path, $raw['status'])
            );
        }

        $decoded = json_decode($raw['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    private function baseUrl(): string
    {
        return rtrim((string) env('KTIME_URL', 'http://127.0.0.1:8090'), '/');
    }

    private function apiKey(): string
    {
        return (string) env('KTIME_GED_API_KEY', '');
    }

    /**
     * @param array<string, mixed> $opts
     * @return array{status: int, body: string}
     */
    private function curlRequest(string $method, string $url, array $opts): array
    {
        if (!function_exists('curl_init')) {
            return $this->streamRequest($method, $url, $opts);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }

        $headers = [
            'Accept: application/json',
            'X-Api-Key: ' . ($opts['headers']['X-Api-Key'] ?? ''),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int) ($opts['timeout_connect'] ?? self::TIMEOUT_CONNECT),
            CURLOPT_TIMEOUT        => (int) ($opts['timeout_read']    ?? self::TIMEOUT_READ),
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ]);

        if ($opts['body'] !== null) {
            $json      = json_encode($opts['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $raw === false ? 0 : $code, 'body' => $raw === false ? '' : (string) $raw];
    }

    /**
     * @param array<string, mixed> $opts
     * @return array{status: int, body: string}
     */
    private function streamRequest(string $method, string $url, array $opts): array
    {
        $headerStr = "Accept: application/json\r\nX-Api-Key: " . ($opts['headers']['X-Api-Key'] ?? '') . "\r\n";
        $bodyStr   = null;

        if ($opts['body'] !== null) {
            $bodyStr    = json_encode($opts['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headerStr .= "Content-Type: application/json\r\n";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => strtoupper($method),
                'header'        => $headerStr,
                'content'       => $bodyStr ?? '',
                'timeout'       => (int) ($opts['timeout_read'] ?? self::TIMEOUT_READ),
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['status' => 0, 'body' => ''];
        }

        $code = 0;
        if (isset($http_response_header[0])
            && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        return ['status' => $code, 'body' => $raw];
    }
}
