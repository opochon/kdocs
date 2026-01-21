<?php
/**
 * K-Docs - Modèle StoragePath
 * Chemins de stockage personnalisés
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class StoragePath
{
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM storage_paths ORDER BY name")->fetchAll();
    }
    
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM storage_paths WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function findByPath(string $path): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM storage_paths WHERE path = ?");
        $stmt->execute([$path]);
        return $stmt->fetch() ?: null;
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO storage_paths (name, path, match, matching_algorithm)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['path'],
            $data['match'] ?? null,
            $data['matching_algorithm'] ?? 'none'
        ]);
        return $db->lastInsertId();
    }
    
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE storage_paths 
            SET name = ?, path = ?, match = ?, matching_algorithm = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['name'],
            $data['path'],
            $data['match'] ?? null,
            $data['matching_algorithm'] ?? 'none',
            $id
        ]);
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM storage_paths WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    public static function matchDocument(string $text, string $algorithm, string $match): bool
    {
        switch ($algorithm) {
            case 'none':
                return false;
            case 'any':
                $terms = explode(' ', $match);
                foreach ($terms as $term) {
                    if (stripos($text, $term) !== false) return true;
                }
                return false;
            case 'all':
                $terms = explode(' ', $match);
                foreach ($terms as $term) {
                    if (stripos($text, $term) === false) return false;
                }
                return true;
            case 'exact':
                return stripos($text, $match) !== false;
            case 'regex':
                return preg_match('/' . $match . '/i', $text) === 1;
            case 'fuzzy':
                // Implémentation basique de fuzzy matching
                return similar_text(strtolower($text), strtolower($match)) / max(strlen($text), strlen($match)) > 0.7;
            default:
                return false;
        }
    }
}
