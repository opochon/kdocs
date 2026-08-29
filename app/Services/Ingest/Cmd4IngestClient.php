<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

/**
 * Client du moteur d'ingestion partage `cmd4_ingest` (Python).
 *
 * Le meme moteur sert CMD v4 (import direct) et la GED (cette classe, en CLI) :
 * il n'existe pas deux implementations a maintenir alignees, donc pas de derive
 * de performance d'un cote ou de l'autre.
 *
 * Rien de reseau : un processus, du JSON sur stdout.
 */
class Cmd4IngestClient
{
    private string $python;
    private string $productDir;
    private int $timeout;

    public function __construct(?string $python = null, ?string $productDir = null)
    {
        $this->productDir = $productDir ?? $this->guessProductDir();
        $this->python = $python ?? $this->guessPython();
        $this->timeout = (int) (env('CMD4_INGEST_TIMEOUT', 1800));
    }

    private function guessProductDir(): string
    {
        $configured = (string) env('CMD4_INGEST_PATH', '');
        if ($configured !== '' && is_dir($configured)) {
            return rtrim($configured, '\/');
        }
        // Repli : le module vit a cote du produit CMD v4 deja declare dans .env.
        $v4 = (string) env('CMD_V4_PATH', '');
        return $v4 !== '' ? rtrim($v4, '\/') : '';
    }

    private function guessPython(): string
    {
        $configured = (string) env('CMD4_PYTHON', '');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        // Le venv du produit porte les dependances (pymupdf, zxing-cpp, pytesseract).
        $venv = $this->productDir . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR
              . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        if (is_file($venv)) {
            return $venv;
        }
        $posix = $this->productDir . '/.venv/bin/python';
        return is_file($posix) ? $posix : 'python';
    }

    public function isAvailable(): bool
    {
        if ($this->productDir === '' || !is_dir($this->productDir . DIRECTORY_SEPARATOR . 'cmd4_ingest')) {
            return false;
        }
        return $this->python === 'python' || is_file($this->python);
    }

    public function describe(): array
    {
        return [
            'available' => $this->isAvailable(),
            'python' => $this->python,
            'product_dir' => $this->productDir,
            'module' => $this->productDir . DIRECTORY_SEPARATOR . 'cmd4_ingest',
        ];
    }

    /** Texte page par page. */
    public function pages(string $pdf, array $options = []): ?array
    {
        return $this->run('pages', $pdf, $options);
    }

    /** Codes 1D/2D par page. */
    public function barcodes(string $pdf, array $options = []): ?array
    {
        return $this->run('barcodes', $pdf, $options);
    }

    /** Plan de decoupage (groupes de pages + raison de chaque frontiere). */
    public function split(string $pdf, array $options = []): ?array
    {
        return $this->run('split', $pdf, $options);
    }

    /** Decoupage + qualification facture + ecriture d'un PDF par document. */
    public function ingest(string $pdf, ?string $outDir = null, array $options = []): ?array
    {
        if ($outDir !== null) {
            $options['out-dir'] = $outDir;
        }
        return $this->run('ingest', $pdf, $options);
    }

    /**
     * @param array<string, mixed> $options no-vision, force-ocr, langs, out-dir
     * @return array<string, mixed>|null
     */
    private function run(string $command, string $pdf, array $options = []): ?array
    {
        if (!$this->isAvailable() || !is_file($pdf)) {
            return null;
        }

        $args = [
            escapeshellarg($this->python), '-X', 'utf8', '-m', 'cmd4_ingest',
            escapeshellarg($command), escapeshellarg($pdf),
        ];
        foreach ($options as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $args[] = '--' . $key;
            if ($value !== true) {
                $args[] = escapeshellarg((string) $value);
            }
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(implode(' ', $args), $descriptors, $pipes, $this->productDir, $this->environment());
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($stdout === '') {
            error_log("Cmd4IngestClient {$command}: code {$code} - " . substr($stderr, 0, 500));
            return null;
        }

        $decoded = $this->decodePayload($stdout, $command);
        if (!is_array($decoded)) {
            return null;
        }

        if (!empty($stderr)) {
            $decoded['stderr'] = substr($stderr, 0, 1000);
        }
        return $decoded;
    }

    /**
     * Extrait le payload JSON d'une sortie qui n'est pas du JSON pur.
     *
     * Une dependance du moteur (pymupdf4llm) imprime ses bannieres de diagnostic
     * (« === Document parser messages === / Using Tesseract... ») sur STDOUT, avant
     * le JSON. Un json_decode() direct rend alors null : le moteur parait absent,
     * la decoupe retombe en silence sur le pipeline PHP et la qualification facture
     * (QR, montants, IBAN) est perdue pour la GED — mesure le 2026-08-29, bannieres
     * apparues avec la mise a jour du venv ClearMyDocs du meme matin.
     *
     * Trois tentatives : decode direct, puis tranche du premier '{' au dernier '}'
     * (couvre le bruit avant ET apres le payload), puis scan de chaque '{' en
     * gardant le premier objet complet — une banniere contenant un petit JSON ne
     * doit pas masquer le vrai payload.
     *
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $stdout, string $command): ?array
    {
        $direct = json_decode($stdout, true);
        if (is_array($direct)) {
            return $direct;
        }

        $start = strpos($stdout, '{');
        $end = strrpos($stdout, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = json_decode(substr($stdout, $start, $end - $start + 1), true);
            if (is_array($slice)) {
                return $slice;
            }
        }

        $offset = $start;
        while ($offset !== false && $offset < strlen($stdout)) {
            $offset = strpos($stdout, '{', $offset);
            if ($offset === false) {
                break;
            }
            $candidate = json_decode(substr($stdout, $offset), true);
            if (is_array($candidate)) {
                return $candidate;
            }
            $offset++;
        }

        error_log("Cmd4IngestClient {$command}: JSON introuvable dans la sortie - " . substr($stdout, 0, 200));

        return null;
    }

    /** Le moteur lit sa configuration IA dans l'environnement du processus. */
    private function environment(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_scalar($value)) {
                $env[$key] = (string) $value;
            }
        }
        $env['INFOMANIAK_AI_API_KEY'] = (string) env('INFOMANIAK_AI_API_KEY', '');
        $env['INFOMANIAK_AI_API_SECRET'] = (string) env('INFOMANIAK_AI_API_SECRET', '');
        $env['INFOMANIAK_VISION_MODEL'] = (string) env('INFOMANIAK_VISION_MODEL', 'google/gemma-4-31B-it');
        $env['CMD4_VISION_ENABLED'] = (string) env('CMD4_VISION_ENABLED', '1');
        $env['PATH'] = getenv('PATH') ?: '';
        $env['SYSTEMROOT'] = getenv('SYSTEMROOT') ?: 'C:\Windows';
        return $env;
    }
}
