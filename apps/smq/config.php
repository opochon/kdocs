<?php
/**
 * K-SMQ — Configuration plugin (phase C scaffold)
 */

return [
    'app' => [
        'name' => 'K-SMQ',
        'version' => '0.0.1',
        'enabled' => filter_var(env('SMQ_APP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
