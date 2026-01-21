<?php
/**
 * K-Docs - Modèle pour les paramètres système
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class Setting
{
    /**
     * Récupère une valeur de paramètre
     * 
     * @param string $key Clé du paramètre (ex: 'storage.base_path')
     * @param mixed $default Valeur par défaut si non trouvé
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $db = Database::getInstance();
        
        try {
            $stmt = $db->prepare("SELECT `value`, `type` FROM settings WHERE `key` = ?");
            $stmt->execute([$key]);
            $setting = $stmt->fetch();
            
            if (!$setting) {
                return $default;
            }
            
            // Convertir selon le type
            return self::convertValue($setting['value'], $setting['type']);
        } catch (\Exception $e) {
            return $default;
        }
    }
    
    /**
     * Définit une valeur de paramètre
     * 
     * @param string $key Clé du paramètre
     * @param mixed $value Valeur
     * @param string $type Type (string, integer, boolean, json)
     * @param int|null $userId ID de l'utilisateur qui modifie
     * @return bool
     */
    public static function set(string $key, $value, string $type = 'string', ?int $userId = null): bool
    {
        $db = Database::getInstance();
        
        try {
            // Convertir la valeur selon le type
            $stringValue = self::stringifyValue($value, $type);
            
            // Vérifier si le paramètre existe déjà
            $checkStmt = $db->prepare("SELECT id FROM settings WHERE `key` = ?");
            $checkStmt->execute([$key]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Mettre à jour
                $stmt = $db->prepare("
                    UPDATE settings 
                    SET `value` = ?, `type` = ?, `updated_by` = ?, `updated_at` = CURRENT_TIMESTAMP
                    WHERE `key` = ?
                ");
                return $stmt->execute([$stringValue, $type, $userId, $key]);
            } else {
                // Insérer
                $stmt = $db->prepare("
                    INSERT INTO settings (`key`, `value`, `type`, `updated_by`)
                    VALUES (?, ?, ?, ?)
                ");
                return $stmt->execute([$key, $stringValue, $type, $userId]);
            }
        } catch (\Exception $e) {
            error_log("Erreur Setting::set: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Récupère tous les paramètres d'une catégorie
     * 
     * @param string|null $category Catégorie (null = toutes)
     * @return array
     */
    public static function getAll(?string $category = null): array
    {
        $db = Database::getInstance();
        
        try {
            if ($category) {
                $stmt = $db->prepare("SELECT * FROM settings WHERE category = ? ORDER BY `key`");
                $stmt->execute([$category]);
            } else {
                $stmt = $db->query("SELECT * FROM settings ORDER BY category, `key`");
            }
            
            $settings = [];
            while ($row = $stmt->fetch()) {
                $row['value'] = self::convertValue($row['value'], $row['type']);
                $settings[$row['key']] = $row;
            }
            
            return $settings;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Supprime un paramètre
     * 
     * @param string $key Clé du paramètre
     * @return bool
     */
    public static function delete(string $key): bool
    {
        $db = Database::getInstance();
        
        try {
            $stmt = $db->prepare("DELETE FROM settings WHERE `key` = ?");
            return $stmt->execute([$key]);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Convertit une valeur string en type approprié
     */
    private static function convertValue($value, string $type)
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        switch ($type) {
            case 'integer':
                return (int)$value;
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }
    
    /**
     * Convertit une valeur en string pour stockage
     */
    private static function stringifyValue($value, string $type): string
    {
        switch ($type) {
            case 'json':
                return json_encode($value);
            case 'boolean':
                return $value ? '1' : '0';
            default:
                return (string)$value;
        }
    }
}
