<?php

declare(strict_types=1);

/**
 * Scanner antivirus ClamAV via protocole clamd INSTREAM (GAP-045).
 *
 * Envoie le fichier au démon clamd sur socket TCP en utilisant le protocole
 * INSTREAM (commande « zINSTREAM\0 », chunks de 8 Ko avec longueur big-endian,
 * chunk terminal 4 octets zéro). Retourne l'état du scan.
 *
 * Le transport est injectable pour permettre des tests hermétiques sans clamd.
 *
 * Politique fail-open : si le scan échoue techniquement (clamd indisponible,
 * timeout, erreur réseau), l'appelant doit attraper l'exception et laisser
 * passer l'upload (voir DocumentsController::apiUpload). Un virus avéré (FOUND)
 * ne lève PAS d'exception : il est retourné via clean=false pour que l'appelant
 * prenne la décision (bloquer ou non).
 *
 * @see https://manpages.ubuntu.com/manpages/focal/man8/clamd.8.html Protocol INSTREAM
 */

namespace KDocs\Services;

class ClamAvScanner
{
    private const CHUNK_SIZE = 8192;

    /** @var callable(string $payload): string */
    private $transport;

    private string $host;
    private int $port;
    private bool $enabled;

    /**
     * @param callable|null $transport Transport injectable : fn(string $payload): string
     *                                 Reçoit le payload INSTREAM complet (binaire),
     *                                 retourne la réponse clamd (ex. "stream: OK\n").
     *                                 Défaut = socket TCP vers clamd.
     * @param string|null   $host      Hôte clamd (défaut = env('CLAMAV_HOST', '127.0.0.1')).
     * @param int|null      $port      Port clamd (défaut = env('CLAMAV_PORT', 3310)).
     * @param bool|null     $enabled   Activer le scan (défaut = env('CLAMAV_ENABLED', false)).
     */
    public function __construct(
        ?callable $transport = null,
        ?string $host = null,
        ?int $port = null,
        ?bool $enabled = null
    ) {
        // host/port/enabled d'abord : le transport par défaut lit ces propriétés.
        $this->host      = $host    ?? (string) env('CLAMAV_HOST', '127.0.0.1');
        $this->port      = $port    ?? (int)    env('CLAMAV_PORT', 3310);
        $this->enabled   = $enabled ?? filter_var(env('CLAMAV_ENABLED', false), \FILTER_VALIDATE_BOOLEAN);
        $this->transport = $transport ?? $this->buildDefaultTransport();
    }

    /**
     * Scanne un fichier via clamd INSTREAM.
     *
     * @param string $filePath Chemin absolu du fichier à scanner.
     *
     * @return array{clean: bool, signature: string|null, skipped: bool}
     *   - clean     : true si propre ou si scan ignoré (skipped).
     *   - signature : nom de la signature si virus détecté, null sinon.
     *   - skipped   : true si ClamAV est désactivé (pas de régression upload).
     *
     * @throws \InvalidArgumentException Si le fichier est introuvable.
     * @throws \RuntimeException         Si clamd retourne une erreur (ERROR).
     */
    public function scan(string $filePath): array
    {
        // Scanner désactivé → fail-open proprement (pas de régression upload)
        if (!$this->enabled) {
            return ['clean' => true, 'signature' => null, 'skipped' => true];
        }

        if (!is_file($filePath)) {
            throw new \InvalidArgumentException("Fichier introuvable : {$filePath}");
        }

        $payload  = $this->buildInStreamPayload($filePath);
        $response = ($this->transport)($payload);

        return $this->parseResponse($response);
    }

    /**
     * Indique si le scanner est activé.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * Construit le payload INSTREAM clamd :
     *   zINSTREAM\0  — commande null-terminée
     *   [4 octets BE][données]... — chunks de max CHUNK_SIZE octets
     *   \x00\x00\x00\x00          — chunk terminal (taille 0)
     *
     * @return string Payload binaire complet.
     */
    private function buildInStreamPayload(string $filePath): string
    {
        $payload = "zINSTREAM\0";

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Impossible d'ouvrir le fichier : {$filePath}");
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                // 4 octets big-endian = taille du chunk
                $payload .= pack('N', strlen($chunk)) . $chunk;
            }
        } finally {
            fclose($handle);
        }

        // Chunk terminal : 4 octets zéro
        $payload .= "\x00\x00\x00\x00";

        return $payload;
    }

    /**
     * Parse la réponse clamd INSTREAM.
     *
     * Formats attendus :
     *   "stream: OK\n"
     *   "stream: Eicar-Test-Signature FOUND\n"
     *   "stream: ERROR: ...\n"
     *
     * @return array{clean: bool, signature: string|null, skipped: bool}
     *
     * @throws \RuntimeException Sur réponse ERROR.
     */
    private function parseResponse(string $response): array
    {
        $response = trim($response);

        // Réponse propre
        if (str_ends_with($response, 'OK')) {
            return ['clean' => true, 'signature' => null, 'skipped' => false];
        }

        // Virus détecté : "stream: Nom-Signature FOUND"
        if (str_ends_with($response, 'FOUND')) {
            // Extraire le nom de la signature (entre ": " et " FOUND")
            $signature = null;
            if (preg_match('/^stream:\s+(.+)\s+FOUND$/i', $response, $m)) {
                $signature = $m[1];
            }
            return ['clean' => false, 'signature' => $signature, 'skipped' => false];
        }

        // Erreur clamd
        if (str_contains($response, 'ERROR')) {
            throw new \RuntimeException("Erreur clamd : {$response}");
        }

        // Réponse inattendue — lever une exception pour fail-open dans l'appelant
        throw new \RuntimeException("Réponse clamd inattendue : {$response}");
    }

    /**
     * Transport par défaut : socket TCP vers clamd INSTREAM.
     *
     * Non utilisé en test (transport injectable passé au constructeur).
     *
     * @return callable(string): string
     */
    private function buildDefaultTransport(): callable
    {
        return function (string $payload): string {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
            if ($socket === false) {
                throw new \RuntimeException(
                    "Impossible de se connecter à clamd {$this->host}:{$this->port} — {$errstr} ({$errno})"
                );
            }

            fwrite($socket, $payload);
            $response = '';
            while (!feof($socket)) {
                $response .= fread($socket, 1024);
            }
            fclose($socket);

            return $response;
        };
    }
}
