<?php
/**
 * K-Portail — Configuration (GAP-042).
 *
 * Portail client en lecture seule : liste les documents d'un correspondant.
 * Activé par la variable d'environnement PORTAL_APP_ENABLED.
 */

return [
    'app' => [
        'name'    => 'K-Portail',
        'version' => '0.1.0',
        'enabled' => (bool) (function_exists('env') ? env('PORTAL_APP_ENABLED', false) : ($_ENV['PORTAL_APP_ENABLED'] ?? false)),
    ],

    // Comportement du portail
    'portal' => [
        'per_page'     => 50,
        'show_content' => false, // Lecture seule — jamais le contenu OCR
    ],
];
