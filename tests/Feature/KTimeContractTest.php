<?php

declare(strict_types=1);

/**
 * Oracle unitaire du contrat /api/ged/* (gouvernance/contrat-ged-ktime.json).
 *
 * Garantit que le contrat ne peut pas deriver en silence cote GEDv1 : chaque route
 * declaree correspond a un appel reel dans KTimeClient.php, et reciproquement aucune
 * methode du client n'appelle un chemin /api/ged/* absent du contrat.
 *
 * Purement statique — lecture de fichiers locaux, AUCUN reseau. La preuve d'integration
 * reelle (depot K-Time sur disque + serveur K-Time vivant) est le linter externe :
 * tools/lint-contrat.mjs (node tools/lint-contrat.mjs).
 *
 * Spec de reference : K-TIME/docs/SPEC-GED-INTEGRATION.md §3.
 */

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class KTimeContractTest extends TestCase
{
    private const CONTRACT_PATH = __DIR__ . '/../../governance/contrat-ged-ktime.json';
    private const CLIENT_PATH   = __DIR__ . '/../../apps/erpconnect/Services/KTimeClient.php';

    /** @var array<string,mixed> */
    private array $contract;

    /** @var list<array{clientMethod:string, method:string, path:string}> */
    private array $calls;

    protected function setUp(): void
    {
        $this->assertFileExists(self::CONTRACT_PATH, 'Le contrat governance/contrat-ged-ktime.json doit exister');
        $this->assertFileExists(self::CLIENT_PATH, 'apps/erpconnect/Services/KTimeClient.php doit exister');

        $decoded = json_decode((string) file_get_contents(self::CONTRACT_PATH), true);
        $this->assertIsArray($decoded, 'Le contrat doit etre du JSON valide');
        $this->contract = $decoded;

        $this->calls = $this->parseClientCalls((string) file_get_contents(self::CLIENT_PATH));
    }

    // -------------------------------------------------------------------------
    // Contrat -> client : chaque route declaree a un appel reel correspondant
    // -------------------------------------------------------------------------

    public function testEachContractRouteHasAMatchingClientCall(): void
    {
        $byMethodName = [];
        foreach ($this->calls as $call) {
            $byMethodName[$call['clientMethod']] = $call;
        }

        foreach ($this->contract['routes'] as $route) {
            $clientMethod = $route['client_method'];

            $this->assertArrayHasKey(
                $clientMethod,
                $byMethodName,
                "Le contrat declare la route {$route['method']} {$route['path']} via " .
                "KTimeClient::{$clientMethod}(), mais cette methode n'appelle aucun endpoint /api/ged/*"
            );

            $call = $byMethodName[$clientMethod];

            $this->assertSame(
                $route['method'],
                $call['method'],
                "KTimeClient::{$clientMethod}() doit appeler {$route['method']} (contrat), " .
                "trouve {$call['method']}"
            );

            $this->assertSame(
                $route['path'],
                $call['path'],
                "KTimeClient::{$clientMethod}() doit appeler {$route['path']} (contrat), " .
                "trouve {$call['path']}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Client -> contrat : aucun appel /api/ged/* hors contrat
    // -------------------------------------------------------------------------

    public function testNoClientCallEscapesTheContract(): void
    {
        $contractKeys = [];
        foreach ($this->contract['routes'] as $route) {
            $contractKeys[$route['method'] . ':' . $route['path']] = true;
        }

        foreach ($this->calls as $call) {
            $key = $call['method'] . ':' . $call['path'];
            $this->assertArrayHasKey(
                $key,
                $contractKeys,
                "KTimeClient::{$call['clientMethod']}() appelle {$call['method']} {$call['path']}, " .
                'absent du contrat governance/contrat-ged-ktime.json'
            );
        }
    }

    /**
     * Sanity check : le contrat porte bien les 8 routes attendues (spec §3 + addendum GED-6),
     * ni plus ni moins — evite qu'une route disparaisse silencieusement du contrat.
     */
    public function testContractHasExactlyEightRoutes(): void
    {
        $this->assertCount(8, $this->contract['routes']);
    }

    public function testAuthHeaderIsDocumented(): void
    {
        $this->assertSame('X-Api-Key', $this->contract['auth']['header'] ?? null);
        $this->assertSame('KTIME_GED_API_KEY', $this->contract['auth']['env_var'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Parsing statique de KTimeClient.php (aucun reseau)
    // -------------------------------------------------------------------------

    /**
     * Extrait, pour chaque methode publique du client, le couple {method, path}
     * reellement envoye a $this->request(...). Les fragments interpoles ('.$id.')
     * sont normalises en '{id}' pour se comparer au gabarit du contrat.
     *
     * @return list<array{clientMethod:string, method:string, path:string}>
     */
    private function parseClientCalls(string $source): array
    {
        // Decoupe la classe en blocs par methode publique.
        preg_match_all('~public function\s+(\w+)\s*\(~', $source, $methodMatches, PREG_OFFSET_CAPTURE);

        $boundaries = [];
        foreach ($methodMatches[1] as $mm) {
            $boundaries[] = ['name' => $mm[0], 'start' => $mm[1]];
        }
        $boundaries[] = ['name' => null, 'start' => strlen($source)];

        $calls = [];
        $requestRe = "~->request\\(\\s*'(GET|POST|PUT|DELETE|PATCH)'\\s*,\\s*((?:\\s*\\.?\\s*'[^']*'|\\s*\\.\\s*\\\$[A-Za-z_][A-Za-z0-9_]*)+)~";

        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $name  = $boundaries[$i]['name'];
            $start = $boundaries[$i]['start'];
            $end   = $boundaries[$i + 1]['start'];
            $chunk = substr($source, $start, $end - $start);

            if (!preg_match($requestRe, $chunk, $callMatch)) {
                continue;
            }

            $httpMethod = $callMatch[1];
            $rawExpr    = $callMatch[2];

            // Chaque fragment est soit un litteral entre quotes (groupe 1, capture
            // toujours presente meme vide), soit une variable $xxx (groupe 2). On
            // distingue via la marque de debut du match : une quote ou un $.
            preg_match_all("~'([^']*)'|\\\$([A-Za-z_][A-Za-z0-9_]*)~", $rawExpr, $pieceMatches, PREG_SET_ORDER);

            $normalizedPath = '';
            foreach ($pieceMatches as $piece) {
                if ($piece[0][0] === "'") {
                    $normalizedPath .= $piece[1]; // fragment litteral (peut etre vide)
                } else {
                    $normalizedPath .= '{id}'; // variable interpolee ($id, $supplierId, ...)
                }
            }

            $qPos = strpos($normalizedPath, '?');
            if ($qPos !== false) {
                $normalizedPath = substr($normalizedPath, 0, $qPos);
            }

            $calls[] = ['clientMethod' => $name, 'method' => $httpMethod, 'path' => $normalizedPath];
        }

        return $calls;
    }
}
