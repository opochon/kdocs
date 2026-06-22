<?php
/**
 * K-RH — Configuration plugin (phase D scaffold)
 */

return [
    'app' => [
        'name' => 'K-RH',
        'version' => '0.0.1',
        'enabled' => filter_var(env('RH_APP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
