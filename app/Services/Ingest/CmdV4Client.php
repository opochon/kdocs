<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

/**
 * Client HTTP vers l'API ClearMyDocs v4 (projets + jobs async).
 *
 * Contrat officiel : `clearmydocs-v3/cmdv4/docs/API.md` (Swagger `/docs`, OpenAPI `/openapi.json`).
 * Spec GED : `docs/CMD-V4-CONNECTOR.md`, lot P2 `docs/CONNECTEURS-PLUGINS.md`.
 */

class CmdV4Client

{

    private const DEFAULT_JOB_TIMEOUT = 300;

    public function isEnabled(): bool

    {

        return filter_var(env('CMD_V4_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

    }

    public function baseUrl(): string

    {

        $url = env('CMD_V4_URL', 'http://127.0.0.1:8510');

        return rtrim((string) $url, '/');

    }

    public function jobTimeoutSeconds(): int

    {

        return max(30, (int) env('CMD_V4_JOB_TIMEOUT', self::DEFAULT_JOB_TIMEOUT));

    }

    public function projectProfile(): string

    {

        $profile = trim((string) env('CMD_V4_PROJECT_PROFILE', 'legal_ch'));

        return $profile !== '' ? $profile : 'legal_ch';

    }

    /** @return array{ok: bool, version?: string, app?: string, error?: string}|null */

    public function health(): ?array

    {

        if (!$this->isEnabled()) {

            return null;

        }

        $response = $this->request('GET', '/api/health');

        if ($response === null) {

            return ['ok' => false, 'error' => 'health_unreachable'];

        }

        return [

            'ok' => ($response['ok'] ?? false) === true,

            'version' => isset($response['version']) ? (string) $response['version'] : null,

            'app' => isset($response['app']) ? (string) $response['app'] : null,

        ];

    }

    /**

     * @return array{slug: string, source_dir: string}|null

     */

    public function createProject(string $name, string $sourceDir, ?string $profile = null): ?array

    {

        if (!$this->isEnabled() || !is_dir($sourceDir)) {

            return null;

        }

        $response = $this->request('POST', '/api/projects', [

            'name' => $name,

            'source_dir' => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceDir),

            'profile' => $profile ?? $this->projectProfile(),

            'report_style' => 'bullets',

        ]);

        if ($response === null || empty($response['slug'])) {

            return null;

        }

        return [

            'slug' => (string) $response['slug'],

            'source_dir' => (string) ($response['source_dir'] ?? $sourceDir),

        ];

    }

    public function deleteProject(string $slug): bool

    {

        if (!$this->isEnabled() || $slug === '') {

            return false;

        }

        $response = $this->request('DELETE', '/api/projects/' . rawurlencode($slug));

        return is_array($response) && ($response['ok'] ?? false) === true;

    }

    /** @return array{job_id: string}|null */

    public function startExtract(string $slug): ?array

    {

        return $this->startJob('POST', '/api/projects/' . rawurlencode($slug) . '/extract');

    }

    /** @return array{job_id: string}|null */

    public function startSynthesize(string $slug): ?array

    {

        return $this->startJob('POST', '/api/projects/' . rawurlencode($slug) . '/synthesize', []);

    }

    /** @return array{job_id: string}|null */

    public function startIndex(string $slug): ?array

    {

        return $this->startJob('POST', '/api/projects/' . rawurlencode($slug) . '/index', []);

    }

    /** @return array<string, mixed>|null */

    public function getJob(string $jobId): ?array

    {

        if (!$this->isEnabled() || $jobId === '') {

            return null;

        }

        return $this->request('GET', '/api/jobs/' . rawurlencode($jobId));

    }

    /**

     * Attend la fin d'un job (poll 1 s).

     *

     * @return array<string, mixed>|null Job final ou null (timeout / erreur)

     */

    public function waitForJob(string $jobId, ?int $timeoutSeconds = null): ?array

    {

        $deadline = time() + ($timeoutSeconds ?? $this->jobTimeoutSeconds());

        while (time() <= $deadline) {

            $job = $this->getJob($jobId);

            if ($job === null) {

                return null;

            }

            $status = (string) ($job['status'] ?? '');

            if ($status === 'done') {

                return $job;

            }

            if ($status === 'error') {

                return $job;

            }

            sleep(1);

        }

        return null;

    }

    /** @return array<string, mixed>|null */

    public function getDocumentFields(string $slug, int $docId): ?array

    {

        if (!$this->isEnabled() || $slug === '') {

            return null;

        }

        $response = $this->request('GET', '/api/projects/' . rawurlencode($slug) . '/fields/' . $docId);

        return is_array($response) && isset($response['fields']) ? $response : null;

    }

    /**
     * Étape 6 — analyse fichier unique (POST /api/analyze-file).
     *
     * @return array{job_id: string, slug: string}|null
     */
    public function analyzeFile(string $path, ?string $profile = null): ?array
    {
        if (!$this->isEnabled() || !is_file($path)) {
            return null;
        }

        $response = $this->request('POST', '/api/analyze-file', [
            'path' => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path),
            'profile' => $profile ?? $this->projectProfile(),
        ]);

        if ($response === null || empty($response['job_id']) || empty($response['slug'])) {
            return null;
        }

        return [
            'job_id' => (string) $response['job_id'],
            'slug' => (string) $response['slug'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function getAnnexe(string $slug): ?array
    {
        if (!$this->isEnabled() || $slug === '') {
            return null;
        }

        $response = $this->request('GET', '/api/projects/' . rawurlencode($slug) . '/annexe');

        return is_array($response) && isset($response['annexe_md']) ? $response : null;
    }

    /** @return array<string, mixed>|null */
    public function getDocsManifest(string $slug): ?array
    {
        if (!$this->isEnabled() || $slug === '') {
            return null;
        }

        $response = $this->request('GET', '/api/projects/' . rawurlencode($slug) . '/docs');

        return is_array($response) ? $response : null;
    }

    /** @return array<string, mixed>|null */
    public function getFidelity(string $slug): ?array
    {
        if (!$this->isEnabled() || $slug === '') {
            return null;
        }

        return $this->request('GET', '/api/projects/' . rawurlencode($slug) . '/fidelity');
    }

    /** @return array<string, mixed>|null */
    public function getFreshness(string $slug): ?array
    {
        if (!$this->isEnabled() || $slug === '') {
            return null;
        }

        return $this->request('GET', '/api/projects/' . rawurlencode($slug) . '/freshness');
    }

    /**

     * @param array<string, mixed>|null $body

     *

     * @return array<string, mixed>|null

     */

    public function request(string $method, string $path, ?array $body = null): ?array

    {

        $url = $this->baseUrl() . $path;

        if (!function_exists('curl_init')) {

            return $this->requestViaStream($method, $url, $body);

        }

        $ch = curl_init($url);

        if ($ch === false) {

            return null;

        }

        $headers = ['Accept: application/json'];

        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_CONNECTTIMEOUT => 5,

            CURLOPT_TIMEOUT => min(60, $this->jobTimeoutSeconds()),

            CURLOPT_CUSTOMREQUEST => strtoupper($method),

        ]);

        if ($body !== null) {

            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $headers[] = 'Content-Type: application/json';

            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {

            return null;

        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;

    }

    /** @return array{job_id: string}|null */

    private function startJob(string $method, string $path, ?array $body = null): ?array

    {

        if (!$this->isEnabled()) {

            return null;

        }

        $response = $this->request($method, $path, $body);

        if ($response === null || empty($response['job_id'])) {

            return null;

        }

        return ['job_id' => (string) $response['job_id']];

    }

    /**

     * @param array<string, mixed>|null $body

     *

     * @return array<string, mixed>|null

     */

    private function requestViaStream(string $method, string $url, ?array $body): ?array

    {

        $content = null;

        $headerLines = "Accept: application/json\r\n";

        if ($body !== null) {

            $content = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $headerLines .= "Content-Type: application/json\r\n";

        }

        $context = stream_context_create([

            'http' => [

                'method' => strtoupper($method),

                'header' => $headerLines,

                'content' => $content ?? '',

                'timeout' => min(60, $this->jobTimeoutSeconds()),

                'ignore_errors' => true,

            ],

        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {

            return null;

        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;

    }

}
