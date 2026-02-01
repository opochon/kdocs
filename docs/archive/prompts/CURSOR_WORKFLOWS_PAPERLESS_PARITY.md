# K-Docs - Mise à niveau Workflows au niveau Paperless-ngx

## 🎯 OBJECTIF

Atteindre la parité complète avec les workflows Paperless-ngx.
Référence : https://docs.paperless-ngx.com/usage/#workflows

---

## 📋 ÉTAT ACTUEL vs CIBLE

### Triggers - À compléter

**Paperless-ngx supporte :**
1. **Consumption Started** - Avant que le document soit consommé
   - Filtres : sources (consume folder, API upload, mail fetch), filter_path, filter_filename
   
2. **Document Added** - Après ajout d'un document
   - Filtres : filter_has_tags, filter_has_all_tags, filter_has_not_tags
   - filter_has_correspondent, filter_has_not_correspondents  
   - filter_has_document_type, filter_has_not_document_types
   - filter_has_storage_path, filter_has_not_storage_paths
   - match (texte à chercher), matching_algorithm (any/all/exact/regex/fuzzy), is_insensitive
   
3. **Document Updated** - Quand un document est modifié
   - Mêmes filtres que Document Added
   
4. **Scheduled** - Planifié basé sur une date
   - schedule_date_field (created, added, modified, custom_field)
   - schedule_date_custom_field (ID du champ custom si custom_field)
   - schedule_offset_days (peut être négatif pour "X jours avant")
   - schedule_is_recurring, schedule_recurring_interval_days

### Actions - À compléter

**Paperless-ngx supporte :**

1. **Assignment (type=1)** - Assigner des valeurs
   - assign_title (avec placeholders : {correspondent}, {document_type}, {created_year}, etc.)
   - assign_tags (liste d'IDs)
   - assign_document_type (ID)
   - assign_correspondent (ID)
   - assign_storage_path (ID)
   - assign_owner (user ID)
   - assign_view_users, assign_view_groups (permissions lecture)
   - assign_change_users, assign_change_groups (permissions modification)
   - assign_custom_fields (liste d'IDs de champs à assigner)
   - assign_custom_fields_values (JSON avec les valeurs)

2. **Removal (type=2)** - Retirer des valeurs
   - remove_tags (liste d'IDs)
   - remove_all_tags (boolean)
   - remove_correspondents (liste d'IDs)
   - remove_all_correspondents (boolean)
   - remove_document_types (liste d'IDs)
   - remove_all_document_types (boolean)
   - remove_storage_paths (liste d'IDs)
   - remove_all_storage_paths (boolean)
   - remove_custom_fields (liste d'IDs)
   - remove_all_custom_fields (boolean)
   - remove_owners, remove_all_owners
   - remove_view_users, remove_view_groups, remove_change_users, remove_change_groups
   - remove_all_permissions (boolean)

3. **Email (type=3)** - Envoyer un email
   - email_subject (avec placeholders)
   - email_body (avec placeholders)
   - email_to (adresse email)
   - email_include_document (boolean - attacher le PDF)

4. **Webhook (type=4)** - Appeler une URL
   - webhook_url
   - webhook_use_params (boolean - GET params vs body)
   - webhook_as_json (boolean - JSON vs form-data)
   - webhook_params (JSON pour les paramètres)
   - webhook_body (template du body avec placeholders)
   - webhook_headers (JSON pour headers custom)
   - webhook_include_document (boolean - inclure le fichier)

---

## 📁 FICHIERS À MODIFIER

### 1. Migration Base de Données

**Créer** `database/migrations/add_workflow_columns.sql` :
```sql
-- Ajout des colonnes manquantes pour les triggers
ALTER TABLE workflow_triggers 
    ADD COLUMN IF NOT EXISTS sources TEXT DEFAULT NULL COMMENT 'JSON: consume_folder, api_upload, mail_fetch',
    ADD COLUMN IF NOT EXISTS filter_has_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs',
    ADD COLUMN IF NOT EXISTS filter_has_all_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs (ALL must match)',
    ADD COLUMN IF NOT EXISTS filter_has_not_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs to exclude',
    ADD COLUMN IF NOT EXISTS filter_has_correspondent INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_has_not_correspondents TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS filter_has_document_type INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_has_not_document_types TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS filter_has_storage_path INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS filter_has_not_storage_paths TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS match_text VARCHAR(500) DEFAULT NULL COMMENT 'Text to match in content',
    ADD COLUMN IF NOT EXISTS matching_algorithm ENUM('any', 'all', 'exact', 'regex', 'fuzzy') DEFAULT 'any',
    ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS schedule_date_field ENUM('created', 'added', 'modified', 'custom_field') DEFAULT 'created',
    ADD COLUMN IF NOT EXISTS schedule_date_custom_field INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS schedule_recurring_interval_days INT DEFAULT 30;

-- Ajout des colonnes manquantes pour les actions
ALTER TABLE workflow_actions
    ADD COLUMN IF NOT EXISTS assign_title VARCHAR(500) DEFAULT NULL COMMENT 'Title template with placeholders',
    ADD COLUMN IF NOT EXISTS assign_tags TEXT DEFAULT NULL COMMENT 'JSON array of tag IDs',
    ADD COLUMN IF NOT EXISTS assign_document_type INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_correspondent INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_storage_path INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_owner INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assign_view_users TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_view_groups TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_change_users TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_change_groups TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS assign_custom_fields TEXT DEFAULT NULL COMMENT 'JSON array of field IDs',
    ADD COLUMN IF NOT EXISTS assign_custom_fields_values TEXT DEFAULT NULL COMMENT 'JSON object {field_id: value}',
    ADD COLUMN IF NOT EXISTS remove_tags TEXT DEFAULT NULL COMMENT 'JSON array',
    ADD COLUMN IF NOT EXISTS remove_all_tags BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_correspondents TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_correspondents BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_document_types TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_document_types BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_storage_paths TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_storage_paths BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_custom_fields TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_custom_fields BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_owners TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_owners BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS remove_view_users TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_view_groups TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_change_users TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_change_groups TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS remove_all_permissions BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS email_subject VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS email_body TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS email_to VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS email_include_document BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS webhook_url VARCHAR(500) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS webhook_use_params BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS webhook_as_json BOOLEAN DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS webhook_params TEXT DEFAULT NULL COMMENT 'JSON object',
    ADD COLUMN IF NOT EXISTS webhook_body TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS webhook_headers TEXT DEFAULT NULL COMMENT 'JSON object',
    ADD COLUMN IF NOT EXISTS webhook_include_document BOOLEAN DEFAULT FALSE;
```

### 2. Service WorkflowService Complet

**Modifier** `app/Services/WorkflowService.php` :
```php
<?php
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
        
        // Récupérer le document
        $document = Document::find($documentId);
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
            if (!array_intersect($requiredTags, $documentTags)) {
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
            if (array_intersect($excludedTags, $documentTags)) {
                return false;
            }
        }
        
        // Filtre par correspondent
        if (!empty($trigger['filter_has_correspondent'])) {
            if ($document['correspondent_id'] != $trigger['filter_has_correspondent']) {
                return false;
            }
        }
        
        // Filtre par document type
        if (!empty($trigger['filter_has_document_type'])) {
            if ($document['document_type_id'] != $trigger['filter_has_document_type']) {
                return false;
            }
        }
        
        // Filtre par storage path
        if (!empty($trigger['filter_has_storage_path'])) {
            if ($document['storage_path_id'] != $trigger['filter_has_storage_path']) {
                return false;
            }
        }
        
        // Filtre par match text
        if (!empty($trigger['match_text'])) {
            $text = $trigger['match_text'];
            $content = ($document['title'] ?? '') . ' ' . ($document['content'] ?? '');
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
        
        // Assign custom fields
        if (!empty($action['assign_custom_fields_values'])) {
            $values = json_decode($action['assign_custom_fields_values'], true) ?: [];
            foreach ($values as $fieldId => $value) {
                // Upsert custom field value
                $this->db->prepare("
                    INSERT INTO document_custom_fields (document_id, custom_field_id, value)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)
                ")->execute([$docId, $fieldId, $value]);
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
            $placeholders = implode(',', array_fill(0, count($tags), '?'));
            $this->db->prepare("DELETE FROM document_tags WHERE document_id = ? AND tag_id IN ($placeholders)")
                ->execute(array_merge([$docId], $tags));
            $removed['tags'] = $tags;
        }
        
        // Remove correspondent
        if (!empty($action['remove_all_correspondents'])) {
            $this->db->prepare("UPDATE documents SET correspondent_id = NULL WHERE id = ?")->execute([$docId]);
            $removed['correspondent'] = true;
        }
        
        // Remove document type
        if (!empty($action['remove_all_document_types'])) {
            $this->db->prepare("UPDATE documents SET document_type_id = NULL WHERE id = ?")->execute([$docId]);
            $removed['document_type'] = true;
        }
        
        // Remove storage path
        if (!empty($action['remove_all_storage_paths'])) {
            $this->db->prepare("UPDATE documents SET storage_path_id = NULL WHERE id = ?")->execute([$docId]);
            $removed['storage_path'] = true;
        }
        
        // Remove custom fields
        if (!empty($action['remove_all_custom_fields'])) {
            $this->db->prepare("DELETE FROM document_custom_fields WHERE document_id = ?")->execute([$docId]);
            $removed['all_custom_fields'] = true;
        } elseif (!empty($action['remove_custom_fields'])) {
            $fields = json_decode($action['remove_custom_fields'], true) ?: [];
            $placeholders = implode(',', array_fill(0, count($fields), '?'));
            $this->db->prepare("DELETE FROM document_custom_fields WHERE document_id = ? AND custom_field_id IN ($placeholders)")
                ->execute(array_merge([$docId], $fields));
            $removed['custom_fields'] = $fields;
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
        
        // Utiliser le MailService existant
        $mailService = new MailService();
        
        $attachment = null;
        if (!empty($action['email_include_document'])) {
            $filePath = $document['file_path'] ?? null;
            if ($filePath && file_exists($filePath)) {
                $attachment = $filePath;
            }
        }
        
        $sent = $mailService->send($to, $subject, $body, $attachment);
        
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
            $data = json_decode($body, true) ?: ['body' => $body];
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
        
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Webhook failed: $error");
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
        
        // Récupérer les noms liés
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
                $words = preg_split('/\s+/', $text);
                foreach ($words as $word) {
                    if (strpos($content, $word) === false) {
                        return false;
                    }
                }
                return true;
            case 'any':
                $words = preg_split('/\s+/', $text);
                foreach ($words as $word) {
                    if (strpos($content, $word) !== false) {
                        return true;
                    }
                }
                return false;
            case 'regex':
                return preg_match('/' . $text . '/u' . ($insensitive ? 'i' : ''), $content) === 1;
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
        $stmt = $this->db->prepare("SELECT tag_id FROM document_tags WHERE document_id = ?");
        $stmt->execute([$documentId]);
        return array_column($stmt->fetchAll(), 'tag_id');
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
}
```

### 3. Interface Formulaire Améliorée

**Remplacer** `templates/admin/workflow_form.php` avec une interface complète qui inclut :
- Multi-select pour tags/correspondents/types avec Select2 ou Choices.js
- Sections collapsibles pour chaque type d'action
- Preview des placeholders disponibles
- Validation côté client

---

## 🎯 PRIORITÉS

1. **D'abord** : Exécuter la migration SQL
2. **Ensuite** : Mettre à jour WorkflowService.php
3. **Puis** : Améliorer l'interface formulaire
4. **Enfin** : Tester avec des cas réels

---

## 🧪 TESTS À EFFECTUER

1. Créer un workflow "Document Added" avec filtre sur tag
2. Créer un workflow "Assignment" qui ajoute un tag
3. Créer un workflow "Email" avec placeholders
4. Créer un workflow "Webhook" vers RequestBin
5. Tester les matching algorithms (any, all, exact, regex)

---

## 📌 INSTRUCTIONS CURSOR

```
Lis docs/CURSOR_WORKFLOWS_PAPERLESS_PARITY.md et implémente les améliorations.

1. Exécute la migration SQL pour ajouter les colonnes manquantes
2. Remplace app/Services/WorkflowService.php avec le code complet
3. Améliore templates/admin/workflow_form.php avec multi-select et toutes les options
4. Teste que tout fonctionne

Référence : https://docs.paperless-ngx.com/usage/#workflows
```
