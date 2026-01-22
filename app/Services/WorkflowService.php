<?php
/**
 * K-Docs - Service Workflow (Parité Paperless-ngx)
 * Exécute les workflows automatiques avec tous les triggers et actions
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Models\Workflow;
use KDocs\Models\Document;

class WorkflowService
{
    private $db;
    
    // Placeholders disponibles pour les templates
    private array $placeholders = [
        '{correspondent}' => 'correspondent_name',
        '{document_type}' => 'document_type_name', 
        '{title}' => 'title',
        '{created}' => 'created_at',
        '{created_year}' => 'created_year',
        '{created_month}' => 'created_month',
        '{created_day}' => 'created_day',
        '{added}' => 'added_at',
        '{added_year}' => 'added_year',
        '{added_month}' => 'added_month',
        '{added_day}' => 'added_day',
        '{asn}' => 'archive_serial_number',
        '{owner}' => 'owner_name',
        '{original_filename}' => 'original_filename',
    ];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Exécute les workflows pour un événement donné
     */
    public function executeForEvent(string $event, int $documentId, array $context = []): array
    {
        $results = [];
        
        // Récupérer le document avec toutes les relations
        $document = $this->getDocumentWithRelations($documentId);
        if (!$document) {
            return ['error' => 'Document not found'];
        }
        
        // Récupérer les workflows actifs pour cet événement
        $workflows = $this->getWorkflowsForEvent($event);
        
        foreach ($workflows as $workflow) {
            // Vérifier les triggers
            $triggers = Workflow::getTriggers($workflow['id']);
            $shouldExecute = false;
            
            foreach ($triggers as $trigger) {
                if ($this->triggerMatches($trigger, $document, $context)) {
                    $shouldExecute = true;
                    break;
                }
            }
            
            if (!$shouldExecute) {
                continue;
            }
            
            // Exécuter les actions
            $actions = Workflow::getActions($workflow['id']);
            foreach ($actions as $action) {
                try {
                    $result = $this->executeAction($action, $document);
                    $results[] = [
                        'workflow_id' => $workflow['id'],
                        'workflow_name' => $workflow['name'],
                        'action_type' => $action['action_type'],
                        'success' => true,
                        'result' => $result
                    ];
                    
                    // Logger l'exécution
                    Workflow::logExecution($workflow['id'], $documentId, 'success', json_encode($result));
                    
                } catch (\Exception $e) {
                    $results[] = [
                        'workflow_id' => $workflow['id'],
                        'workflow_name' => $workflow['name'],
                        'action_type' => $action['action_type'],
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    
                    Workflow::logExecution($workflow['id'], $documentId, 'error', $e->getMessage());
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Récupère un document avec toutes ses relations
     * Méthode publique pour permettre l'accès depuis WorkflowEngine
     */
    public function getDocumentWithRelations(int $documentId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   dt.label as document_type_label,
                   dt.code as document_type_code,
                   c.name as correspondent_name,
                   u.username as created_by_username,
                   sp.name as storage_path_name,
                   sp.path as storage_path_path,
                   owner.username as owner_name
            FROM documents d
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN users u ON d.created_by = u.id
            LEFT JOIN storage_paths sp ON d.storage_path_id = sp.id
            LEFT JOIN users owner ON d.owner_id = owner.id
            WHERE d.id = ? AND d.deleted_at IS NULL
        ");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if ($document) {
            // Ajouter les alias pour compatibilité
            $document['document_type_name'] = $document['document_type_label'] ?? null;
            $document['added_at'] = $document['created_at'] ?? null;
            $document['archive_serial_number'] = $document['asn'] ?? null;
        }
        
        return $document ?: null;
    }
    
    /**
     * Vérifie si un trigger correspond au document
     */
    private function triggerMatches(array $trigger, array $document, array $context = []): bool
    {
        $triggerType = $trigger['trigger_type'];
        
        // Vérifier le type de trigger correspond à l'événement
        // (géré au niveau de getWorkflowsForEvent)
        
        // Filtre par source (pour consumption)
        if (!empty($trigger['sources'])) {
            $sources = json_decode($trigger['sources'], true) ?: [];
            $currentSource = $context['source'] ?? 'unknown';
            if (!empty($sources) && !in_array($currentSource, $sources)) {
                return false;
            }
        }
        
        // Filtre par nom de fichier
        if (!empty($trigger['filter_filename'])) {
            $pattern = $trigger['filter_filename'];
            $filename = $document['original_filename'] ?? $document['filename'] ?? '';
            if (!$this->matchesPattern($filename, $pattern)) {
                return false;
            }
        }
        
        // Filtre par chemin
        if (!empty($trigger['filter_path'])) {
            $pattern = $trigger['filter_path'];
            $path = $document['file_path'] ?? '';
            if (!$this->matchesPattern($path, $pattern)) {
                return false;
            }
        }
        
        // Filtre par tags (has)
        if (!empty($trigger['filter_has_tags'])) {
            $requiredTags = json_decode($trigger['filter_has_tags'], true) ?: [];
            $documentTags = $this->getDocumentTagIds($document['id']);
            if (empty(array_intersect($requiredTags, $documentTags))) {
                return false;
            }
        }
        
        // Filtre par tags (has_all)
        if (!empty($trigger['filter_has_all_tags'])) {
            $requiredTags = json_decode($trigger['filter_has_all_tags'], true) ?: [];
            $documentTags = $this->getDocumentTagIds($document['id']);
            if (count(array_intersect($requiredTags, $documentTags)) !== count($requiredTags)) {
                return false;
            }
        }
        
        // Filtre par tags (has_not)
        if (!empty($trigger['filter_has_not_tags'])) {
            $excludedTags = json_decode($trigger['filter_has_not_tags'], true) ?: [];
            $documentTags = $this->getDocumentTagIds($document['id']);
            if (!empty(array_intersect($excludedTags, $documentTags))) {
                return false;
            }
        }
        
        // Filtre par correspondent
        if (!empty($trigger['filter_has_correspondent'])) {
            if ($document['correspondent_id'] != $trigger['filter_has_correspondent']) {
                return false;
            }
        }
        
        // Filtre par correspondents exclus
        if (!empty($trigger['filter_has_not_correspondents'])) {
            $excluded = json_decode($trigger['filter_has_not_correspondents'], true) ?: [];
            if (in_array($document['correspondent_id'], $excluded)) {
                return false;
            }
        }
        
        // Filtre par document type
        if (!empty($trigger['filter_has_document_type'])) {
            if ($document['document_type_id'] != $trigger['filter_has_document_type']) {
                return false;
            }
        }
        
        // Filtre par document types exclus
        if (!empty($trigger['filter_has_not_document_types'])) {
            $excluded = json_decode($trigger['filter_has_not_document_types'], true) ?: [];
            if (in_array($document['document_type_id'], $excluded)) {
                return false;
            }
        }
        
        // Filtre par storage path
        if (!empty($trigger['filter_has_storage_path'])) {
            if ($document['storage_path_id'] != $trigger['filter_has_storage_path']) {
                return false;
            }
        }
        
        // Filtre par storage paths exclus
        if (!empty($trigger['filter_has_not_storage_paths'])) {
            $excluded = json_decode($trigger['filter_has_not_storage_paths'], true) ?: [];
            if (in_array($document['storage_path_id'], $excluded)) {
                return false;
            }
        }
        
        // Filtre par match text
        if (!empty($trigger['match_text'])) {
            $text = $trigger['match_text'];
            $content = ($document['title'] ?? '') . ' ' . ($document['ocr_text'] ?? '') . ' ' . ($document['content'] ?? '');
            $algorithm = $trigger['matching_algorithm'] ?? 'any';
            $insensitive = $trigger['is_insensitive'] ?? true;
            
            if (!$this->matchesText($content, $text, $algorithm, $insensitive)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Exécute une action sur un document
     */
    private function executeAction(array $action, array $document): array
    {
        $actionType = (int)$action['action_type'];
        
        switch ($actionType) {
            case 1: // Assignment
                return $this->executeAssignment($action, $document);
            case 2: // Removal
                return $this->executeRemoval($action, $document);
            case 3: // Email
                return $this->executeEmail($action, $document);
            case 4: // Webhook
                return $this->executeWebhook($action, $document);
            default:
                throw new \Exception("Unknown action type: $actionType");
        }
    }
    
    /**
     * Action Assignment
     */
    private function executeAssignment(array $action, array $document): array
    {
        $updates = [];
        $docId = $document['id'];
        
        // Assign title
        if (!empty($action['assign_title'])) {
            $title = $this->replacePlaceholders($action['assign_title'], $document);
            $this->db->prepare("UPDATE documents SET title = ? WHERE id = ?")->execute([$title, $docId]);
            $updates['title'] = $title;
        }
        
        // Assign document type
        if (!empty($action['assign_document_type'])) {
            $this->db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?")
                ->execute([$action['assign_document_type'], $docId]);
            $updates['document_type_id'] = $action['assign_document_type'];
        }
        
        // Assign correspondent
        if (!empty($action['assign_correspondent'])) {
            $this->db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?")
                ->execute([$action['assign_correspondent'], $docId]);
            $updates['correspondent_id'] = $action['assign_correspondent'];
        }
        
        // Assign storage path
        if (!empty($action['assign_storage_path'])) {
            $this->db->prepare("UPDATE documents SET storage_path_id = ? WHERE id = ?")
                ->execute([$action['assign_storage_path'], $docId]);
            $updates['storage_path_id'] = $action['assign_storage_path'];
        }
        
        // Assign tags
        if (!empty($action['assign_tags'])) {
            $tags = json_decode($action['assign_tags'], true) ?: [];
            foreach ($tags as $tagId) {
                // Utiliser INSERT IGNORE pour éviter les doublons
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                    ->execute([$docId, $tagId]);
            }
            $updates['tags_added'] = $tags;
        }
        
        // Assign owner
        if (!empty($action['assign_owner'])) {
            $this->db->prepare("UPDATE documents SET owner_id = ? WHERE id = ?")
                ->execute([$action['assign_owner'], $docId]);
            $updates['owner_id'] = $action['assign_owner'];
        }
        
        // Assign permissions (view)
        if (!empty($action['assign_view_users'])) {
            $users = json_decode($action['assign_view_users'], true) ?: [];
            // TODO: Implémenter la gestion des permissions si la table existe
            $updates['view_users'] = $users;
        }
        
        if (!empty($action['assign_view_groups'])) {
            $groups = json_decode($action['assign_view_groups'], true) ?: [];
            // TODO: Implémenter la gestion des permissions si la table existe
            $updates['view_groups'] = $groups;
        }
        
        // Assign permissions (change)
        if (!empty($action['assign_change_users'])) {
            $users = json_decode($action['assign_change_users'], true) ?: [];
            // TODO: Implémenter la gestion des permissions si la table existe
            $updates['change_users'] = $users;
        }
        
        if (!empty($action['assign_change_groups'])) {
            $groups = json_decode($action['assign_change_groups'], true) ?: [];
            // TODO: Implémenter la gestion des permissions si la table existe
            $updates['change_groups'] = $groups;
        }
        
        // Assign custom fields
        if (!empty($action['assign_custom_fields_values'])) {
            $values = json_decode($action['assign_custom_fields_values'], true) ?: [];
            foreach ($values as $fieldId => $value) {
                // Upsert custom field value
                try {
                    $this->db->prepare("
                        INSERT INTO document_custom_fields (document_id, custom_field_id, value)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE value = VALUES(value)
                    ")->execute([$docId, $fieldId, $value]);
                } catch (\Exception $e) {
                    // Table peut ne pas exister
                    error_log("Erreur assign custom field: " . $e->getMessage());
                }
            }
            $updates['custom_fields'] = $values;
        }
        
        return $updates;
    }
    
    /**
     * Action Removal
     */
    private function executeRemoval(array $action, array $document): array
    {
        $removed = [];
        $docId = $document['id'];
        
        // Remove all tags
        if (!empty($action['remove_all_tags'])) {
            $this->db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$docId]);
            $removed['all_tags'] = true;
        } elseif (!empty($action['remove_tags'])) {
            $tags = json_decode($action['remove_tags'], true) ?: [];
            if (!empty($tags)) {
                $placeholders = implode(',', array_fill(0, count($tags), '?'));
                $this->db->prepare("DELETE FROM document_tags WHERE document_id = ? AND tag_id IN ($placeholders)")
                    ->execute(array_merge([$docId], $tags));
                $removed['tags'] = $tags;
            }
        }
        
        // Remove correspondent
        if (!empty($action['remove_all_correspondents'])) {
            $this->db->prepare("UPDATE documents SET correspondent_id = NULL WHERE id = ?")->execute([$docId]);
            $removed['correspondent'] = true;
        } elseif (!empty($action['remove_correspondents'])) {
            $correspondents = json_decode($action['remove_correspondents'], true) ?: [];
            if (in_array($document['correspondent_id'], $correspondents)) {
                $this->db->prepare("UPDATE documents SET correspondent_id = NULL WHERE id = ?")->execute([$docId]);
                $removed['correspondent'] = true;
            }
        }
        
        // Remove document type
        if (!empty($action['remove_all_document_types'])) {
            $this->db->prepare("UPDATE documents SET document_type_id = NULL WHERE id = ?")->execute([$docId]);
            $removed['document_type'] = true;
        } elseif (!empty($action['remove_document_types'])) {
            $types = json_decode($action['remove_document_types'], true) ?: [];
            if (in_array($document['document_type_id'], $types)) {
                $this->db->prepare("UPDATE documents SET document_type_id = NULL WHERE id = ?")->execute([$docId]);
                $removed['document_type'] = true;
            }
        }
        
        // Remove storage path
        if (!empty($action['remove_all_storage_paths'])) {
            $this->db->prepare("UPDATE documents SET storage_path_id = NULL WHERE id = ?")->execute([$docId]);
            $removed['storage_path'] = true;
        } elseif (!empty($action['remove_storage_paths'])) {
            $paths = json_decode($action['remove_storage_paths'], true) ?: [];
            if (in_array($document['storage_path_id'], $paths)) {
                $this->db->prepare("UPDATE documents SET storage_path_id = NULL WHERE id = ?")->execute([$docId]);
                $removed['storage_path'] = true;
            }
        }
        
        // Remove custom fields
        if (!empty($action['remove_all_custom_fields'])) {
            try {
                $this->db->prepare("DELETE FROM document_custom_fields WHERE document_id = ?")->execute([$docId]);
                $removed['all_custom_fields'] = true;
            } catch (\Exception $e) {
                error_log("Erreur remove all custom fields: " . $e->getMessage());
            }
        } elseif (!empty($action['remove_custom_fields'])) {
            $fields = json_decode($action['remove_custom_fields'], true) ?: [];
            if (!empty($fields)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($fields), '?'));
                    $this->db->prepare("DELETE FROM document_custom_fields WHERE document_id = ? AND custom_field_id IN ($placeholders)")
                        ->execute(array_merge([$docId], $fields));
                    $removed['custom_fields'] = $fields;
                } catch (\Exception $e) {
                    error_log("Erreur remove custom fields: " . $e->getMessage());
                }
            }
        }
        
        // Remove owner
        if (!empty($action['remove_all_owners'])) {
            $this->db->prepare("UPDATE documents SET owner_id = NULL WHERE id = ?")->execute([$docId]);
            $removed['owner'] = true;
        } elseif (!empty($action['remove_owners'])) {
            $owners = json_decode($action['remove_owners'], true) ?: [];
            if (in_array($document['owner_id'], $owners)) {
                $this->db->prepare("UPDATE documents SET owner_id = NULL WHERE id = ?")->execute([$docId]);
                $removed['owner'] = true;
            }
        }
        
        // Remove permissions
        if (!empty($action['remove_all_permissions'])) {
            // TODO: Implémenter si la table de permissions existe
            $removed['all_permissions'] = true;
        }
        
        return $removed;
    }
    
    /**
     * Action Email
     */
    private function executeEmail(array $action, array $document): array
    {
        $to = $action['email_to'] ?? null;
        if (!$to) {
            throw new \Exception('Email recipient not specified');
        }
        
        $subject = $this->replacePlaceholders($action['email_subject'] ?? 'Document notification', $document);
        $body = $this->replacePlaceholders($action['email_body'] ?? '', $document);
        
        // Utiliser le MailService existant ou PHPMailer directement
        $sent = false;
        try {
            if (class_exists('\KDocs\Services\MailService')) {
                $mailService = new \KDocs\Services\MailService();
                $attachment = null;
                if (!empty($action['email_include_document'])) {
                    $filePath = $document['file_path'] ?? null;
                    if ($filePath && file_exists($filePath)) {
                        $attachment = $filePath;
                    }
                }
                $sent = $mailService->send($to, $subject, $body, $attachment);
            } else {
                // Fallback: utiliser mail() PHP
                $headers = "From: K-Docs <noreply@kdocs.local>\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $sent = mail($to, $subject, $body, $headers);
            }
        } catch (\Exception $e) {
            throw new \Exception("Email sending failed: " . $e->getMessage());
        }
        
        return [
            'sent' => $sent,
            'to' => $to,
            'subject' => $subject
        ];
    }
    
    /**
     * Action Webhook
     */
    private function executeWebhook(array $action, array $document): array
    {
        $url = $action['webhook_url'] ?? null;
        if (!$url) {
            throw new \Exception('Webhook URL not specified');
        }
        
        // Préparer les données
        $data = [
            'document_id' => $document['id'],
            'title' => $document['title'] ?? null,
            'correspondent' => $document['correspondent_name'] ?? null,
            'document_type' => $document['document_type_name'] ?? null,
            'created_at' => $document['created_at'] ?? null,
            'original_filename' => $document['original_filename'] ?? null,
        ];
        
        // Ajouter les params custom
        if (!empty($action['webhook_params'])) {
            $params = json_decode($action['webhook_params'], true) ?: [];
            foreach ($params as $key => $value) {
                $data[$key] = $this->replacePlaceholders($value, $document);
            }
        }
        
        // Custom body
        if (!empty($action['webhook_body'])) {
            $body = $this->replacePlaceholders($action['webhook_body'], $document);
            // Essayer de parser comme JSON, sinon utiliser comme texte
            $parsed = json_decode($body, true);
            if ($parsed !== null) {
                $data = array_merge($data, $parsed);
            } else {
                $data['body'] = $body;
            }
        }
        
        // Headers
        $headers = ['Content-Type: application/json'];
        if (!empty($action['webhook_headers'])) {
            $customHeaders = json_decode($action['webhook_headers'], true) ?: [];
            foreach ($customHeaders as $key => $value) {
                $headers[] = "$key: $value";
            }
        }
        
        // Méthode et format
        $asJson = $action['webhook_as_json'] ?? true;
        $useParams = $action['webhook_use_params'] ?? false;
        
        if ($useParams) {
            $url .= '?' . http_build_query($data);
            $postData = null;
        } else {
            $postData = $asJson ? json_encode($data) : http_build_query($data);
            if (!$asJson) {
                $headers = ['Content-Type: application/x-www-form-urlencoded'];
            }
        }
        
        // Exécuter la requête
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        
        // Inclure le document si demandé
        if (!empty($action['webhook_include_document'])) {
            $filePath = $document['file_path'] ?? null;
            if ($filePath && file_exists($filePath)) {
                $file = new \CURLFile($filePath);
                $data['document'] = $file;
                $postData = $data;
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Webhook failed: $error");
        }
        
        if ($httpCode >= 400) {
            throw new \Exception("Webhook returned error code: $httpCode");
        }
        
        return [
            'url' => $url,
            'http_code' => $httpCode,
            'response' => substr($response, 0, 500)
        ];
    }
    
    /**
     * Remplace les placeholders dans un template
     */
    private function replacePlaceholders(string $template, array $document): string
    {
        // Enrichir le document avec des données calculées
        $data = $document;
        
        if (!empty($document['created_at'])) {
            $created = new \DateTime($document['created_at']);
            $data['created_year'] = $created->format('Y');
            $data['created_month'] = $created->format('m');
            $data['created_day'] = $created->format('d');
        }
        
        if (!empty($document['added_at'])) {
            $added = new \DateTime($document['added_at']);
            $data['added_year'] = $added->format('Y');
            $data['added_month'] = $added->format('m');
            $data['added_day'] = $added->format('d');
        }
        
        // Récupérer les noms liés si manquants
        if (!empty($document['correspondent_id']) && empty($document['correspondent_name'])) {
            $stmt = $this->db->prepare("SELECT name FROM correspondents WHERE id = ?");
            $stmt->execute([$document['correspondent_id']]);
            $data['correspondent_name'] = $stmt->fetchColumn() ?: '';
        }
        
        if (!empty($document['document_type_id']) && empty($document['document_type_name'])) {
            $stmt = $this->db->prepare("SELECT label FROM document_types WHERE id = ?");
            $stmt->execute([$document['document_type_id']]);
            $data['document_type_name'] = $stmt->fetchColumn() ?: '';
        }
        
        // Remplacer les placeholders
        foreach ($this->placeholders as $placeholder => $field) {
            $value = $data[$field] ?? '';
            $template = str_replace($placeholder, $value, $template);
        }
        
        return $template;
    }
    
    /**
     * Matching selon l'algorithme
     */
    private function matchesText(string $content, string $text, string $algorithm, bool $insensitive): bool
    {
        if ($insensitive) {
            $content = mb_strtolower($content);
            $text = mb_strtolower($text);
        }
        
        switch ($algorithm) {
            case 'exact':
                return strpos($content, $text) !== false;
            case 'all':
                $words = preg_split('/\s+/', trim($text));
                foreach ($words as $word) {
                    if (strpos($content, $word) === false) {
                        return false;
                    }
                }
                return true;
            case 'any':
                $words = preg_split('/\s+/', trim($text));
                foreach ($words as $word) {
                    if (strpos($content, $word) !== false) {
                        return true;
                    }
                }
                return false;
            case 'regex':
                $flags = $insensitive ? 'iu' : 'u';
                return preg_match('/' . $text . '/' . $flags, $content) === 1;
            case 'fuzzy':
                // Fuzzy matching basique (70% similarité)
                similar_text($content, $text, $percent);
                return $percent >= 70;
            default:
                return strpos($content, $text) !== false;
        }
    }
    
    private function matchesPattern(string $text, string $pattern): bool
    {
        // Convertir le pattern glob en regex
        $regex = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
        return preg_match('/^' . $regex . '$/i', $text) === 1;
    }
    
    private function getDocumentTagIds(int $documentId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT tag_id FROM document_tags WHERE document_id = ?");
            $stmt->execute([$documentId]);
            return array_column($stmt->fetchAll(), 'tag_id');
        } catch (\Exception $e) {
            return [];
        }
    }
    
    private function getWorkflowsForEvent(string $event): array
    {
        // Mapping événement -> trigger_type
        $triggerTypes = [
            'consumption_started' => ['consumption'],
            'document_added' => ['document_added'],
            'document_updated' => ['document_updated'],
        ];
        
        $types = $triggerTypes[$event] ?? [];
        if (empty($types)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        
        $sql = "
            SELECT DISTINCT w.* FROM workflows w
            INNER JOIN workflow_triggers t ON w.id = t.workflow_id
            WHERE w.enabled = 1 AND t.trigger_type IN ($placeholders)
            ORDER BY w.order_index, w.name
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($types);
        return $stmt->fetchAll();
    }
    
    /**
     * Méthodes statiques pour compatibilité avec l'ancien code
     */
    public static function executeOnDocumentAdded(int $documentId): void
    {
        $service = new self();
        $service->executeForEvent('document_added', $documentId);
    }
    
    public static function executeOnDocumentModified(int $documentId): void
    {
        $service = new self();
        $service->executeForEvent('document_updated', $documentId);
    }
}
