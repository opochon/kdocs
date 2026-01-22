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
            // Convertir la valeur selon le type (même si vide)
            $stringValue = self::stringifyValue($value, $type);
            
            // Extraire la catégorie depuis la clé (ex: 'storage.base_path' -> 'storage')
            $category = explode('.', $key)[0] ?? 'general';
            
            // Vérifier si le paramètre existe déjà (utiliser `key` car pas de colonne `id`)
            $checkStmt = $db->prepare("SELECT `key` FROM settings WHERE `key` = ?");
            $checkStmt->execute([$key]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Mettre à jour
                $stmt = $db->prepare("
                    UPDATE settings 
                    SET `value` = ?, `type` = ?, `category` = ?, `updated_by` = ?, `updated_at` = CURRENT_TIMESTAMP
                    WHERE `key` = ?
                ");
                $result = $stmt->execute([$stringValue, $type, $category, $userId, $key]);
                if (!$result) {
                    error_log("Erreur UPDATE Setting::set pour '$key': " . implode(', ', $stmt->errorInfo()));
                }
                return $result;
            } else {
                // Insérer
                $stmt = $db->prepare("
                    INSERT INTO settings (`key`, `value`, `type`, `category`, `updated_by`)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([$key, $stringValue, $type, $category, $userId]);
                if (!$result) {
                    error_log("Erreur INSERT Setting::set pour '$key': " . implode(', ', $stmt->errorInfo()));
                }
                return $result;
            }
        } catch (\Exception $e) {
            error_log("Erreur Setting::set pour '$key': " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
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
        // Permettre les valeurs vides/null
        if ($value === null || $value === '') {
            return '';
        }
        
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
