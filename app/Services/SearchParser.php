<?php
/**
 * K-Docs - Service de parsing de recherche avancée (Priorité 2.4)
 * 
 * Supporte les opérateurs :
 * - title:facture (recherche dans le titre)
 * - correspondent:ACME (recherche par correspondant)
 * - type:facture (recherche par type)
 * - tag:important (recherche par tag)
 * - date:2024-01-01 (recherche par date exacte)
 * - date:>2024-01-01 (recherche par date supérieure)
 * - date:<2024-01-01 (recherche par date inférieure)
 * - amount:>100 (recherche par montant)
 * - AND, OR, NOT (opérateurs logiques)
 */

namespace KDocs\Services;

class SearchParser
{
    /**
     * Parse une requête de recherche avancée
     * 
     * @param string $query La requête de recherche
     * @return array Tableau avec les conditions SQL et les paramètres
     */
    public static function parse(string $query): array
    {
        $query = trim($query);
        
        if (empty($query)) {
            return ['conditions' => [], 'params' => []];
        }
        
        $conditions = [];
        $params = [];
        $paramIndex = 1;
        
        // Détecter les opérateurs logiques
        $parts = preg_split('/\s+(AND|OR|NOT)\s+/i', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        $currentOperator = 'AND';
        $whereParts = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            
            if (empty($part)) {
                continue;
            }
            
            // Vérifier si c'est un opérateur logique
            if (in_array(strtoupper($part), ['AND', 'OR', 'NOT'])) {
                $currentOperator = strtoupper($part);
                continue;
            }
            
            // Parser la partie
            $parsed = self::parsePart($part, $paramIndex);
            
            if (!empty($parsed['condition'])) {
                $whereParts[] = [
                    'operator' => $currentOperator,
                    'condition' => $parsed['condition'],
                    'params' => $parsed['params']
                ];
                $params = array_merge($params, $parsed['params']);
                $paramIndex += count($parsed['params']);
                $currentOperator = 'AND'; // Reset après utilisation
            }
        }
        
        // Construire la clause WHERE
        $whereClause = '';
        $finalParams = [];
        
        foreach ($whereParts as $index => $part) {
            if ($index > 0) {
                $whereClause .= ' ' . $part['operator'] . ' ';
            }
            
            if ($part['operator'] === 'NOT') {
                $whereClause .= 'NOT (' . $part['condition'] . ')';
            } else {
                $whereClause .= '(' . $part['condition'] . ')';
            }
            
            $finalParams = array_merge($finalParams, $part['params']);
        }
        
        return [
            'where' => $whereClause,
            'params' => $finalParams
        ];
    }
    
    /**
     * Parse une partie de la requête
     */
    private static function parsePart(string $part, int $paramIndex): array
    {
        // Recherche par champ spécifique (ex: title:facture)
        if (preg_match('/^(\w+):(.+)$/', $part, $matches)) {
            $field = strtolower($matches[1]);
            $value = trim($matches[2]);
            
            return self::parseFieldSearch($field, $value, $paramIndex);
        }
        
        // Recherche générale (tous les champs)
        return self::parseGeneralSearch($part, $paramIndex);
    }
    
    /**
     * Parse une recherche par champ spécifique
     */
    private static function parseFieldSearch(string $field, string $value, int $paramIndex): array
    {
        $conditions = [];
        $params = [];
        
        switch ($field) {
            case 'title':
                $conditions[] = "(d.title LIKE ? OR d.original_filename LIKE ? OR d.filename LIKE ?)";
                $searchTerm = '%' . $value . '%';
                $params = [$searchTerm, $searchTerm, $searchTerm];
                break;
                
            case 'correspondent':
            case 'correspondent_name':
                $conditions[] = "c.name LIKE ?";
                $params[] = '%' . $value . '%';
                break;
                
            case 'type':
            case 'document_type':
                $conditions[] = "dt.label LIKE ? OR dt.code LIKE ?";
                $params[] = '%' . $value . '%';
                $params[] = '%' . $value . '%';
                break;
                
            case 'tag':
            case 'tags':
                $conditions[] = "EXISTS (SELECT 1 FROM document_tags dt INNER JOIN tags t ON dt.tag_id = t.id WHERE dt.document_id = d.id AND t.name LIKE ?)";
                $params[] = '%' . $value . '%';
                break;
                
            case 'date':
            case 'document_date':
                // Supporte date:2024-01-01, date:>2024-01-01, date:<2024-01-01
                if (preg_match('/^([><=]+)?(.+)$/', $value, $dateMatches)) {
                    $operator = $dateMatches[1] ?: '=';
                    $dateValue = trim($dateMatches[2]);
                    
                    if ($operator === '>') {
                        $conditions[] = "d.document_date >= ?";
                    } elseif ($operator === '<') {
                        $conditions[] = "d.document_date <= ?";
                    } else {
                        $conditions[] = "DATE(d.document_date) = ?";
                    }
                    $params[] = $dateValue;
                }
                break;
                
            case 'amount':
            case 'montant':
                // Supporte amount:>100, amount:<100, amount:100
                if (preg_match('/^([><=]+)?(.+)$/', $value, $amountMatches)) {
                    $operator = $amountMatches[1] ?: '=';
                    $amountValue = floatval(trim($amountMatches[2]));
                    
                    if ($operator === '>') {
                        $conditions[] = "d.amount > ?";
                    } elseif ($operator === '<') {
                        $conditions[] = "d.amount < ?";
                    } else {
                        $conditions[] = "d.amount = ?";
                    }
                    $params[] = $amountValue;
                }
                break;
                
            case 'ocr':
            case 'ocr_text':
            case 'content':
                $conditions[] = "(d.ocr_text LIKE ? OR d.content LIKE ?)";
                $searchTerm = '%' . $value . '%';
                $params = [$searchTerm, $searchTerm];
                break;
                
            default:
                // Champ inconnu, recherche générale
                return self::parseGeneralSearch($value, $paramIndex);
        }
        
        return [
            'condition' => implode(' OR ', $conditions),
            'params' => $params
        ];
    }
    
    /**
     * Parse une recherche générale (tous les champs)
     */
    private static function parseGeneralSearch(string $value, int $paramIndex): array
    {
        $searchTerm = '%' . $value . '%';
        
        $condition = "(
            d.title LIKE ? OR 
            d.original_filename LIKE ? OR 
            d.filename LIKE ? OR 
            d.ocr_text LIKE ? OR 
            d.content LIKE ? OR
            c.name LIKE ? OR
            dt.label LIKE ?
        )";
        
        $params = [
            $searchTerm, // title
            $searchTerm, // original_filename
            $searchTerm, // filename
            $searchTerm, // ocr_text
            $searchTerm, // content
            $searchTerm, // correspondent name
            $searchTerm  // document type label
        ];
        
        return [
            'condition' => $condition,
            'params' => $params
        ];
    }
}
