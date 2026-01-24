<?php
/**
 * K-Docs - Classe de gestion de la configuration
 * 
 * ATTENTION: Ne pas charger les settings DB ici pour éviter
 * la dépendance circulaire Config -> Database -> Config
 */

namespace KDocs\Core;

class Config
{
    private static ?array $config = null;
    private static ?array $dbSettings = null;
    private static bool $dbSettingsLoaded = false;

    /**
     * Charge la configuration depuis le fichier config.php UNIQUEMENT
     * Les settings DB sont chargés à la demande via loadDbSettings()
     */
    public static function load(): array
    {
        if (self::$config === null) {
            $configPath = __DIR__ . '/../../config/config.php';
            if (!file_exists($configPath)) {
                throw new \RuntimeException("Fichier de configuration introuvable: $configPath");
            }
            self::$config = require $configPath;
            
            // NE PAS charger les settings DB ici pour éviter la dépendance circulaire
            // Database::getInstance() appelle Config::get('database') 
            // qui appellerait Setting::getAll() qui appelle Database::getInstance()
        }
        return self::$config;
    }
    
    /**
     * Charge les settings DB et les merge avec la config
     * Appelé uniquement quand on a besoin des settings DB (pas pour database.*)
     */
    public static function loadDbSettingsIfNeeded(): void
    {
        if (self::$dbSettingsLoaded) {
            return;
        }
        
        self::$dbSettingsLoaded = true;
        
        try {
            // Charger les settings depuis la DB
            $dbSettings = \KDocs\Models\Setting::getAll();
            
            // Merger avec la config (les settings DB ont priorité)
            foreach ($dbSettings as $key => $setting) {
                // Ignorer les valeurs vides
                if ($setting['value'] === null || $setting['value'] === '') {
                    continue;
                }
                
                // NE PAS permettre de surcharger la config database depuis les settings DB
                // pour éviter les problèmes de bootstrap
                if (strpos($key, 'database.') === 0) {
                    continue;
                }
                
                $keys = explode('.', $key);
                $target = &self::$config;
                
                for ($i = 0; $i < count($keys) - 1; $i++) {
                    if (!isset($target[$keys[$i]])) {
                        $target[$keys[$i]] = [];
                    }
                    $target = &$target[$keys[$i]];
                }
                
                $finalKey = $keys[count($keys) - 1];
                $value = $setting['value'];
                
                switch ($setting['type']) {
                    case 'integer':
                        $value = (int)$value;
                        break;
                    case 'boolean':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                
                $target[$finalKey] = $value;
            }
        } catch (\Exception $e) {
            // Si la table settings n'existe pas, continuer avec la config par défaut
        }
    }
    
    /**
     * Réinitialise le cache de configuration
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$dbSettings = null;
        self::$dbSettingsLoaded = false;
    }

    /**
     * Récupère une valeur de configuration
     */
    public static function get(string $key, $default = null)
    {
        $config = self::load();
        
        // Pour les clés non-database, charger les settings DB
        if (strpos($key, 'database') !== 0) {
            self::loadDbSettingsIfNeeded();
        }
        
        $keys = explode('.', $key);
        $value = self::$config; // Utiliser la config potentiellement modifiée

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Vérifie si une clé de configuration existe
     */
    public static function has(string $key): bool
    {
        return self::get($key, '__NOT_FOUND__') !== '__NOT_FOUND__';
    }

    /**
     * Retourne toute la configuration
     */
    public static function all(): array
    {
        self::load();
        self::loadDbSettingsIfNeeded();
        return self::$config;
    }

    /**
     * Retourne l'URL de base de l'application
     */
    public static function baseUrl(): string
    {
        return self::get('app.url', 'http://localhost/kdocs');
    }

    /**
     * Retourne le chemin de base (path) de l'URL
     */
    public static function basePath(): string
    {
        $url = self::baseUrl();
        $parsed = parse_url($url);
        return $parsed['path'] ?? '/kdocs';
    }
}
