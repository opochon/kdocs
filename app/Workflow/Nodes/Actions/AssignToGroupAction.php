<?php
/**
 * K-Docs - AssignToGroupAction
 * Assigne un document à un groupe d'utilisateurs
 */

namespace KDocs\Workflow\Nodes\Actions;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;
use KDocs\Core\Database;

class AssignToGroupAction extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        if (!$context->documentId) {
            return ExecutionResult::failed('Aucun document associé');
        }
        
        $db = Database::getInstance();
        
        $groupId = $config['group_id'] ?? null;
        $groupCode = $config['group_code'] ?? null;
        
        // Récupérer le groupe par ID ou code
        if ($groupCode && !$groupId) {
            $stmt = $db->prepare("SELECT id, name FROM user_groups WHERE code = ?");
            $stmt->execute([$groupCode]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($group) {
                $groupId = $group['id'];
            }
        } elseif ($groupId) {
            $stmt = $db->prepare("SELECT id, name FROM user_groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $group = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        
        if (!$groupId || !isset($group)) {
            return ExecutionResult::failed('Groupe non trouvé');
        }
        
        // Mettre à jour le document avec le groupe assigné
        // On crée/met à jour une entrée dans document_assignments
        try {
            // Vérifier si la table existe, sinon la créer
            $db->exec("
                CREATE TABLE IF NOT EXISTS document_assignments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    document_id INT NOT NULL,
                    assigned_user_id INT NULL,
                    assigned_group_id INT NULL,
                    assigned_by INT NULL,
                    assignment_type ENUM('owner', 'reviewer', 'approver', 'processor') DEFAULT 'processor',
                    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
                    note TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
                    INDEX idx_document (document_id),
                    INDEX idx_group (assigned_group_id),
                    INDEX idx_user (assigned_user_id)
                )
            ");
            
            $assignmentType = $config['assignment_type'] ?? 'processor';
            $note = $config['note'] ?? null;
            
            // Supprimer les anciennes assignations du même type si configuré
            if ($config['replace_existing'] ?? true) {
                $stmt = $db->prepare("DELETE FROM document_assignments WHERE document_id = ? AND assignment_type = ?");
                $stmt->execute([$context->documentId, $assignmentType]);
            }
            
            // Créer la nouvelle assignation
            $stmt = $db->prepare("
                INSERT INTO document_assignments 
                (document_id, assigned_group_id, assignment_type, note)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $context->documentId,
                $groupId,
                $assignmentType,
                $note
            ]);
            
            // Créer des notifications pour tous les membres du groupe si configuré
            if ($config['notify_members'] ?? false) {
                $this->notifyGroupMembers($db, $groupId, $context->documentId, $group['name']);
            }
            
            return ExecutionResult::success([
                'group_id' => $groupId,
                'group_name' => $group['name'],
                'assignment_type' => $assignmentType
            ]);
            
        } catch (\Exception $e) {
            return ExecutionResult::failed('Erreur assignation: ' . $e->getMessage());
        }
    }
    
    private function notifyGroupMembers(\PDO $db, int $groupId, int $documentId, string $groupName): void
    {
        // Récupérer le titre du document
        $stmt = $db->prepare("SELECT title, original_filename FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        $title = $doc['title'] ?? $doc['original_filename'] ?? 'Document';
        
        // Récupérer les membres du groupe
        $stmt = $db->prepare("
            SELECT u.id FROM users u
            INNER JOIN user_group_memberships ugm ON u.id = ugm.user_id
            WHERE ugm.group_id = ? AND u.is_active = 1
        ");
        $stmt->execute([$groupId]);
        $members = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Créer une notification pour chaque membre
        foreach ($members as $userId) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO workflow_notifications 
                    (user_id, document_id, type, title, message, link)
                    VALUES (?, ?, 'info', ?, ?, ?)
                ");
                $stmt->execute([
                    $userId,
                    $documentId,
                    "Document assigné au groupe $groupName",
                    "Le document \"$title\" a été assigné à votre groupe.",
                    "/kdocs/documents/$documentId"
                ]);
            } catch (\Exception $e) {
                // Ignorer les erreurs de notification
            }
        }
    }
    
    public function getConfigSchema(): array
    {
        return [
            'group_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'ID du groupe',
            ],
            'group_code' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Code du groupe (ex: ACCOUNTING, SUPERVISORS)',
            ],
            'assignment_type' => [
                'type' => 'string',
                'required' => false,
                'default' => 'processor',
                'description' => 'Type d\'assignation',
                'enum' => ['owner', 'reviewer', 'approver', 'processor']
            ],
            'note' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Note d\'assignation',
            ],
            'replace_existing' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'description' => 'Remplacer les assignations existantes du même type',
            ],
            'notify_members' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Notifier les membres du groupe',
            ],
        ];
    }
}
