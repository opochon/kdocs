<?php
/**
 * Générateur de chemins de stockage dynamiques
 * Basé sur les champs de classification paramétrables
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class StoragePathGenerator
{
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Génère un chemin de stockage basé sur les champs détectés
     * 
     * @param array $documentData Données du document (correspondent_id, document_type_id, doc_date, etc.)
     * @return string Chemin relatif (ex: "2026/Fournisseurs/ABC/Factures")
     */
    public function generatePath(array $documentData): string
    {
        // Récupérer les champs actifs pour le stockage
        $fields = $this->getActiveStorageFields();
        
        if (empty($fields)) {
            // Fallback : structure par défaut
            return $this->generateDefaultPath($documentData);
        }
        
        $pathParts = [];
        
        // Trier par position dans le chemin
        usort($fields, function($a, $b) {
            return ($a['storage_path_position'] ?? 999) <=> ($b['storage_path_position'] ?? 999);
        });
        
        foreach ($fields as $field) {
            $value = $this->extractFieldValue($field, $documentData);
            if ($value) {
                $pathParts[] = $this->sanitizePathSegment($value);
            }
        }
        
        return !empty($pathParts) ? implode('/', $pathParts) : $this->generateDefaultPath($documentData);
    }
    
    /**
     * Génère un chemin par défaut si aucun champ configuré
     * Structure: Année/Fournisseurs/Nom/Type
     */
    private function generateDefaultPath(array $documentData): string
    {
        $parts = [];
        
        // Année depuis la date du document ou date d'upload
        $year = null;
        if (!empty($documentData['doc_date'])) {
            $year = date('Y', strtotime($documentData['doc_date']));
        } elseif (!empty($documentData['uploaded_at'])) {
            $year = date('Y', strtotime($documentData['uploaded_at']));
        } else {
            $year = date('Y');
        }
        $parts[] = $year;
        
        // Correspondant/Fournisseur
        if (!empty($documentData['correspondent_id'])) {
            $corrStmt = $this->db->prepare("SELECT name FROM correspondents WHERE id = ?");
            $corrStmt->execute([$documentData['correspondent_id']]);
            $corr = $corrStmt->fetch();
            if ($corr) {
                $parts[] = 'Fournisseurs';
                $parts[] = $this->sanitizePathSegment($corr['name']);
            }
        }
        
        // Type de document
        if (!empty($documentData['document_type_id'])) {
            $typeStmt = $this->db->prepare("SELECT label FROM document_types WHERE id = ?");
            $typeStmt->execute([$documentData['document_type_id']]);
            $type = $typeStmt->fetch();
            if ($type) {
                $parts[] = $this->sanitizePathSegment($type['label']);
            }
        }
        
        return !empty($parts) ? implode('/', $parts) : $year . '/Divers';
    }
    
    /**
     * Extrait la valeur d'un champ depuis les données du document
     */
    private function extractFieldValue(array $field, array $documentData): ?string
    {
        $code = $field['field_code'] ?? '';
        
        switch ($code) {
            case 'year':
                if (!empty($documentData['doc_date'])) {
                    return date('Y', strtotime($documentData['doc_date']));
                }
                if (!empty($documentData['uploaded_at'])) {
                    return date('Y', strtotime($documentData['uploaded_at']));
                }
                return date('Y');
                
            case 'supplier':
            case 'correspondent':
                if (!empty($documentData['correspondent_id'])) {
                    $stmt = $this->db->prepare("SELECT name FROM correspondents WHERE id = ?");
                    $stmt->execute([$documentData['correspondent_id']]);
                    $result = $stmt->fetch();
                    return $result['name'] ?? null;
                }
                // Essayer aussi avec correspondent_name si présent
                if (!empty($documentData['correspondent_name'])) {
                    return $documentData['correspondent_name'];
                }
                return null;
                
            case 'type':
            case 'document_type':
                if (!empty($documentData['document_type_id'])) {
                    $stmt = $this->db->prepare("SELECT label FROM document_types WHERE id = ?");
                    $stmt->execute([$documentData['document_type_id']]);
                    $result = $stmt->fetch();
                    return $result['label'] ?? null;
                }
                // Essayer aussi avec document_type_name si présent
                if (!empty($documentData['document_type_name'])) {
                    return $documentData['document_type_name'];
                }
                return null;
                
            case 'amount':
                return !empty($documentData['amount']) ? number_format($documentData['amount'], 2) : null;
                
            case 'date':
                return !empty($documentData['doc_date']) ? date('Y-m-d', strtotime($documentData['doc_date'])) : null;
                
            default:
                // Champ personnalisé
                if (!empty($documentData['custom_fields'])) {
                    foreach ($documentData['custom_fields'] as $cf) {
                        if (($cf['field_code'] ?? '') === $code) {
                            return $cf['value'] ?? null;
                        }
                    }
                }
                return null;
        }
    }
    
    /**
     * Récupère les champs actifs pour le stockage
     */
    private function getActiveStorageFields(): array
    {
        try {
            return $this->db->query("
                SELECT * FROM classification_fields 
                WHERE is_active = TRUE 
                AND use_for_storage_path = TRUE 
                ORDER BY storage_path_position ASC
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Table n'existe pas encore, retourner vide
            return [];
        }
    }
    
    /**
     * Nettoie un segment de chemin pour éviter les caractères interdits
     */
    private function sanitizePathSegment(string $segment): string
    {
        // Remplacer les caractères interdits
        $segment = preg_replace('/[<>:"|?*\x00-\x1F]/', '_', $segment);
        // Remplacer les espaces multiples par un seul
        $segment = preg_replace('/\s+/', ' ', $segment);
        // Trim et remplacer espaces par underscores
        $segment = trim($segment);
        $segment = str_replace(' ', '_', $segment);
        // Limiter la longueur
        return mb_substr($segment, 0, 100) ?: 'Divers';
    }
    
    /**
     * Génère un chemin avec préfixe personnalisé
     * Ex: "Fournisseurs/ABC/Factures" au lieu de "2026/Fournisseurs/ABC/Factures"
     */
    public function generatePathWithPrefix(string $prefix, array $documentData): string
    {
        $path = $this->generatePath($documentData);
        return $prefix ? $prefix . '/' . $path : $path;
    }
}
