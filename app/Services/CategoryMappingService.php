<?php
/**
 * K-Docs - Service de gestion des mappings de catégories IA
 * Gère la mémorisation et l'application des mappings entre catégories IA et entités
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class CategoryMappingService
{
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        // Vérifier si la table existe
        $this->checkTableExists();
    }
    
    /**
     * Vérifie si la table category_mappings existe
     */
    private function checkTableExists(): void
    {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'category_mappings'");
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException(
                    'La table category_mappings n\'existe pas. ' .
                    'Veuillez exécuter la migration : php database/migrate_013_category_mappings.php'
                );
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'Impossible de vérifier l\'existence de la table category_mappings. ' .
                'Veuillez exécuter la migration : php database/migrate_013_category_mappings.php'
            );
        }
    }
    
    /**
     * Crée un mapping entre une catégorie IA et une entité
     */
    public function createMapping(string $categoryName, string $mappedType, int $mappedId, string $mappedName): int
    {
        // Vérifier si le mapping existe déjà
        $existing = $this->getMapping($categoryName, $mappedType, $mappedId);
        if ($existing) {
            // Mettre à jour le nom si différent
            if ($existing['mapped_name'] !== $mappedName) {
                $this->db->prepare("
                    UPDATE category_mappings 
                    SET mapped_name = ?, updated_at = NOW() 
                    WHERE id = ?
                ")->execute([$mappedName, $existing['id']]);
            }
            return $existing['id'];
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO category_mappings (category_name, mapped_type, mapped_id, mapped_name)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$categoryName, $mappedType, $mappedId, $mappedName]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Récupère un mapping spécifique
     */
    public function getMapping(string $categoryName, string $mappedType, int $mappedId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM category_mappings 
            WHERE category_name = ? AND mapped_type = ? AND mapped_id = ?
        ");
        $stmt->execute([$categoryName, $mappedType, $mappedId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Récupère tous les mappings pour une catégorie
     */
    public function getMappingsForCategory(string $categoryName): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM category_mappings 
            WHERE category_name = ?
            ORDER BY usage_count DESC, created_at DESC
        ");
        $stmt->execute([$categoryName]);
        return $stmt->fetchAll();
    }
    
    /**
     * Applique les mappings connus à une liste de catégories
     * Retourne les catégories avec leurs mappings appliqués
     */
    public function applyMappings(array $categories): array
    {
        $result = [];
        
        foreach ($categories as $category) {
            $mappings = $this->getMappingsForCategory($category);
            $result[] = [
                'name' => $category,
                'mappings' => $mappings,
                'has_mapping' => !empty($mappings)
            ];
        }
        
        return $result;
    }
    
    /**
     * Incrémente le compteur d'utilisation d'un mapping
     */
    public function incrementUsage(int $mappingId): void
    {
        $this->db->prepare("
            UPDATE category_mappings 
            SET usage_count = usage_count + 1, updated_at = NOW()
            WHERE id = ?
        ")->execute([$mappingId]);
    }
    
    /**
     * Crée un tag depuis une catégorie
     */
    public function createTagFromCategory(string $categoryName, string $tagName): int
    {
        // Créer le tag
        $stmt = $this->db->prepare("INSERT INTO tags (name, color) VALUES (?, ?)");
        $stmt->execute([$tagName, $this->generateTagColor()]);
        $tagId = (int)$this->db->lastInsertId();
        
        // Créer le mapping
        $this->createMapping($categoryName, 'tag', $tagId, $tagName);
        
        return $tagId;
    }
    
    /**
     * Crée un champ de classification depuis une catégorie
     */
    public function createClassificationFieldFromCategory(string $categoryName, string $fieldName, string $fieldCode): int
    {
        // Créer le champ de classification
        $field = \KDocs\Models\ClassificationField::create([
            'field_code' => $fieldCode,
            'field_name' => $fieldName,
            'field_type' => 'custom',
            'is_active' => true,
            'use_for_storage_path' => false,
            'use_for_tag' => false
        ]);
        
        // Créer le mapping
        $this->createMapping($categoryName, 'classification_field', $field, $fieldName);
        
        return $field;
    }
    
    /**
     * Mappe une catégorie sur un tag existant
     */
    public function mapToTag(string $categoryName, int $tagId, string $tagName): int
    {
        return $this->createMapping($categoryName, 'tag', $tagId, $tagName);
    }
    
    /**
     * Mappe une catégorie sur un champ de classification existant
     */
    public function mapToClassificationField(string $categoryName, int $fieldId, string $fieldName): int
    {
        return $this->createMapping($categoryName, 'classification_field', $fieldId, $fieldName);
    }
    
    /**
     * Mappe une catégorie sur un correspondant existant
     */
    public function mapToCorrespondent(string $categoryName, int $correspondentId, string $correspondentName): int
    {
        return $this->createMapping($categoryName, 'correspondent', $correspondentId, $correspondentName);
    }
    
    /**
     * Mappe une catégorie sur un type de document existant
     */
    public function mapToDocumentType(string $categoryName, int $typeId, string $typeName): int
    {
        return $this->createMapping($categoryName, 'document_type', $typeId, $typeName);
    }
    
    /**
     * Génère une couleur aléatoire pour un tag
     */
    private function generateTagColor(): string
    {
        $colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4'];
        return $colors[array_rand($colors)];
    }
}
