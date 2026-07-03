<?php
/**
 * K-Contrats — Configuration plugin (GAP-030).
 */

return [
    'app' => [
        'name'    => 'K-Contrats',
        'version' => '0.1.0',
        'enabled' => filter_var(env('CONTRACTS_APP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
