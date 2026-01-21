<?php
/**
 * K-Docs - Modèle MailRule
 * Règles de traitement automatique des emails
 */

namespace KDocs\Models;

use KDocs\Core\Database;

class MailRule
{
    /**
     * Récupère toutes les règles pour un compte
     */
    public static function getByAccount(int $accountId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT r.*, 
                   GROUP_CONCAT(mrt.tag_id) as tag_ids
            FROM mail_rules r
            LEFT JOIN mail_rule_tags mrt ON r.id = mrt.mail_rule_id
            WHERE r.mail_account_id = ? AND r.is_active = TRUE
            GROUP BY r.id
            ORDER BY r.order_index ASC, r.id ASC
        ");
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Trouve une règle par ID
     */
    public static function find(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM mail_rules WHERE id = ?");
        $stmt->execute([$id]);
        $rule = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($rule) {
            // Récupérer les tags
            $tagStmt = $db->prepare("SELECT tag_id FROM mail_rule_tags WHERE mail_rule_id = ?");
            $tagStmt->execute([$id]);
            $rule['tag_ids'] = $tagStmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        
        return $rule ?: null;
    }
    
    /**
     * Crée une nouvelle règle
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            INSERT INTO mail_rules (
                mail_account_id, name, order_index,
                filter_from, filter_subject, filter_body, filter_attachment_filename,
                maximum_age, action, action_parameter,
                assign_title_from, assign_correspondent_from,
                assign_document_type_id, assign_storage_path_id, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['mail_account_id'],
            $data['name'],
            $data['order_index'] ?? 0,
            $data['filter_from'] ?? null,
            $data['filter_subject'] ?? null,
            $data['filter_body'] ?? null,
            $data['filter_attachment_filename'] ?? null,
            $data['maximum_age'] ?? null,
            $data['action'] ?? 'tag',
            $data['action_parameter'] ?? null,
            $data['assign_title_from'] ?? 'subject',
            $data['assign_correspondent_from'] ?? 'from',
            $data['assign_document_type_id'] ?? null,
            $data['assign_storage_path_id'] ?? null,
            $data['is_active'] ?? true
        ]);
        
        $ruleId = (int)$db->lastInsertId();
        
        // Ajouter les tags
        if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
            self::setTags($ruleId, $data['tag_ids']);
        }
        
        return $ruleId;
    }
    
    /**
     * Met à jour une règle
     */
    public static function update(int $id, array $data): bool
    {
        $db = Database::getInstance();
        
        $fields = [];
        $params = [];
        
        $allowedFields = ['name', 'order_index', 'filter_from', 'filter_subject', 'filter_body',
                         'filter_attachment_filename', 'maximum_age', 'action', 'action_parameter',
                         'assign_title_from', 'assign_correspondent_from',
                         'assign_document_type_id', 'assign_storage_path_id', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $id;
        $stmt = $db->prepare("UPDATE mail_rules SET " . implode(', ', $fields) . " WHERE id = ?");
        $result = $stmt->execute($params);
        
        // Mettre à jour les tags
        if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
            self::setTags($id, $data['tag_ids']);
        }
        
        return $result;
    }
    
    /**
     * Supprime une règle
     */
    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM mail_rules WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Définit les tags d'une règle
     */
    private static function setTags(int $ruleId, array $tagIds): void
    {
        $db = Database::getInstance();
        
        // Supprimer les tags existants
        $db->prepare("DELETE FROM mail_rule_tags WHERE mail_rule_id = ?")->execute([$ruleId]);
        
        // Ajouter les nouveaux tags
        $stmt = $db->prepare("INSERT INTO mail_rule_tags (mail_rule_id, tag_id) VALUES (?, ?)");
        foreach ($tagIds as $tagId) {
            $stmt->execute([$ruleId, (int)$tagId]);
        }
    }
    
    /**
     * Vérifie si un email correspond à une règle
     */
    public static function matches(array $rule, array $email): bool
    {
        // Filtre expéditeur
        if (!empty($rule['filter_from']) && stripos($email['from'] ?? '', $rule['filter_from']) === false) {
            return false;
        }
        
        // Filtre objet
        if (!empty($rule['filter_subject']) && stripos($email['subject'] ?? '', $rule['filter_subject']) === false) {
            return false;
        }
        
        // Filtre corps
        if (!empty($rule['filter_body']) && stripos($email['body'] ?? '', $rule['filter_body']) === false) {
            return false;
        }
        
        // Filtre pièce jointe
        if (!empty($rule['filter_attachment_filename'])) {
            $hasMatchingAttachment = false;
            foreach ($email['attachments'] ?? [] as $attachment) {
                if (stripos($attachment['filename'] ?? '', $rule['filter_attachment_filename']) !== false) {
                    $hasMatchingAttachment = true;
                    break;
                }
            }
            if (!$hasMatchingAttachment) {
                return false;
            }
        }
        
        // Âge maximum
        if (!empty($rule['maximum_age']) && isset($email['date'])) {
            $emailDate = new \DateTime($email['date']);
            $maxDate = new \DateTime("-{$rule['maximum_age']} days");
            if ($emailDate < $maxDate) {
                return false;
            }
        }
        
        return true;
    }
}
