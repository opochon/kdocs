<?php
/**
 * Registre des connecteurs et plugins — chemins et flags via .env uniquement.
 * Spec : docs/CONNECTEURS-PLUGINS.md
 */

return [
    'ingest' => [
        'ingest-native' => [
            'label' => 'Ingest natif GED',
            'description' => 'OCR PHP, workers, UnifiedClassifier — toujours disponible',
            'always' => true,
            'capabilities' => ['extract', 'ocr', 'thumbnail', 'classify'],
        ],
        'ingest-cmd-v3' => [
            'label' => 'ClearMyDocs v3 (sidecar)',
            'description' => 'Extraction, segment, enrich — remplace tout ou partie de l\'ingest si joignable',
            'enabled_env' => 'CLEARMYDOCS_ENABLED',
            'url_env' => 'CLEARMYDOCS_SIDECAR_URL',
            'path_env' => 'CLEARMYDOCS_PATH',
            'capabilities' => ['extract', 'segment', 'analyze', 'ingest'],
        ],
        'ingest-cmd-v4' => [
            'label' => 'ClearMyDocs v4',
            'description' => 'Schémas factures, gate fidélité — cible lot factures',
            'enabled_env' => 'CMD_V4_ENABLED',
            'url_env' => 'CMD_V4_URL',
            'path_env' => 'CMD_V4_PATH',
            'api_doc' => 'cmdv4/docs/API.md',
            'capabilities' => ['extract', 'segment', 'invoice_schema'],
        ],
    ],

    'erp' => [
        'erp-winbiz' => [
            'label' => 'WinBiz (k-winbiz-bridge)',
            'description' => 'Contrôle facture, ventilation, lecture ERP — post-ingest uniquement',
            'enabled_env' => 'WINBIZ_ENABLED',
            'url_env' => 'WINBIZ_BRIDGE_URL',
            'external_repo' => 'WinbizIntegrator/k-winbiz-bridge',
            'capabilities' => ['health', 'documents', 'articles', 'write'],
        ],
        'erp-ktime' => [
            'label' => 'K-Time (via bridge)',
            'description' => 'TimeTracker v2 — même bridge que WinBiz',
            'enabled_env' => 'WINBIZ_ENABLED',
            'url_env' => 'WINBIZ_BRIDGE_URL',
            'note' => 'Logique dans k-winbiz-bridge/service/time_tracker.py',
            'capabilities' => ['clients', 'projects', 'time_entries'],
        ],
    ],

    'apps' => [
        'smq' => [
            'label' => 'Plugin SMQ',
            'enabled_env' => 'SMQ_APP_ENABLED',
            'requires' => [],
        ],
        'rh' => [
            'label' => 'Plugin RH',
            'enabled_env' => 'RH_APP_ENABLED',
            'requires' => [],
        ],
        'invoices' => [
            'label' => 'Plugin Factures (contrôle ERP)',
            'enabled_env' => 'INVOICES_APP_ENABLED',
            'requires' => ['erp-winbiz'],
        ],
    ],
];
