<?php
/**
 * K-Docs - Service de parsing de recherche avancée (Priorité 2.4)
 * 
 * Supporte les opérateurs :
 * - tag:facture (recherche par tag)
 * - correspondent:swisscom ou from:swisscom (recherche par correspondant)
 * - type:facture (recherche par type)
 * - date:2024 ou date:2024-01 ou date:2024-01-15 (recherche par date)
 * - after:2024-01-01 before:2024-12-31 (recherche par plage de dates)
 * - asn:123 (recherche par Archive Serial Number)
 * - amount:>100 ou amount:<500 (recherche par montant)
 * - Recherche full-text sur le reste (titre, contenu, nom de fichier)
 */

namespace KDocs\Services;

class SearchParser
{
    /**
     * Parse une requête de recherche et retourne les conditions SQL
     * 
     * @param string $query La requête de recherche
     * @return array Tableau avec 'conditions' (array), 'params' (array), 'sql' (string)
     */
    public static function parse(string $query): array
    {
        $conditions = [];
        $params = [];
        
        // Recherche par tag: tag:facture
        if (preg_match_all('/tag:(\w+)/i', $query, $matches)) {
            foreach ($matches[1] as $tag) {
                $conditions[] = "EXISTS (SELECT 1 FROM document_tags dt JOIN tags t ON dt.tag_id = t.id WHERE dt.document_id = d.id AND t.name LIKE ?)";
                $params[] = "%$tag%";
            }
            $query = preg_replace('/tag:\w+/i', '', $query);
        }
        
        // Recherche par correspondent: correspondent:swisscom ou from:swisscom
        if (preg_match_all('/(correspondent|from):(\w+)/i', $query, $matches)) {
            foreach ($matches[2] as $corr) {
                $conditions[] = "EXISTS (SELECT 1 FROM correspondents c WHERE c.id = d.correspondent_id AND c.name LIKE ?)";
                $params[] = "%$corr%";
            }
            $query = preg_replace('/(correspondent|from):\w+/i', '', $query);
        }
        
        // Recherche par type: type:facture
        if (preg_match_all('/type:(\w+)/i', $query, $matches)) {
            foreach ($matches[1] as $type) {
                $conditions[] = "EXISTS (SELECT 1 FROM document_types dt WHERE dt.id = d.document_type_id AND (dt.label LIKE ? OR dt.code LIKE ?))";
                $params[] = "%$type%";
                $params[] = "%$type%";
            }
            $query = preg_replace('/type:\w+/i', '', $query);
        }
        
        // Recherche par date: date:2024 ou date:2024-01 ou date:2024-01-15
        if (preg_match_all('/date:(\d{4}(?:-\d{2})?(?:-\d{2})?)/i', $query, $matches)) {
            foreach ($matches[1] as $date) {
                $conditions[] = "d.document_date LIKE ?";
                $params[] = "$date%";
            }
            $query = preg_replace('/date:\d{4}(?:-\d{2})?(?:-\d{2})?/i', '', $query);
        }
        
        // Recherche par plage de dates: after:2024-01-01 before:2024-12-31
        if (preg_match('/after:(\d{4}-\d{2}-\d{2})/i', $query, $match)) {
            $conditions[] = "d.document_date >= ?";
            $params[] = $match[1];
            $query = preg_replace('/after:\d{4}-\d{2}-\d{2}/i', '', $query);
        }
        if (preg_match('/before:(\d{4}-\d{2}-\d{2})/i', $query, $match)) {
            $conditions[] = "d.document_date <= ?";
            $params[] = $match[1];
            $query = preg_replace('/before:\d{4}-\d{2}-\d{2}/i', '', $query);
        }
        
        // Recherche par ASN: asn:123
        if (preg_match('/asn:(\d+)/i', $query, $match)) {
            $conditions[] = "d.archive_serial_number = ?";
            $params[] = (int)$match[1];
            $query = preg_replace('/asn:\d+/i', '', $query);
        }
        
        // Recherche par montant: amount:>100 ou amount:<500 ou amount:>=100.50
        if (preg_match('/amount:([<>]=?)(\d+(?:\.\d+)?)/i', $query, $match)) {
            $op = $match[1];
            $amount = (float)$match[2];
            $conditions[] = "d.amount $op ?";
            $params[] = $amount;
            $query = preg_replace('/amount:[<>]=?\d+(?:\.\d+)?/i', '', $query);
        }
        
        // Recherche full-text sur le reste
        $query = trim($query);
        if (!empty($query)) {
            // Recherche dans titre ET contenu ET nom de fichier
            $conditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.original_filename LIKE ? OR d.filename LIKE ? OR d.ocr_text LIKE ?)";
            $searchTerm = "%$query%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        return [
            'conditions' => $conditions,
            'params' => $params,
            'where' => !empty($conditions) ? implode(' AND ', $conditions) : '1=1',
            'sql' => !empty($conditions) ? implode(' AND ', $conditions) : '1=1'
        ];
    }
}
