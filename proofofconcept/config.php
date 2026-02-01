<?php
/**
 * POC Config - Configuration complète pour validation K-DOCS
 *
 * Ce fichier configure TOUTES les fonctionnalités à valider:
 * - OCR multi-format
 * - Embeddings (Ollama + OpenAI)
 * - Classification (Règles + Claude AI)
 * - Recherche (FULLTEXT + Sémantique)
 * - Miniatures
 * - Flux complets
 */

return [
    // ============================================
    // CONNEXION DB (même base que GED)
    // ============================================
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3307,
        'name' => 'kdocs',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    // ============================================
    // DOSSIERS GED (lecture seule dans POC)
    // ============================================
    'paths' => [
        'documents' => 'C:/wamp64/www/kdocs/storage/documents',
        'consume' => 'C:/wamp64/www/kdocs/storage/consume',
        'thumbnails' => 'C:/wamp64/www/kdocs/storage/thumbnails',
        'temp' => 'C:/wamp64/www/kdocs/storage/temp',
    ],

    // ============================================
    // OUTILS EXTERNES
    // ============================================
    'tools' => [
        'tesseract' => 'C:/Program Files/Tesseract-OCR/tesseract.exe',
        'ghostscript' => 'C:/Program Files/gs/gs10.03.0/bin/gswin64c.exe',
        'libreoffice' => 'C:/Program Files/LibreOffice/program/soffice.exe',
        'pdftotext' => 'C:/Program Files/Git/mingw64/bin/pdftotext.exe',
        'pdftoppm' => 'C:/Program Files/Git/mingw64/bin/pdftoppm.exe',
        'imagemagick' => 'C:/Program Files/ImageMagick-7.1.2-Q16-HDRI/magick.exe',
    ],

    // ============================================
    // OLLAMA (embeddings locaux)
    // ============================================
    'ollama' => [
        'url' => 'http://localhost:11434',
        'model' => 'nomic-embed-text',           // Embedding model
        'vision_model' => 'llama3.2-vision:11b', // Vision model
        'chat_model' => 'llama3.2',              // Chat model
        'dimensions' => 768,
        'timeout' => 60,
        'max_chars' => 6000,  // Limite caractères pour nomic-embed-text
    ],

    // ============================================
    // OPENAI (optionnel - Ollama suffit)
    // ============================================
    'openai' => [
        'api_key' => getenv('OPENAI_API_KEY') ?: '',  // Non utilisé
        'model' => 'text-embedding-3-small',
        'dimensions' => 1536,
        'timeout' => 30,
        'max_tokens' => 8000,
    ],

    // ============================================
    // AI CONFIGURATION (CASCADE)
    // ============================================
    'ai' => [
        'anthropic' => [
            'enabled' => true,
            'api_key' => (function() {
                $keyFile = dirname(__DIR__) . '/claude_api_key.txt';
                if (file_exists($keyFile)) {
                    return trim(file_get_contents($keyFile));
                }
                return getenv('ANTHROPIC_API_KEY') ?: '';
            })(),
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 2000,
            'timeout' => 60,
        ],
        'ollama' => [
            'enabled' => true,
            'url' => 'http://localhost:11434',
            'model_embed' => 'nomic-embed-text',
            'model_generate' => 'llama3.1:8b',  // Disponibles: mistral:7b, llama3.1:8b, gemma3:4b
            'timeout' => 60,
        ],
        'cascade' => [
            'classification' => ['anthropic', 'ollama', 'rules'],
            'embedding' => ['ollama'],
        ],
        'training' => [
            'enabled' => true,
            'min_similarity' => 0.85,
            'auto_learn_rules' => true,
            'file' => __DIR__ . '/output/training.json',
        ],
    ],

    // ============================================
    // CLAUDE (alias pour compatibilité)
    // ============================================
    'claude' => [
        'api_key' => (function() {
            $keyFile = dirname(__DIR__) . '/claude_api_key.txt';
            if (file_exists($keyFile)) {
                return trim(file_get_contents($keyFile));
            }
            return getenv('ANTHROPIC_API_KEY') ?: '';
        })(),
        'model' => 'claude-sonnet-4-20250514',
        'timeout' => 60,
    ],

    // ============================================
    // EMBEDDINGS (configuration générale)
    // ============================================
    'embeddings' => [
        'enabled' => true,
        'provider' => 'ollama',  // 'ollama' ou 'openai'
        'fallback' => true,      // Fallback sur l'autre provider si échec
    ],

    // ============================================
    // CLASSIFICATION
    // ============================================
    'classification' => [
        'method' => 'auto',      // 'rules', 'ai', 'auto'
        'auto_apply' => false,
        'auto_apply_threshold' => 0.8,
        'ai_enabled' => true,    // Utiliser Claude si disponible
    ],

    // ============================================
    // RECHERCHE
    // ============================================
    'search' => [
        'fulltext_enabled' => true,
        'semantic_enabled' => true,
        'semantic_threshold' => 0.5,   // Similarité minimum
        'semantic_limit' => 10,        // Résultats max
        'hybrid_mode' => true,         // Combiner FULLTEXT + sémantique
    ],

    // ============================================
    // FLUX CONSUME (bulk scan avec split)
    // ============================================
    'consume' => [
        'split_auto_confirm' => false,
        'text_threshold' => 200,
        'max_pages_per_doc' => 50,
    ],

    // ============================================
    // FLUX DROP UI
    // ============================================
    'drop' => [
        'default_behavior' => 'propose',
    ],

    // ============================================
    // FORMATS SUPPORTÉS
    // ============================================
    'formats' => [
        'ocr' => ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'bmp', 'webp'],
        'office' => ['docx', 'doc', 'odt', 'rtf', 'xlsx', 'xls', 'ods', 'csv', 'pptx', 'ppt', 'odp'],
        'text' => ['txt', 'md', 'json', 'xml', 'html'],
        'email' => ['msg', 'eml'],
    ],

    // ============================================
    // POC SPÉCIFIQUE
    // ============================================
    'poc' => [
        'samples_dir' => __DIR__ . '/samples',
        'output_dir' => __DIR__ . '/output',
        'log_file' => __DIR__ . '/output/poc.log',
        'dry_run' => true,   // TRUE = ne modifie PAS la DB GED
        'verbose' => true,
        'test_all_features' => true,  // Tester toutes les fonctionnalités
    ],
];
