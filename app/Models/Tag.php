<?php
/**
 * K-Docs - Modèle Tag avec support Nested Tags (Phase 3.2)
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class Tag
{
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM tags ORDER BY name")->fetchAll();
    }
    
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO tags (name, color, parent_id, match, matching_algorithm)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['color'] ?? '#6b7280',
            $data['parent_id'] ?? null,
            $data['match'] ?? null,
            $data['matching_algorithm'] ?? 'none'
        ]);
        return $db->lastInsertId();
    }
    
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE tags 
            SET name = ?, color = ?, parent_id = ?, match = ?, matching_algorithm = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['name'],
            $data['color'] ?? '#6b7280',
            $data['parent_id'] ?? null,
            $data['match'] ?? null,
            $data['matching_algorithm'] ?? 'none',
            $id
        ]);
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM tags WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Récupère tous les tags racine (sans parent)
     */
    public static function getRootTags(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM tags WHERE parent_id IS NULL ORDER BY name")->fetchAll();
    }
    
    /**
     * Récupère tous les enfants d'un tag
     */
    public static function getChildren(int $parentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM tags WHERE parent_id = ? ORDER BY name");
        $stmt->execute([$parentId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Récupère tous les tags avec leur hiérarchie (arborescence)
     */
    public static function getTree(): array
    {
        $db = Database::getInstance();
        $allTags = $db->query("SELECT * FROM tags ORDER BY name")->fetchAll();
        
        // Organiser en arborescence
        $tree = [];
        $tagMap = [];
        
        // Créer une map de tous les tags
        foreach ($allTags as $tag) {
            $tagMap[$tag['id']] = $tag;
            $tag['children'] = [];
        }
        
        // Construire l'arborescence
        foreach ($allTags as $tag) {
            if ($tag['parent_id'] === null) {
                $tree[] = &$tagMap[$tag['id']];
            } else {
                if (isset($tagMap[$tag['parent_id']])) {
                    $tagMap[$tag['parent_id']]['children'][] = &$tagMap[$tag['id']];
                }
            }
        }
        
        return $tree;
    }
    
    /**
     * Récupère tous les parents d'un tag (jusqu'à la racine)
     */
    public static function getParents(int $tagId): array
    {
        $db = Database::getInstance();
        $parents = [];
        $currentId = $tagId;
        $maxDepth = 5; // Limite de profondeur
        
        for ($i = 0; $i < $maxDepth; $i++) {
            $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
            $stmt->execute([$currentId]);
            $tag = $stmt->fetch();
            
            if (!$tag || !$tag['parent_id']) {
                break;
            }
            
            $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
            $stmt->execute([$tag['parent_id']]);
            $parent = $stmt->fetch();
            
            if ($parent) {
                $parents[] = $parent;
                $currentId = $parent['id'];
            } else {
                break;
            }
        }
        
        return array_reverse($parents); // Retourner du parent le plus proche au plus lointain
    }
    
    /**
     * Ajoute un tag à un document avec propagation automatique des parents
     */
    public static function addToDocument(int $documentId, int $tagId): void
    {
        $db = Database::getInstance();
        
        // Ajouter le tag lui-même
        $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$documentId, $tagId]);
        
        // Ajouter tous les parents automatiquement
        $parents = self::getParents($tagId);
        foreach ($parents as $parent) {
            $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$documentId, $parent['id']]);
        }
    }
    
    /**
     * Retire un tag d'un document avec suppression automatique des enfants
     */
    public static function removeFromDocument(int $documentId, int $tagId): void
    {
        $db = Database::getInstance();
        
        // Retirer le tag lui-même
        $db->prepare("DELETE FROM document_tags WHERE document_id = ? AND tag_id = ?")->execute([$documentId, $tagId]);
        
        // Retirer tous les enfants automatiquement
        $children = self::getChildren($tagId);
        foreach ($children as $child) {
            self::removeFromDocument($documentId, $child['id']); // Récursif
        }
    }
}
