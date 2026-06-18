<?php

declare(strict_types=1);

namespace KDocs\Services;

/**
 * Client HTTP vers le sidecar ClearMyDocs (segmentation PDF multi-documents).
 *
 * @see docs/IA-CLEARMYDOCS-INTEGRATION.md
 */
class ClearMyDocsSidecarClient
{
    private const DEFAULT_TIMEOUT = 120;

    public function isEnabled(): bool
    {
        return filter_var(env('CLEARMYDOCS_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function baseUrl(): string
    {
        $url = env('CLEARMYDOCS_SIDECAR_URL');
        if ($url === null || $url === '') {
            $url = env('CLEARMYDOCS_API_URL', 'http://127.0.0.1:5101');
        }

        return rtrim((string) $url, '/');
    }

    public function isAvailable(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $response = $this->request('GET', '/health');
        return ($response['status'] ?? null) === 'ok';
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{
     *   should_split: bool,
     *   page_groups: list<array{start: int, end: int, label?: string, confidence?: float, doc_type?: string}>,
     *   segment_count: int,
     *   source: string
     * }|null null si sidecar indisponible ou erreur
     */
    public function segmentPdf(string $pdfPath, array $options = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!is_readable($pdfPath)) {
            return null;
        }

        $payload = [
            'pdf_path' => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pdfPath),
            'options' => array_merge(
                [
                    'profile' => 'legal_ch',
                    'min_pages_per_segment' => 1,
                    'use_llm_confirm' => false,
                ],
                $options
            ),
        ];

        $response = $this->request('POST', '/segment', $payload);
        if ($response === null || !isset($response['page_groups'])) {
            return null;
        }

        $groups = [];
        foreach ((array) $response['page_groups'] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groups[] = [
                'start' => (int) ($group['start'] ?? 0),
                'end' => (int) ($group['end'] ?? 0),
                'label' => (string) ($group['label'] ?? ''),
                'confidence' => (float) ($group['confidence'] ?? 0.0),
                'doc_type' => (string) ($group['doc_type'] ?? ''),
            ];
        }

        return [
            'should_split' => (bool) ($response['should_split'] ?? count($groups) > 1),
            'page_groups' => $groups,
            'segment_count' => (int) ($response['segment_count'] ?? count($groups)),
            'source' => (string) ($response['source'] ?? 'clearmydocs-segmenter'),
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null
     */
    public function request(string $method, string $path, ?array $body = null): ?array
    {
        $url = $this->baseUrl() . $path;
        $timeout = (int) env('CLEARMYDOCS_SIDECAR_TIMEOUT', self::DEFAULT_TIMEOUT);

        if (!function_exists('curl_init')) {
            return $this->requestViaStream($method, $url, $body, $timeout);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = ['Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
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

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null
     */
    private function requestViaStream(string $method, string $url, ?array $body, int $timeout): ?array
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
                'timeout' => $timeout,
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
