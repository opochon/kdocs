<?php
/**
 * K-Docs - Factory pour créer les instances de stockage
 */

namespace KDocs\Services\Storage;

use KDocs\Core\Config;

class StorageFactory
{
    /**
     * Crée une instance de stockage selon la configuration
     * 
     * @return StorageInterface Instance de stockage (LocalStorage ou KDriveStorage)
     */
    public static function create(): StorageInterface
    {
        $config = Config::load();
        $storageType = Config::get('storage.type', 'local');
        
        switch ($storageType) {
            case 'kdrive':
                return new KDriveStorage();
            case 'local':
            default:
                return new LocalStorage();
        }
    }
    
    /**
     * Vérifie si KDrive est configuré et disponible
     * 
     * @return bool True si KDrive est configuré
     */
    public static function isKDriveAvailable(): bool
    {
        try {
            $config = Config::load();
            $kdriveConfig = $config['kdrive'] ?? [];
            
            return !empty($kdriveConfig['drive_id']) 
                && !empty($kdriveConfig['username']) 
                && !empty($kdriveConfig['password']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
