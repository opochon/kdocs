<?php
/**
 * K-DOCS - Config Validator
 * Vérifie que la configuration est valide au démarrage
 * 
 * Usage: require_once 'app/Core/ConfigValidator.php';
 *        ConfigValidator::validate(); // Lance une exception si erreur
 */

namespace KDocs\Core;

class ConfigValidator
{
    private static array $errors = [];
    private static array $warnings = [];
    
    /**
     * Valide toute la configuration
     * @throws \RuntimeException si erreur critique
     */
    public static function validate(): bool
    {
        self::$errors = [];
        self::$warnings = [];
        
        $config = Config::load();
        
        self::validateDatabase($config);
        self::validateStorage($config);
        self::validateTools($config);
        self::validateSecurity($config);
        
        if (!empty(self::$errors)) {
            $msg = "Configuration errors:\n- " . implode("\n- ", self::$errors);
            throw new \RuntimeException($msg);
        }
        
        return true;
    }
    
    /**
     * Validation silencieuse (retourne tableau d'erreurs)
     */
    public static function check(): array
    {
        try {
            self::validate();
        } catch (\Exception $e) {
            // Ignore, on veut juste les erreurs
        }
        
        return [
            'valid' => empty(self::$errors),
            'errors' => self::$errors,
            'warnings' => self::$warnings,
        ];
    }
    
    private static function validateDatabase(array $config): void
    {
        $db = $config['database'] ?? [];
        
        if (empty($db['host'])) {
            self::$errors[] = "database.host is required";
        }
        if (empty($db['database']) && empty($db['name'])) {
            self::$errors[] = "database.database (or database.name) is required";
        }
        
        // Test connexion
        try {
            $dbName = $db['database'] ?? $db['name'] ?? '';
            $port = $db['port'] ?? 3306;
            $dsn = "mysql:host={$db['host']};port={$port};dbname={$dbName};charset=utf8mb4";
            $pdo = new \PDO($dsn, $db['username'] ?? $db['user'] ?? 'root', $db['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query('SELECT 1');
        } catch (\Exception $e) {
            self::$errors[] = "Database connection failed: " . $e->getMessage();
        }
    }
    
    private static function validateStorage(array $config): void
    {
        $storage = $config['storage'] ?? [];
        
        $dirs = [
            'base_path' => $storage['base_path'] ?? null,
            'consume' => $storage['consume'] ?? null,
            'thumbnails' => $storage['thumbnails'] ?? null,
        ];
        
        foreach ($dirs as $name => $path) {
            if ($path === null) continue;
            
            if (!is_dir($path)) {
                self::$warnings[] = "storage.$name directory does not exist: $path";
            } elseif (!is_writable($path)) {
                self::$errors[] = "storage.$name is not writable: $path";
            }
        }
    }
    
    private static function validateTools(array $config): void
    {
        $tools = $config['tools'] ?? [];
        $ocr = $config['ocr'] ?? [];
        
        // Tesseract (critique pour OCR)
        $tesseract = $ocr['tesseract_path'] ?? $tools['tesseract'] ?? null;
        if ($tesseract && !file_exists($tesseract)) {
            self::$warnings[] = "Tesseract not found: $tesseract (OCR will not work)";
        }
        
        // Ghostscript (critique pour PDF)
        $gs = $tools['ghostscript'] ?? null;
        if ($gs && !file_exists($gs)) {
            self::$warnings[] = "Ghostscript not found: $gs (PDF thumbnails may fail)";
        }
    }
    
    private static function validateSecurity(array $config): void
    {
        $app = $config['app'] ?? [];
        
        // App key
        $key = $app['key'] ?? null;
        if (empty($key) || $key === 'change-this-to-random-string-32-chars') {
            self::$warnings[] = "app.key should be set to a random string";
        }
        
        // Debug mode en prod
        if (($app['debug'] ?? false) === true) {
            self::$warnings[] = "app.debug is enabled (disable in production)";
        }
    }
    
    public static function getErrors(): array
    {
        return self::$errors;
    }
    
    public static function getWarnings(): array
    {
        return self::$warnings;
    }
}
