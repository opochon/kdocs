<?php
/**
 * Connecteur WinBiz - Configuration
 */

return [
    'connector' => [
        'name' => 'WinBiz',
        'version' => '0.1.0',
        'enabled' => false,
    ],

    // Connexion ODBC
    'odbc' => [
        'driver' => 'Microsoft Visual FoxPro Driver',
        'db_path' => env('WINBIZ_DB_PATH', 'C:\\WinBiz\\Data\\DEMO\\'),
        'source_type' => 'DBF',
        'exclusive' => false,
        'null_values' => true,
        'deleted' => false,
    ],

    // DSN string complete
    'dsn' => function () {
        $config = require __FILE__;
        $odbc = $config['odbc'];
        return "Driver={{$odbc['driver']}};SourceType={$odbc['source_type']};SourceDB={$odbc['db_path']};Exclusive={$odbc['exclusive']};NULL={$odbc['null_values']};Deleted={$odbc['deleted']};";
    },

    // Tables a lire
    'tables' => [
        'articles' => 'ARTICLE',
        'clients' => 'CLIENT',
        'fournisseurs' => 'FOURN',
        'factures_clients' => 'FACTURE',
        'factures_fournisseurs' => 'FACTFOURN',
        'bons_livraison' => 'BL',
        'fiches_travail' => 'FICHETRAV',
    ],

    // Mapping champs
    'field_mapping' => [
        'articles' => [
            'code' => 'ART_CODE',
            'designation' => 'ART_DESIGN',
            'prix_achat' => 'ART_PXACH',
            'prix_vente' => 'ART_PXVTE',
            'stock' => 'ART_STOCK',
            'unite' => 'ART_UNITE',
        ],
        'clients' => [
            'code' => 'CLI_CODE',
            'nom' => 'CLI_NOM',
            'adresse' => 'CLI_ADR1',
            'npa' => 'CLI_NPA',
            'localite' => 'CLI_LOCAL',
        ],
    ],

    // Securite
    'security' => [
        'read_only' => true,
        'allowed_tables' => ['ARTICLE', 'CLIENT', 'FOURN', 'BL', 'FICHETRAV'],
        'max_results' => 1000,
    ],

    // Cache
    'cache' => [
        'enabled' => true,
        'ttl' => 300, // 5 minutes
        'file' => __DIR__ . '/../../storage/cache/winbiz.cache',
    ],
];
