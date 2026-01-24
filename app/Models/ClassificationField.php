<?php
/**
 * K-Docs - Modèle ClassificationField
 * Gère les champs paramétrables pour la classification
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class ClassificationField
{
    public static function all(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM classification_fields ORDER BY storage_path_position ASC, field_name ASC")->fetchAll();
    }
    
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM classification_fields WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function findByCode(string $code): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM classification_fields WHERE field_code = ?");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }
    
    public static function getActive(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM classification_fields WHERE is_active = TRUE ORDER BY storage_path_position ASC")->fetchAll();
    }
    
    public static function getForStoragePath(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT * FROM classification_fields WHERE is_active = TRUE AND use_for_storage_path = TRUE ORDER BY storage_path_position ASC")->fetchAll();
    }
    
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO classification_fields (
                field_code, field_name, field_type, is_active, 
                use_for_storage_path, storage_path_position, use_for_tag,
                matching_keywords, matching_algorithm, use_ai, ai_prompt, is_required
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['field_code'],
            $data['field_name'],
            $data['field_type'],
            $data['is_active'] ?? true,
            $data['use_for_storage_path'] ?? false,
            $data['storage_path_position'] ?? null,
            $data['use_for_tag'] ?? false,
            $data['matching_keywords'] ?? null,
            $data['matching_algorithm'] ?? 'any',
            $data['use_ai'] ?? false,
            $data['ai_prompt'] ?? null,
            $data['is_required'] ?? false
        ]);
        return $db->lastInsertId();
    }
    
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            UPDATE classification_fields 
            SET field_name = ?, field_type = ?, is_active = ?,
                use_for_storage_path = ?, storage_path_position = ?, use_for_tag = ?,
                matching_keywords = ?, matching_algorithm = ?, use_ai = ?, ai_prompt = ?, is_required = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $data['field_name'],
            $data['field_type'],
            $data['is_active'] ?? true,
            $data['use_for_storage_path'] ?? false,
            $data['storage_path_position'] ?? null,
            $data['use_for_tag'] ?? false,
            $data['matching_keywords'] ?? null,
            $data['matching_algorithm'] ?? 'any',
            $data['use_ai'] ?? false,
            $data['ai_prompt'] ?? null,
            $data['is_required'] ?? false,
            $id
        ]);
    }
    
    /**
     * Vérifie si un champ est utilisé dans des documents
     */
    public static function isUsed(int $id): array
    {
        $db = Database::getInstance();
        $field = self::find($id);
        
        if (!$field) {
            return ['used' => false, 'count' => 0, 'message' => ''];
        }
        
        $fieldCode = $field['field_code'];
        $count = 0;
        $details = [];
        
        // Vérifier selon le type de champ
        switch ($fieldCode) {
            case 'date':
                $stmt = $db->query("SELECT COUNT(*) FROM documents WHERE doc_date IS NOT NULL");
                $count = (int)$stmt->fetchColumn();
                if ($count > 0) {
                    $details[] = "$count document(s) avec une date";
                }
                break;
                
            case 'type':
            case 'document_type':
                $stmt = $db->query("SELECT COUNT(*) FROM documents WHERE document_type_id IS NOT NULL");
                $count = (int)$stmt->fetchColumn();
                if ($count > 0) {
                    $details[] = "$count document(s) avec un type";
                }
                break;
                
            case 'supplier':
            case 'correspondent':
                $stmt = $db->query("SELECT COUNT(*) FROM documents WHERE correspondent_id IS NOT NULL");
                $count = (int)$stmt->fetchColumn();
                if ($count > 0) {
                    $details[] = "$count document(s) avec un correspondant";
                }
                break;
                
            case 'amount':
                $stmt = $db->query("SELECT COUNT(*) FROM documents WHERE amount IS NOT NULL");
                $count = (int)$stmt->fetchColumn();
                if ($count > 0) {
                    $details[] = "$count document(s) avec un montant";
                }
                break;
                
            case 'year':
                // L'année est toujours utilisée pour le stockage
                $stmt = $db->query("SELECT COUNT(*) FROM documents");
                $count = (int)$stmt->fetchColumn();
                if ($count > 0) {
                    $details[] = "Utilisé pour le stockage de $count document(s)";
                }
                break;
                
            default:
                // Pour les champs personnalisés, vérifier dans custom_fields
                try {
                    $customField = $db->prepare("SELECT id FROM custom_fields WHERE field_code = ?");
                    $customField->execute([$fieldCode]);
                    $cf = $customField->fetch();
                    if ($cf) {
                        $stmt = $db->prepare("SELECT COUNT(*) FROM document_custom_field_values WHERE custom_field_id = ?");
                        $stmt->execute([$cf['id']]);
                        $count = (int)$stmt->fetchColumn();
                        if ($count > 0) {
                            $details[] = "$count document(s) avec ce champ personnalisé";
                        }
                    }
                } catch (\Exception $e) {
                    // Table peut ne pas exister
                }
                break;
        }
        
        return [
            'used' => $count > 0,
            'count' => $count,
            'message' => implode(', ', $details)
        ];
    }
    
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        
        // Vérifier si le champ est obligatoire
        $field = self::find($id);
        if ($field && !empty($field['is_required'])) {
            throw new \Exception("Ce champ est obligatoire et ne peut pas être supprimé");
        }
        
        // Vérifier si le champ est utilisé
        $usage = self::isUsed($id);
        if ($usage['used']) {
            throw new \Exception("Ce champ est utilisé dans {$usage['count']} document(s). {$usage['message']}");
        }
        
        $stmt = $db->prepare("DELETE FROM classification_fields WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
