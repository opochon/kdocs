<?php
/**
 * K-Docs - Service de Matching (Phase 3.1)
 * Implémente tous les algorithmes de matching pour tags, correspondants, types, storage paths
 */

namespace KDocs\Services;

class MatchingService
{
    /**
     * Vérifie si un texte correspond selon l'algorithme et le match défini
     */
    public static function match(string $text, string $algorithm, string $match): bool
    {
        if (empty($match) || $algorithm === 'none') {
            return false;
        }
        
        $text = strtolower(trim($text));
        $match = trim($match);
        
        switch ($algorithm) {
            case 'any':
                return self::matchAny($text, $match);
                
            case 'all':
                return self::matchAll($text, $match);
                
            case 'exact':
                return self::matchExact($text, $match);
                
            case 'regex':
                return self::matchRegex($text, $match);
                
            case 'fuzzy':
                return self::matchFuzzy($text, $match);
                
            case 'auto':
                // Auto matching nécessite un modèle ML, pour l'instant on utilise fuzzy
                return self::matchFuzzy($text, $match);
                
            default:
                return false;
        }
    }
    
    /**
     * Any: Au moins un mot du match doit être présent
     */
    private static function matchAny(string $text, string $match): bool
    {
        // Gérer les termes entre guillemets
        preg_match_all('/"([^"]+)"/', $match, $quotedMatches);
        $quotedTerms = $quotedMatches[1];
        $remainingMatch = preg_replace('/"[^"]+"/', '', $match);
        $terms = array_filter(array_map('trim', explode(' ', $remainingMatch)));
        
        // Vérifier les termes entre guillemets
        foreach ($quotedTerms as $term) {
            if (stripos($text, strtolower($term)) !== false) {
                return true;
            }
        }
        
        // Vérifier les autres termes
        foreach ($terms as $term) {
            if (stripos($text, strtolower($term)) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * All: Tous les mots du match doivent être présents
     */
    private static function matchAll(string $text, string $match): bool
    {
        // Gérer les termes entre guillemets
        preg_match_all('/"([^"]+)"/', $match, $quotedMatches);
        $quotedTerms = $quotedMatches[1];
        $remainingMatch = preg_replace('/"[^"]+"/', '', $match);
        $terms = array_filter(array_map('trim', explode(' ', $remainingMatch)));
        
        // Vérifier tous les termes entre guillemets
        foreach ($quotedTerms as $term) {
            if (stripos($text, strtolower($term)) === false) {
                return false;
            }
        }
        
        // Vérifier tous les autres termes
        foreach ($terms as $term) {
            if (stripos($text, strtolower($term)) === false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Exact: Le match doit apparaître exactement tel quel
     */
    private static function matchExact(string $text, string $match): bool
    {
        return stripos($text, strtolower($match)) !== false;
    }
    
    /**
     * Regex: Le match est une expression régulière
     */
    private static function matchRegex(string $text, string $match): bool
    {
        try {
            return preg_match('/' . $match . '/i', $text) === 1;
        } catch (\Exception $e) {
            // Regex invalide
            return false;
        }
    }
    
    /**
     * Fuzzy: Correspondance approximative (similarité)
     */
    private static function matchFuzzy(string $text, string $match): bool
    {
        $matchLower = strtolower($match);
        
        // Calcul de similarité simple (ratio de caractères communs)
        $similarity = similar_text($text, $matchLower) / max(strlen($text), strlen($matchLower));
        
        // Seuil de 70% de similarité
        return $similarity >= 0.7;
    }
    
    /**
     * Applique le matching automatique pour un document
     * Retourne les tags, correspondants, types et storage paths à assigner
     */
    public static function applyMatching(string $documentText): array
    {
        $db = \KDocs\Core\Database::getInstance();
        $results = [
            'tags' => [],
            'correspondents' => [],
            'document_types' => [],
            'storage_paths' => []
        ];
        
        // Tags
        try {
            $tags = $db->query("SELECT id, match, matching_algorithm FROM tags WHERE match IS NOT NULL AND match != '' AND matching_algorithm != 'none'")->fetchAll();
            foreach ($tags as $tag) {
                if (self::match($documentText, $tag['matching_algorithm'], $tag['match'])) {
                    $results['tags'][] = $tag['id'];
                }
            }
        } catch (\Exception $e) {}
        
        // Correspondants
        try {
            $correspondents = $db->query("SELECT id, match, matching_algorithm FROM correspondents WHERE match IS NOT NULL AND match != '' AND matching_algorithm != 'none'")->fetchAll();
            foreach ($correspondents as $corr) {
                if (self::match($documentText, $corr['matching_algorithm'], $corr['match'])) {
                    $results['correspondents'][] = $corr['id'];
                }
            }
        } catch (\Exception $e) {}
        
        // Document Types
        try {
            $types = $db->query("SELECT id, match, matching_algorithm FROM document_types WHERE match IS NOT NULL AND match != '' AND matching_algorithm != 'none'")->fetchAll();
            foreach ($types as $type) {
                if (self::match($documentText, $type['matching_algorithm'], $type['match'])) {
                    $results['document_types'][] = $type['id'];
                }
            }
        } catch (\Exception $e) {}
        
        // Storage Paths
        try {
            $storagePaths = $db->query("SELECT id, match, matching_algorithm FROM storage_paths WHERE match IS NOT NULL AND match != '' AND matching_algorithm != 'none'")->fetchAll();
            foreach ($storagePaths as $spath) {
                if (self::match($documentText, $spath['matching_algorithm'], $spath['match'])) {
                    $results['storage_paths'][] = $spath['id'];
                }
            }
        } catch (\Exception $e) {}
        
        return $results;
    }
}
