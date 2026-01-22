<?php
/**
 * K-Docs - Service de Matching (Parité Paperless-ngx)
 * Implémente tous les algorithmes de matching pour tags, correspondants, types, storage paths
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class MatchingService
{
    /**
     * Algorithmes disponibles (compatibles avec la base de données)
     */
    const ALGORITHM_NONE = 0;
    const ALGORITHM_ANY = 1;
    const ALGORITHM_ALL = 2;
    const ALGORITHM_EXACT = 3;
    const ALGORITHM_REGEX = 4;
    const ALGORITHM_FUZZY = 5;
    const ALGORITHM_AUTO = 6; // Machine learning (non implémenté pour l'instant)
    
    /**
     * Vérifie si un texte correspond selon l'algorithme et le match défini
     * 
     * @param string $text Texte dans lequel chercher
     * @param string|int $algorithm Algorithme (string 'any'/'all'/'exact'/'regex'/'fuzzy' ou int 0-6)
     * @param string $match Pattern à rechercher
     * @param bool $insensitive Insensible à la casse (par défaut true)
     * @return bool
     */
    public static function match(string $text, $algorithm, string $match, bool $insensitive = true): bool
    {
        if (empty($match)) {
            return false;
        }
        
        // Convertir l'algorithme en entier si c'est une chaîne
        if (is_string($algorithm)) {
            $algorithm = match(strtolower($algorithm)) {
                'none' => self::ALGORITHM_NONE,
                'any' => self::ALGORITHM_ANY,
                'all' => self::ALGORITHM_ALL,
                'exact' => self::ALGORITHM_EXACT,
                'regex' => self::ALGORITHM_REGEX,
                'fuzzy' => self::ALGORITHM_FUZZY,
                'auto' => self::ALGORITHM_AUTO,
                default => self::ALGORITHM_ANY,
            };
        }
        
        $algorithm = (int)$algorithm;
        
        if ($algorithm === self::ALGORITHM_NONE) {
            return false;
        }
        
        if ($insensitive) {
            $text = mb_strtolower(trim($text), 'UTF-8');
            $match = mb_strtolower(trim($match), 'UTF-8');
        } else {
            $text = trim($text);
            $match = trim($match);
        }
        
        switch ($algorithm) {
            case self::ALGORITHM_ANY:
                return self::matchAny($text, $match);
                
            case self::ALGORITHM_ALL:
                return self::matchAll($text, $match);
                
            case self::ALGORITHM_EXACT:
                return self::matchExact($text, $match);
                
            case self::ALGORITHM_REGEX:
                return self::matchRegex($text, $match, $insensitive);
                
            case self::ALGORITHM_FUZZY:
                return self::matchFuzzy($text, $match);
                
            case self::ALGORITHM_AUTO:
                // Auto matching nécessite un modèle ML, pour l'instant on utilise fuzzy
                return self::matchFuzzy($text, $match);
                
            default:
                return self::matchAny($text, $match);
        }
    }
    
    /**
     * Match si UN des mots est trouvé
     */
    private static function matchAny(string $content, string $pattern): bool
    {
        $words = self::splitWords($pattern);
        foreach ($words as $word) {
            if (mb_strpos($content, $word) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Match si TOUS les mots sont trouvés
     */
    private static function matchAll(string $content, string $pattern): bool
    {
        $words = self::splitWords($pattern);
        foreach ($words as $word) {
            if (mb_strpos($content, $word) === false) {
                return false;
            }
        }
        return count($words) > 0;
    }
    
    /**
     * Match exact de la chaîne
     */
    private static function matchExact(string $content, string $pattern): bool
    {
        return mb_strpos($content, $pattern) !== false;
    }
    
    /**
     * Match avec expression régulière
     */
    private static function matchRegex(string $content, string $pattern, bool $insensitive): bool
    {
        $flags = $insensitive ? 'iu' : 'u';
        
        // Échapper les délimiteurs si présents
        if (preg_match('/^\/.*\/[imsxADSUXJu]*$/', $pattern)) {
            // Pattern déjà délimité
            $regex = $pattern;
        } else {
            // Ajouter les délimiteurs
            $regex = '/' . $pattern . '/' . $flags;
        }
        
        try {
            return preg_match($regex, $content) === 1;
        } catch (\Exception $e) {
            error_log("Erreur regex matching: " . $e->getMessage() . " - Pattern: $pattern");
            return false;
        }
    }
    
    /**
     * Match approximatif (fuzzy) - 70% de similarité minimum
     */
    private static function matchFuzzy(string $content, string $pattern): bool
    {
        similar_text($content, $pattern, $percent);
        return $percent >= 70.0;
    }
    
    /**
     * Divise un texte en mots (supprime la ponctuation)
     */
    private static function splitWords(string $text): array
    {
        // Supprimer la ponctuation et diviser en mots
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', trim($text));
        return array_filter($words, function($word) {
            return mb_strlen($word) > 0;
        });
    }
    
    /**
     * Applique le matching automatique pour un tag/correspondent/type/storage_path
     * 
     * @param int $entityId ID de l'entité (tag, correspondent, etc.)
     * @param string $entityType Type d'entité ('tag', 'correspondent', 'document_type', 'storage_path')
     * @param string $documentContent Contenu du document (title + ocr_text)
     * @return bool True si match
     */
    public static function applyMatching(int $entityId, string $entityType, string $documentContent): bool
    {
        $db = Database::getInstance();
        
        $table = match($entityType) {
            'tag' => 'tags',
            'correspondent' => 'correspondents',
            'document_type' => 'document_types',
            'storage_path' => 'storage_paths',
            default => null,
        };
        
        if (!$table) {
            return false;
        }
        
        // Récupérer les paramètres de matching
        $stmt = $db->prepare("
            SELECT `match`, matching_algorithm, is_insensitive 
            FROM $table 
            WHERE id = ?
        ");
        $stmt->execute([$entityId]);
        $entity = $stmt->fetch();
        
        if (!$entity || empty($entity['match'])) {
            return false;
        }
        
        $algorithm = (int)($entity['matching_algorithm'] ?? self::ALGORITHM_ANY);
        $insensitive = (bool)($entity['is_insensitive'] ?? true);
        
        return self::match($documentContent, $algorithm, $entity['match'], $insensitive);
    }
    
    /**
     * Trouve automatiquement les tags/correspondents/types/storage_paths qui matchent un document
     * 
     * @param string $documentContent Contenu du document
     * @return array ['tags' => [...], 'correspondents' => [...], 'document_types' => [...], 'storage_paths' => [...]]
     */
    public static function findMatches(string $documentContent): array
    {
        $db = Database::getInstance();
        $matches = [
            'tags' => [],
            'correspondents' => [],
            'document_types' => [],
            'storage_paths' => [],
        ];
        
        // Tags
        try {
            $stmt = $db->query("
                SELECT id, name, `match`, matching_algorithm, is_insensitive 
                FROM tags 
                WHERE `match` IS NOT NULL AND `match` != '' AND matching_algorithm > 0
            ");
            while ($tag = $stmt->fetch()) {
                if (self::match($documentContent, (int)$tag['matching_algorithm'], $tag['match'], (bool)$tag['is_insensitive'])) {
                    $matches['tags'][] = $tag['id'];
                }
            }
        } catch (\Exception $e) {
            error_log("Erreur matching tags: " . $e->getMessage());
        }
        
        // Correspondents
        try {
            $stmt = $db->query("
                SELECT id, name, `match`, matching_algorithm, is_insensitive 
                FROM correspondents 
                WHERE `match` IS NOT NULL AND `match` != '' AND matching_algorithm > 0
            ");
            while ($corr = $stmt->fetch()) {
                if (self::match($documentContent, (int)$corr['matching_algorithm'], $corr['match'], (bool)$corr['is_insensitive'])) {
                    $matches['correspondents'][] = $corr['id'];
                }
            }
        } catch (\Exception $e) {
            error_log("Erreur matching correspondents: " . $e->getMessage());
        }
        
        // Document types
        try {
            $stmt = $db->query("
                SELECT id, label, `match`, matching_algorithm, is_insensitive 
                FROM document_types 
                WHERE `match` IS NOT NULL AND `match` != '' AND matching_algorithm > 0
            ");
            while ($type = $stmt->fetch()) {
                if (self::match($documentContent, (int)$type['matching_algorithm'], $type['match'], (bool)$type['is_insensitive'])) {
                    $matches['document_types'][] = $type['id'];
                }
            }
        } catch (\Exception $e) {
            error_log("Erreur matching document_types: " . $e->getMessage());
        }
        
        // Storage paths
        try {
            $stmt = $db->query("
                SELECT id, name, `match`, matching_algorithm, is_insensitive 
                FROM storage_paths 
                WHERE `match` IS NOT NULL AND `match` != '' AND matching_algorithm > 0
            ");
            while ($path = $stmt->fetch()) {
                if (self::match($documentContent, (int)$path['matching_algorithm'], $path['match'], (bool)$path['is_insensitive'])) {
                    $matches['storage_paths'][] = $path['id'];
                }
            }
        } catch (\Exception $e) {
            error_log("Erreur matching storage_paths: " . $e->getMessage());
        }
        
        return $matches;
    }
    
    /**
     * Applique le matching automatique à un document
     * 
     * @param int $documentId ID du document
     * @return array Résultats du matching
     */
    public static function applyToDocument(int $documentId): array
    {
        $db = Database::getInstance();
        
        // Récupérer le document avec son contenu
        $stmt = $db->prepare("
            SELECT id, title, ocr_text, content 
            FROM documents 
            WHERE id = ?
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            return ['error' => 'Document not found'];
        }
        
        $content = ($document['title'] ?? '') . ' ' . ($document['ocr_text'] ?? '') . ' ' . ($document['content'] ?? '');
        
        // Trouver les matches
        $matches = self::findMatches($content);
        
        $results = [
            'tags_added' => 0,
            'correspondent_set' => false,
            'document_type_set' => false,
            'storage_path_set' => false,
        ];
        
        // Appliquer les tags
        foreach ($matches['tags'] as $tagId) {
            try {
                $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                   ->execute([$documentId, $tagId]);
                $results['tags_added']++;
            } catch (\Exception $e) {
                error_log("Erreur ajout tag $tagId au document $documentId: " . $e->getMessage());
            }
        }
        
        // Appliquer le correspondent (premier match)
        if (!empty($matches['correspondents'])) {
            $corrId = $matches['correspondents'][0];
            try {
                $db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?")
                   ->execute([$corrId, $documentId]);
                $results['correspondent_set'] = true;
            } catch (\Exception $e) {
                error_log("Erreur assignation correspondent $corrId au document $documentId: " . $e->getMessage());
            }
        }
        
        // Appliquer le document type (premier match)
        if (!empty($matches['document_types'])) {
            $typeId = $matches['document_types'][0];
            try {
                $db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?")
                   ->execute([$typeId, $documentId]);
                $results['document_type_set'] = true;
            } catch (\Exception $e) {
                error_log("Erreur assignation type $typeId au document $documentId: " . $e->getMessage());
            }
        }
        
        // Appliquer le storage path (premier match)
        if (!empty($matches['storage_paths'])) {
            $pathId = $matches['storage_paths'][0];
            try {
                $db->prepare("UPDATE documents SET storage_path_id = ? WHERE id = ?")
                   ->execute([$pathId, $documentId]);
                $results['storage_path_set'] = true;
            } catch (\Exception $e) {
                error_log("Erreur assignation storage_path $pathId au document $documentId: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Méthode de compatibilité avec l'ancien code
     * @deprecated Utiliser findMatches() à la place
     */
    public static function applyMatchingLegacy(string $documentText): array
    {
        return self::findMatches($documentText);
    }
}
