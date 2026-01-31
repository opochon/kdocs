<?php
/**
 * K-Invoices - Configuration
 */

return [
    'app' => [
        'name' => 'K-Invoices',
        'version' => '0.1.0',
        'enabled' => false, // A activer quand pret
    ],

    // Tables prefixees app_invoices_*
    'database' => [
        'prefix' => 'app_invoices_',
        'use_kdocs_db' => true,
    ],

    // Extraction IA
    'extraction' => [
        'provider' => 'claude',     // claude, ollama, openai
        'model' => 'claude-sonnet-4-20250514',
        'confidence_threshold' => 0.7,
        'extract_lines' => true,
        'learn_by_supplier' => true,
    ],

    // Detection automatique
    'detection' => [
        'enabled' => true,
        'document_types' => ['Facture', 'Invoice', 'Rechnung'],
        'keywords' => ['facture', 'invoice', 'montant', 'total', 'tva'],
    ],

    // Rapprochement WinBiz
    'winbiz' => [
        'enabled' => false,
        'connector' => 'connectors/winbiz',
        'match_bl' => true,         // Rapprocher avec BL
        'match_stock' => true,      // Rapprocher avec stock
        'match_fiches' => true,     // Rapprocher avec fiches travail
        'tolerance_percent' => 5,   // Tolerance prix %
    ],

    // Workflow validation
    'workflow' => [
        'require_all_lines' => true,    // Toutes les lignes doivent etre validees
        'require_matching' => false,    // Rapprochement obligatoire
        'auto_validate' => false,       // Validation auto si confiance > 95%
    ],

    // Export
    'export' => [
        'winbiz_format' => true,
        'csv_delimiter' => ';',
        'date_format' => 'd.m.Y',
        'decimal_separator' => '.',
    ],

    // Apprentissage
    'learning' => [
        'enabled' => true,
        'by_supplier' => true,      // Apprendre par fournisseur
        'min_samples' => 3,         // Min factures avant suggestion
    ],

    // UI
    'ui' => [
        'show_confidence' => true,
        'highlight_low_confidence' => true,
        'side_by_side_view' => true,
    ],
];
