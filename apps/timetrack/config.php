<?php
/**
 * K-Time - Configuration
 */

return [
    'app' => [
        'name' => 'K-Time',
        'version' => '0.1.0',
        'enabled' => true,
    ],

    // Tables prefixees app_time_*
    'database' => [
        'prefix' => 'app_time_',
        'use_kdocs_db' => true, // Utilise la meme base que K-Docs
    ],

    // Valeurs par defaut
    'defaults' => [
        'rate' => 150.00,           // Taux horaire CHF
        'currency' => 'CHF',
        'vat_rate' => 8.1,          // TVA suisse
        'work_hours_per_day' => 8,
    ],

    // Quick Codes
    'quick_codes' => [
        'enabled' => true,
        'duration_pattern' => '/(\d+(?:[.,]\d+)?)(h|H)([A-Z][A-Z0-9]*)?/',
        'supply_pattern' => '/p([A-Z]{2})(\d+(?:[.,]\d+)?)/',
    ],

    // Timer
    'timer' => [
        'auto_round' => 5,          // Arrondir aux 5 minutes
        'max_duration' => 12,       // Max 12h par timer
        'persist' => true,          // Persister en base
    ],

    // Facturation
    'invoicing' => [
        'prefix' => 'INV-',
        'next_number' => 1,
        'pdf_engine' => 'tcpdf',    // tcpdf ou dompdf
        'qr_swiss' => true,         // QR facture suisse
    ],

    // Integration K-Docs
    'kdocs_integration' => [
        'enabled' => false,         // Auto-detecte si K-Docs present
        'invoice_folder' => 'Factures/Emises',
        'sync_clients' => true,     // Sync avec correspondents
    ],

    // WinBiz export
    'winbiz' => [
        'enabled' => false,
        'connector' => 'connectors/winbiz',
    ],

    // UI
    'ui' => [
        'entries_per_page' => 50,
        'show_timer_widget' => true,
        'week_start' => 1,          // 1 = Lundi
    ],
];
