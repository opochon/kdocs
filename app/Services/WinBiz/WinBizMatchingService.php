<?php
/**
 * Rapprochement facture ↔ WinBiz (phase A — stub, bridge requis pour données live).
 */

namespace KDocs\Services\WinBiz;

class WinBizMatchingService
{
    public function __construct(
        private ?WinBizBridgeClient $bridge = null
    ) {
        $this->bridge ??= new WinBizBridgeClient();
    }

    /**
     * @return array{matched: bool, matches: array, gaps: array, blocked?: string}
     */
    public function matchDocumentToWinBiz(int $documentId): array
    {
        if (!$this->bridge->isConfigured()) {
            return [
                'matched' => false,
                'matches' => [],
                'gaps' => [],
                'blocked' => 'WINBIZ_BRIDGE_URL not configured — deploy k-winbiz-bridge',
            ];
        }

        $health = $this->bridge->health();
        if (!$health['ok']) {
            return [
                'matched' => false,
                'matches' => [],
                'gaps' => [],
                'blocked' => $health['error'] ?? 'Bridge unreachable',
            ];
        }

        // Extension future : appels BL/articles via bridge
        return [
            'matched' => false,
            'matches' => [],
            'gaps' => ['matching_not_implemented' => true],
        ];
    }
}
