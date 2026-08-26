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

    // Segmentation visuelle (2026-08-25) : ce slot NE S'AFFICHE PAS tant que
    // PORTAL_APP_ENABLED est absent du .env — View::pluginSlot() ne rend que
    // les apps activées (PluginRegistry::isEnabled). La déclaration reste :
    // le jour où le portail s'allume, son entrée de navigation apparaît sans
    // toucher au shell. Voir docs/PLUGIN-SYSTEM.md (section Slots).
    'ui_slots' => [
        'admin.sidebar.navigation' => __DIR__ . '/templates/slots/admin_sidebar.php',
    ],
];
