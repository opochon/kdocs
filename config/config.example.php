<?php
/**
 * K-Docs Configuration Example
 * 
 * Copy this file to config.php and adjust values for your environment.
 * 
 * cp config/config.example.php config/config.php
 */

return [
    'app' => [
        'name' => 'K-Docs',
        'url' => 'http://localhost/kdocs',
        'debug' => true,
        'timezone' => 'Europe/Zurich',
        'key' => 'change-this-to-random-string-32-chars',
    ],
    
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'kdocs',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    
    'storage' => [
        'type' => 'local',
        'base_path' => __DIR__ . '/../storage/documents',
        'consume' => __DIR__ . '/../storage/consume',
        'thumbnails' => __DIR__ . '/../storage/thumbnails',
        'temp' => __DIR__ . '/../storage/temp',
        'trash' => __DIR__ . '/../storage/trash',
        'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'doc', 'docx', 'msg'],
    ],
    
    'ocr' => [
        'tesseract_path' => match(PHP_OS_FAMILY) {
            'Windows' => 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            default => '/usr/bin/tesseract'
        },
    ],
    
    'tools' => [
        'ghostscript' => match(PHP_OS_FAMILY) {
            'Windows' => 'C:\\Program Files\\gs\\gs10.03.0\\bin\\gswin64c.exe',
            default => '/usr/bin/gs'
        },
        'pdftotext' => match(PHP_OS_FAMILY) {
            'Windows' => 'C:\\poppler\\bin\\pdftotext.exe',
            default => '/usr/bin/pdftotext'
        },
        'pdftoppm' => match(PHP_OS_FAMILY) {
            'Windows' => 'C:\\poppler\\bin\\pdftoppm.exe',
            default => '/usr/bin/pdftoppm'
        },
        'libreoffice' => match(PHP_OS_FAMILY) {
            'Windows' => 'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            default => '/usr/bin/libreoffice'
        },
    ],
    
    'ai' => [
        'claude_api_key' => null, // Or set via ANTHROPIC_API_KEY env var
        'cascade' => ['training', 'claude', 'ollama', 'rules'],
        'training' => [
            'enabled' => true,
            'file' => __DIR__ . '/../storage/training.json',
            'min_similarity' => 0.85,
        ],
    ],
    
    'ollama' => [
        'url' => 'http://localhost:11434',
        'model' => 'llama3.1:8b',
        'embed_model' => 'nomic-embed-text',
        'timeout' => 60,
    ],
    
    'claude' => [
        'model' => 'claude-sonnet-4-20250514',
    ],
    
    'embeddings' => [
        'enabled' => true,
        'ollama_url' => 'http://localhost:11434',
        'ollama_model' => 'nomic-embed-text',
    ],
    
    'onlyoffice' => [
        'enabled' => false,
        'server_url' => 'http://localhost:8080',
        'jwt_secret' => '',
    ],
];
