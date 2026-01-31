<?php
/**
 * K-Mail - Configuration
 */

return [
    'app' => [
        'name' => 'K-Mail',
        'version' => '0.1.0',
        'enabled' => false, // A activer quand pret
    ],

    // Serveur IMAP par defaut
    'imap' => [
        'host' => env('MAIL_IMAP_HOST', 'imap.example.com'),
        'port' => env('MAIL_IMAP_PORT', 993),
        'encryption' => env('MAIL_IMAP_ENCRYPTION', 'ssl'), // ssl, tls, none
        'validate_cert' => env('MAIL_IMAP_VALIDATE_CERT', true),
    ],

    // Serveur SMTP par defaut
    'smtp' => [
        'host' => env('MAIL_SMTP_HOST', 'smtp.example.com'),
        'port' => env('MAIL_SMTP_PORT', 587),
        'encryption' => env('MAIL_SMTP_ENCRYPTION', 'tls'),
        'auth' => true,
    ],

    // Cache local SQLite
    'cache' => [
        'enabled' => true,
        'database' => __DIR__ . '/../../storage/apps/mail/cache.sqlite',
        'ttl' => 3600, // 1 heure
    ],

    // Recherche semantique (Qdrant natif)
    'semantic_search' => [
        'enabled' => false,
        'qdrant_url' => env('QDRANT_URL', 'http://localhost:6333'),
        'collection' => 'kmail_messages',
    ],

    // Calendrier CalDAV
    'caldav' => [
        'enabled' => false,
        'url' => env('CALDAV_URL', ''),
    ],

    // Integration K-Docs
    'kdocs_integration' => [
        'save_attachments' => true, // Sauvegarder pieces jointes dans GED
        'default_folder' => 'Courrier/Entrant',
    ],

    // UI
    'ui' => [
        'messages_per_page' => 50,
        'preview_lines' => 3,
        'auto_refresh' => 60, // secondes
    ],
];
