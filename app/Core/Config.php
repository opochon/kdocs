<?php
/**
 * K-Docs - Classe de gestion de la configuration
 */

namespace KDocs\Core;

use KDocs\Models\Setting;

class Config
{
    private static ?array $config = null;
    private static ?array $dbSettings = null;

    /**
     * Charge la configuration depuis le fichier config.php et merge avec les settings DB
     */
    public static function load(): array
    {
        if (self::$config === null) {
            $configPath = __DIR__ . '/../../config/config.php';
            if (!file_exists($configPath)) {
                throw new \RuntimeException("Fichier de configuration introuvable: $configPath");
            }
            self::$config = require $configPath;
            
            // Charger les settings depuis la DB et les merger
            self::loadDbSettings();
        }
        return self::$config;
    }
    
    /**
     * Charge les paramètres depuis la base de données et les merge avec la config
     */
    private static function loadDbSettings(): void
    {
        try {
            // Charger les settings depuis la DB
            $dbSettings = Setting::getAll();
            
            // Merger avec la config (les settings DB ont priorité)
            foreach ($dbSettings as $key => $setting) {
                // Ignorer les valeurs vides (utiliser config par défaut)
                if ($setting['value'] === null || $setting['value'] === '') {
                    continue;
                }
                
                $keys = explode('.', $key);
                $target = &self::$config;
                
                // Naviguer dans la structure
                for ($i = 0; $i < count($keys) - 1; $i++) {
                    if (!isset($target[$keys[$i]])) {
                        $target[$keys[$i]] = [];
                    }
                    $target = &$target[$keys[$i]];
                }
                
                // Définir la valeur (convertir selon le type)
                $finalKey = $keys[count($keys) - 1];
                $value = $setting['value'];
                
                // Conversion selon le type
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
            // Si la table settings n'existe pas encore, continuer avec la config par défaut
            // Ne pas logger pour éviter les erreurs au démarrage
        }
    }
    
    /**
     * Réinitialise le cache de configuration (utile après modification des settings)
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$dbSettings = null;
    }

    /**
     * Récupère une valeur de configuration
     * 
     * @param string $key Clé au format "section.key" ou "section.key.subkey"
     * @param mixed $default Valeur par défaut si la clé n'existe pas
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $config = self::load();
        $keys = explode('.', $key);
        $value = $config;

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
        return self::load();
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
     * Ex: http://localhost/kdocs -> /kdocs
     */
    public static function basePath(): string
    {
        $url = self::baseUrl();
        $parsed = parse_url($url);
        return $parsed['path'] ?? '/kdocs';
    }
}
