<?php
/**
 * K-ERP Connect — Configuration plugin (liaison ERP K-Time).
 * Spec : K-TIME/docs/SPEC-GED-INTEGRATION.md
 */

return [
    'app' => [
        'name'    => 'K-ERP Connect',
        'version' => '0.1.0',
        'enabled' => filter_var(env('ERPCONNECT_APP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
