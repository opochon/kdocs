<?php
/**
 * Client HTTP vers k-winbiz-bridge (phase A — stub sans bridge déployé).
 */

namespace KDocs\Services\WinBiz;

use KDocs\Core\Config;

class WinBizBridgeClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) env('WINBIZ_BRIDGE_URL', ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    /**
     * @return array{ok: bool, status?: int, body?: mixed, error?: string}
     */
    public function health(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'WINBIZ_BRIDGE_URL not configured'];
        }

        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => $err];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => json_decode((string) $body, true) ?? $body,
        ];
    }
}
