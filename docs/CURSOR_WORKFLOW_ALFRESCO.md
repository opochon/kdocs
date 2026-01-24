# K-Docs - Amélioration Workflow Style Alfresco

## 🎯 OBJECTIF

Transformer le workflow K-Docs en un système **style Alfresco simplifié** permettant :
- Workflows d'approbation avec emails et liens d'action
- Gestion des groupes d'utilisateurs avec permissions
- Conditions granulaires sur tags, champs, montants, correspondants
- Branchements conditionnels (accepté/refusé)
- Interface designer intuitive et complète

## 📊 ÉTAT ACTUEL (déjà implémenté)

### ✅ Backend Complet

| Composant | Fichier | Status |
|-----------|---------|--------|
| **NodeExecutorFactory** | `app/Workflow/Nodes/NodeExecutorFactory.php` | ✅ 21 types de nodes |
| **RequestApprovalAction** | `app/Workflow/Nodes/Actions/RequestApprovalAction.php` | ✅ Complet |
| **AssignToGroupAction** | `app/Workflow/Nodes/Actions/AssignToGroupAction.php` | ✅ Complet |
| **SendEmailAction** | `app/Workflow/Nodes/Actions/SendEmailAction.php` | ✅ Complet |
| **FieldCondition** | `app/Workflow/Nodes/Conditions/FieldCondition.php` | ✅ Complet |
| **TagCondition** | `app/Workflow/Nodes/Conditions/TagCondition.php` | ✅ Complet |
| **AmountCondition** | `app/Workflow/Nodes/Conditions/AmountCondition.php` | ✅ Complet |
| **CorrespondentCondition** | `app/Workflow/Nodes/Conditions/CorrespondentCondition.php` | ✅ Complet |
| **ApprovalWait** | `app/Workflow/Nodes/Waits/ApprovalWait.php` | ✅ Complet |
| **DelayTimer** | `app/Workflow/Nodes/Timers/DelayTimer.php` | ✅ Complet |

### ✅ Base de données (migrations existantes)

```
database/migrations/workflow_v2/001_user_groups_complete.sql
```

Tables créées :
- `user_groups` - Groupes avec code, permissions JSON, hiérarchie
- `user_group_memberships` - Membres des groupes (member/manager/admin)
- `group_document_type_permissions` - Permissions par type de document
- `workflow_approval_tokens` - Tokens sécurisés pour liens d'approbation
- `workflow_approval_tasks` - Tâches en attente (pending/completed/escalated)
- `workflow_decision_history` - Historique des décisions
- `workflow_notifications` - Notifications utilisateur

Groupes système créés : `ADMIN`, `SUPERVISORS`, `ACCOUNTING`, `MANAGEMENT`, `USERS`

---

## ❌ CE QUI MANQUE

### 1. Interface Designer Incomplète

Le fichier `templates/workflow/designer.php` ne montre pas tous les nodes disponibles dans le backend.

### 2. Formulaires de Configuration des Nodes

Chaque type de node nécessite un formulaire de configuration dans le panneau latéral du designer.

### 3. Page d'Administration des Groupes

Une page `/admin/groups` pour gérer les groupes et leurs membres.

### 4. Endpoint API d'Approbation

Le contrôleur pour traiter les clics sur les liens d'approbation (`/workflow/approve/{token}`).

### 5. Page des Tâches en Attente

Une page pour voir toutes les approbations en attente pour l'utilisateur connecté.

---

## 🔧 TÂCHES À IMPLÉMENTER

### PHASE 1 : Compléter le Designer UI (Priorité Haute)

#### 1.1 Mettre à jour `templates/workflow/designer.php`

Le toolbox doit afficher TOUS les nodes du catalogue. Modifier la section toolbox pour utiliser dynamiquement `NodeExecutorFactory::getCatalog()`.

```php
<?php
// Au début du fichier
use KDocs\Workflow\Nodes\NodeExecutorFactory;
$nodeCatalog = NodeExecutorFactory::getNodeTypes();
?>

<!-- Toolbox -->
<div class="toolbox">
    <?php foreach ($nodeCatalog as $category => $nodes): ?>
    <div class="category mb-4">
        <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">
            <?= ucfirst($category) ?>
        </h3>
        <div class="space-y-1">
            <?php foreach ($nodes as $node): ?>
            <div class="node-toolbox-item" 
                 data-node-type="<?= $node['type'] ?>"
                 data-node-outputs='<?= json_encode($node['outputs']) ?>'
                 draggable="true">
                <div class="flex items-center gap-2 p-2 bg-white rounded border cursor-move hover:border-blue-300">
                    <div class="w-6 h-6 rounded flex items-center justify-center" 
                         style="background-color: <?= $node['color'] ?>20;">
                        <i class="fas fa-<?= $this->getIconClass($node['icon']) ?>" 
                           style="color: <?= $node['color'] ?>"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-sm text-gray-700 block truncate"><?= $node['name'] ?></span>
                        <span class="text-xs text-gray-400 block truncate"><?= $node['description'] ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
```

#### 1.2 Ajouter les formulaires de configuration par type de node

Dans le panneau de configuration (`#config-panel`), ajouter des formulaires dynamiques selon le type de node sélectionné.

Créer `public/js/node-config-forms.js` :

```javascript
const NodeConfigForms = {
    // Formulaire pour RequestApprovalAction
    'action_request_approval': {
        title: 'Demande d\'approbation',
        fields: [
            {
                name: 'assign_to_group_code',
                type: 'select',
                label: 'Groupe approbateur',
                options: [], // Chargé dynamiquement via API
                required: true
            },
            {
                name: 'assign_to_user_id',
                type: 'select',
                label: 'Ou utilisateur spécifique',
                options: [],
                required: false
            },
            {
                name: 'action_required',
                type: 'select',
                label: 'Action requise',
                options: [
                    { value: 'approve', label: 'Approuver' },
                    { value: 'reject', label: 'Refuser' },
                    { value: 'review', label: 'Réviser' },
                    { value: 'sign', label: 'Signer' }
                ]
            },
            {
                name: 'email_subject',
                type: 'text',
                label: 'Sujet email',
                placeholder: 'Demande d\'approbation: {title}',
                help: 'Variables: {title}, {correspondent}, {amount}, {date}'
            },
            {
                name: 'message',
                type: 'textarea',
                label: 'Message personnalisé',
                rows: 3
            },
            {
                name: 'expires_hours',
                type: 'number',
                label: 'Expire après (heures)',
                default: 72
            },
            {
                name: 'priority',
                type: 'select',
                label: 'Priorité',
                options: [
                    { value: 'low', label: 'Basse' },
                    { value: 'normal', label: 'Normale' },
                    { value: 'high', label: 'Haute' },
                    { value: 'urgent', label: 'Urgente' }
                ]
            },
            {
                name: 'escalate_to_user_id',
                type: 'select',
                label: 'Escalader vers',
                options: [],
                help: 'Utilisateur pour escalade automatique'
            },
            {
                name: 'escalate_after_hours',
                type: 'number',
                label: 'Escalader après (heures)',
                placeholder: '24'
            }
        ]
    },
    
    // Formulaire pour condition_field
    'condition_field': {
        title: 'Condition sur champ',
        fields: [
            {
                name: 'field_type',
                type: 'select',
                label: 'Type de champ',
                options: [
                    { value: 'standard', label: 'Champ standard' },
                    { value: 'classification', label: 'Champ de classification' },
                    { value: 'custom', label: 'Champ personnalisé' }
                ]
            },
            {
                name: 'field_name',
                type: 'select',
                label: 'Champ',
                options: [], // Chargé selon field_type
                dependsOn: 'field_type'
            },
            {
                name: 'operator',
                type: 'select',
                label: 'Opérateur',
                options: [
                    { value: 'equals', label: 'Égal à' },
                    { value: 'not_equals', label: 'Différent de' },
                    { value: 'contains', label: 'Contient' },
                    { value: 'not_contains', label: 'Ne contient pas' },
                    { value: 'starts_with', label: 'Commence par' },
                    { value: 'ends_with', label: 'Termine par' },
                    { value: 'is_empty', label: 'Est vide' },
                    { value: 'is_not_empty', label: 'N\'est pas vide' },
                    { value: 'greater_than', label: 'Supérieur à' },
                    { value: 'less_than', label: 'Inférieur à' },
                    { value: 'between', label: 'Entre' }
                ]
            },
            {
                name: 'value',
                type: 'text',
                label: 'Valeur',
                conditional: { field: 'operator', notIn: ['is_empty', 'is_not_empty'] }
            },
            {
                name: 'value2',
                type: 'text',
                label: 'Valeur max',
                conditional: { field: 'operator', equals: 'between' }
            }
        ]
    },
    
    // Formulaire pour condition_amount
    'condition_amount': {
        title: 'Condition sur montant',
        fields: [
            {
                name: 'operator',
                type: 'select',
                label: 'Opérateur',
                options: [
                    { value: 'equals', label: 'Égal à' },
                    { value: 'greater_than', label: 'Supérieur à' },
                    { value: 'greater_or_equal', label: 'Supérieur ou égal à' },
                    { value: 'less_than', label: 'Inférieur à' },
                    { value: 'less_or_equal', label: 'Inférieur ou égal à' },
                    { value: 'between', label: 'Entre' }
                ]
            },
            {
                name: 'value',
                type: 'number',
                label: 'Montant',
                step: '0.01'
            },
            {
                name: 'value_max',
                type: 'number',
                label: 'Montant max',
                step: '0.01',
                conditional: { field: 'operator', equals: 'between' }
            },
            {
                name: 'currency',
                type: 'select',
                label: 'Devise',
                options: [
                    { value: '', label: 'Toutes devises' },
                    { value: 'CHF', label: 'CHF' },
                    { value: 'EUR', label: 'EUR' },
                    { value: 'USD', label: 'USD' }
                ]
            }
        ]
    },
    
    // Formulaire pour condition_tag
    'condition_tag': {
        title: 'Condition sur tag',
        fields: [
            {
                name: 'mode',
                type: 'select',
                label: 'Mode',
                options: [
                    { value: 'has_any', label: 'A au moins un tag' },
                    { value: 'has_all', label: 'A tous les tags' },
                    { value: 'has_none', label: 'N\'a aucun des tags' }
                ]
            },
            {
                name: 'tag_ids',
                type: 'multiselect',
                label: 'Tags',
                options: [] // Chargé via API
            }
        ]
    },
    
    // Formulaire pour condition_correspondent
    'condition_correspondent': {
        title: 'Condition sur correspondant',
        fields: [
            {
                name: 'mode',
                type: 'select',
                label: 'Mode',
                options: [
                    { value: 'is', label: 'Est' },
                    { value: 'is_not', label: 'N\'est pas' },
                    { value: 'is_any_of', label: 'Est l\'un de' },
                    { value: 'is_none_of', label: 'N\'est aucun de' }
                ]
            },
            {
                name: 'correspondent_ids',
                type: 'multiselect',
                label: 'Correspondants',
                options: [] // Chargé via API
            }
        ]
    },
    
    // Formulaire pour action_send_email
    'action_send_email': {
        title: 'Envoyer email',
        fields: [
            {
                name: 'to_type',
                type: 'select',
                label: 'Destinataire',
                options: [
                    { value: 'user', label: 'Utilisateur spécifique' },
                    { value: 'group', label: 'Groupe' },
                    { value: 'owner', label: 'Propriétaire du document' },
                    { value: 'correspondent', label: 'Correspondant du document' },
                    { value: 'custom', label: 'Email personnalisé' }
                ]
            },
            {
                name: 'to_user_id',
                type: 'select',
                label: 'Utilisateur',
                options: [],
                conditional: { field: 'to_type', equals: 'user' }
            },
            {
                name: 'to_group_code',
                type: 'select',
                label: 'Groupe',
                options: [],
                conditional: { field: 'to_type', equals: 'group' }
            },
            {
                name: 'to_email',
                type: 'text',
                label: 'Email',
                conditional: { field: 'to_type', equals: 'custom' }
            },
            {
                name: 'subject',
                type: 'text',
                label: 'Sujet',
                placeholder: '{title} - Notification'
            },
            {
                name: 'body',
                type: 'textarea',
                label: 'Corps du message',
                rows: 5,
                help: 'Variables: {title}, {correspondent}, {amount}, {date}, {link}'
            },
            {
                name: 'attach_document',
                type: 'checkbox',
                label: 'Joindre le document'
            }
        ]
    },
    
    // Formulaire pour timer_delay
    'timer_delay': {
        title: 'Délai',
        fields: [
            {
                name: 'delay_value',
                type: 'number',
                label: 'Durée',
                min: 1
            },
            {
                name: 'delay_unit',
                type: 'select',
                label: 'Unité',
                options: [
                    { value: 'minutes', label: 'Minutes' },
                    { value: 'hours', label: 'Heures' },
                    { value: 'days', label: 'Jours' },
                    { value: 'weeks', label: 'Semaines' }
                ]
            }
        ]
    },

    // Formulaire pour trigger_document_added
    'trigger_document_added': {
        title: 'Document ajouté',
        fields: [
            {
                name: 'filter_document_type_id',
                type: 'select',
                label: 'Filtrer par type',
                options: [], // Tous les types + option "Tous"
                help: 'Optionnel: déclenche uniquement pour ce type'
            },
            {
                name: 'filter_storage_path_id',
                type: 'select',
                label: 'Filtrer par dossier',
                options: [],
                help: 'Optionnel: déclenche uniquement dans ce dossier'
            }
        ]
    },

    // Formulaire pour trigger_tag_added
    'trigger_tag_added': {
        title: 'Tag ajouté',
        fields: [
            {
                name: 'trigger_tag_ids',
                type: 'multiselect',
                label: 'Tags déclencheurs',
                options: [],
                required: true,
                help: 'Le workflow démarre quand un de ces tags est ajouté'
            }
        ]
    }
};
```

---

### PHASE 2 : Créer l'Endpoint d'Approbation

Créer `app/Controllers/WorkflowApprovalController.php` :

```php
<?php
namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Core\Auth;
use KDocs\Workflow\ExecutionEngine;
use KDocs\Workflow\ContextBag;

class WorkflowApprovalController
{
    /**
     * GET /workflow/approve/{token}
     * Traite le clic sur un lien d'approbation
     */
    public function handleApproval(string $token): void
    {
        $db = Database::getInstance();
        $action = $_GET['action'] ?? null;
        
        // Valider le token
        $stmt = $db->prepare("
            SELECT wat.*, d.title as document_title
            FROM workflow_approval_tokens wat
            JOIN documents d ON wat.document_id = d.id
            WHERE wat.token = ? AND wat.response_action IS NULL
        ");
        $stmt->execute([$token]);
        $approval = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$approval) {
            $this->renderError('Lien invalide ou déjà traité');
            return;
        }
        
        // Vérifier expiration
        if (strtotime($approval['expires_at']) < time()) {
            $this->renderError('Ce lien a expiré');
            return;
        }
        
        // Si pas d'action, afficher la page de choix
        if (!in_array($action, ['approve', 'reject'])) {
            $this->renderApprovalPage($approval, $token);
            return;
        }
        
        // Vérifier si l'utilisateur est connecté et autorisé
        $currentUser = Auth::user();
        $isAuthorized = $this->checkAuthorization($approval, $currentUser);
        
        if (!$isAuthorized) {
            // Demander connexion ou afficher erreur
            if (!$currentUser) {
                $_SESSION['redirect_after_login'] = "/workflow/approve/{$token}?action={$action}";
                header('Location: /login');
                exit;
            }
            $this->renderError('Vous n\'êtes pas autorisé à traiter cette demande');
            return;
        }
        
        // Traiter la décision
        $comment = $_POST['comment'] ?? $_GET['comment'] ?? null;
        $this->processDecision($approval, $action, $currentUser['id'], $comment);
        
        // Rediriger vers page de confirmation
        $this->renderConfirmation($approval, $action);
    }
    
    private function checkAuthorization(array $approval, ?array $user): bool
    {
        if (!$user) return false;
        
        // Admin peut tout approuver
        if (Auth::hasRole('admin')) return true;
        
        // Vérifie si assigné à cet utilisateur
        if ($approval['assigned_user_id'] == $user['id']) return true;
        
        // Vérifie si membre du groupe assigné
        if ($approval['assigned_group_id']) {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT 1 FROM user_group_memberships 
                WHERE user_id = ? AND group_id = ?
            ");
            $stmt->execute([$user['id'], $approval['assigned_group_id']]);
            if ($stmt->fetch()) return true;
        }
        
        return false;
    }
    
    private function processDecision(array $approval, string $action, int $userId, ?string $comment): void
    {
        $db = Database::getInstance();
        $decision = ($action === 'approve') ? 'approved' : 'rejected';
        
        // Mettre à jour le token
        $stmt = $db->prepare("
            UPDATE workflow_approval_tokens 
            SET response_action = ?, response_comment = ?, responded_by = ?, responded_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$decision, $comment, $userId, $approval['id']]);
        
        // Enregistrer dans l'historique
        $stmt = $db->prepare("
            INSERT INTO workflow_decision_history 
            (execution_id, document_id, node_id, token_id, decision, decided_by, comment)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $approval['execution_id'],
            $approval['document_id'],
            $approval['node_id'],
            $approval['id'],
            $decision,
            $userId,
            $comment
        ]);
        
        // Mettre à jour la tâche
        $stmt = $db->prepare("
            UPDATE workflow_approval_tasks 
            SET status = 'completed', completed_at = NOW(), completed_by = ?, decision = ?, comment = ?
            WHERE execution_id = ? AND node_id = ?
        ");
        $stmt->execute([$userId, $decision, $comment, $approval['execution_id'], $approval['node_id']]);
        
        // Reprendre l'exécution du workflow
        $this->resumeWorkflow($approval['execution_id'], $decision);
    }
    
    private function resumeWorkflow(int $executionId, string $decision): void
    {
        $db = Database::getInstance();
        
        // Récupérer l'exécution
        $stmt = $db->prepare("
            SELECT we.*, w.definition 
            FROM workflow_executions we
            JOIN workflows w ON we.workflow_id = w.id
            WHERE we.id = ?
        ");
        $stmt->execute([$executionId]);
        $execution = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$execution || $execution['status'] !== 'waiting') {
            return;
        }
        
        // Mettre à jour le statut
        $stmt = $db->prepare("UPDATE workflow_executions SET status = 'running' WHERE id = ?");
        $stmt->execute([$executionId]);
        
        // Créer le contexte et reprendre
        $context = new ContextBag();
        $context->executionId = $executionId;
        $context->documentId = $execution['document_id'];
        $context->workflowId = $execution['workflow_id'];
        $context->set('approval_decision', $decision);
        
        $engine = new ExecutionEngine();
        $engine->resumeFromWait($executionId, $decision);
    }
    
    private function renderApprovalPage(array $approval, string $token): void
    {
        // Afficher la page HTML avec les boutons d'approbation
        require __DIR__ . '/../../templates/workflow/approval_page.php';
    }
    
    private function renderConfirmation(array $approval, string $action): void
    {
        require __DIR__ . '/../../templates/workflow/approval_confirmation.php';
    }
    
    private function renderError(string $message): void
    {
        require __DIR__ . '/../../templates/workflow/approval_error.php';
    }
}
```

Ajouter la route dans `index.php` :

```php
// Routes workflow
$router->get('/workflow/approve/{token}', [WorkflowApprovalController::class, 'handleApproval']);
$router->post('/workflow/approve/{token}', [WorkflowApprovalController::class, 'handleApproval']);
```

---

### PHASE 3 : Page Administration des Groupes

Créer `app/Controllers/GroupsController.php` :

```php
<?php
namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Core\Auth;

class GroupsController
{
    public function index(): void
    {
        Auth::requireRole('admin');
        
        $db = Database::getInstance();
        $stmt = $db->query("
            SELECT g.*, 
                   COUNT(DISTINCT ugm.user_id) as member_count,
                   pg.name as parent_name
            FROM user_groups g
            LEFT JOIN user_group_memberships ugm ON g.id = ugm.group_id
            LEFT JOIN user_groups pg ON g.parent_group_id = pg.id
            GROUP BY g.id
            ORDER BY g.name
        ");
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        require __DIR__ . '/../../templates/admin/groups/index.php';
    }
    
    public function show(int $id): void
    {
        Auth::requireRole('admin');
        
        $db = Database::getInstance();
        
        // Groupe
        $stmt = $db->prepare("SELECT * FROM user_groups WHERE id = ?");
        $stmt->execute([$id]);
        $group = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$group) {
            header('Location: /admin/groups');
            exit;
        }
        
        // Membres
        $stmt = $db->prepare("
            SELECT u.*, ugm.role_in_group, ugm.joined_at
            FROM users u
            JOIN user_group_memberships ugm ON u.id = ugm.user_id
            WHERE ugm.group_id = ?
            ORDER BY ugm.role_in_group DESC, u.username
        ");
        $stmt->execute([$id]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Utilisateurs disponibles (non membres)
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.email, u.first_name, u.last_name
            FROM users u
            WHERE u.is_active = 1 
            AND u.id NOT IN (SELECT user_id FROM user_group_memberships WHERE group_id = ?)
            ORDER BY u.username
        ");
        $stmt->execute([$id]);
        $availableUsers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Permissions par type de document
        $stmt = $db->prepare("
            SELECT gdtp.*, dt.label as document_type_name
            FROM group_document_type_permissions gdtp
            JOIN document_types dt ON gdtp.document_type_id = dt.id
            WHERE gdtp.group_id = ?
        ");
        $stmt->execute([$id]);
        $permissions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        require __DIR__ . '/../../templates/admin/groups/show.php';
    }
    
    public function create(): void
    {
        Auth::requireRole('admin');
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO user_groups (name, code, description, parent_group_id, permissions)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'],
                strtoupper($_POST['code'] ?? ''),
                $_POST['description'] ?? null,
                $_POST['parent_group_id'] ?: null,
                json_encode($_POST['permissions'] ?? [])
            ]);
            
            header('Location: /admin/groups/' . $db->lastInsertId());
            exit;
        }
        
        // Groupes parents possibles
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name FROM user_groups ORDER BY name");
        $parentGroups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        require __DIR__ . '/../../templates/admin/groups/create.php';
    }
    
    public function addMember(int $groupId): void
    {
        Auth::requireRole('admin');
        
        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO user_group_memberships (user_id, group_id, role_in_group)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE role_in_group = VALUES(role_in_group)
        ");
        $stmt->execute([
            $_POST['user_id'],
            $groupId,
            $_POST['role'] ?? 'member'
        ]);
        
        header("Location: /admin/groups/{$groupId}");
        exit;
    }
    
    public function removeMember(int $groupId, int $userId): void
    {
        Auth::requireRole('admin');
        
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM user_group_memberships WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);
        
        header("Location: /admin/groups/{$groupId}");
        exit;
    }
}
```

---

### PHASE 4 : Page Tâches en Attente

Créer `app/Controllers/TasksController.php` :

```php
<?php
namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Core\Auth;

class TasksController
{
    /**
     * Liste des tâches en attente pour l'utilisateur connecté
     */
    public function myTasks(): void
    {
        $user = Auth::requireLogin();
        $db = Database::getInstance();
        
        // Récupérer les IDs des groupes de l'utilisateur
        $stmt = $db->prepare("SELECT group_id FROM user_group_memberships WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $groupIds = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'group_id');
        
        // Tâches assignées directement ou via groupe
        $sql = "
            SELECT 
                wat.*,
                d.title as document_title,
                d.original_filename,
                dt.label as document_type,
                c.name as correspondent_name,
                d.amount,
                d.currency,
                w.name as workflow_name,
                token.expires_at,
                token.message,
                CASE 
                    WHEN wat.assigned_user_id = ? THEN 'direct'
                    ELSE 'group'
                END as assignment_type
            FROM workflow_approval_tasks wat
            JOIN documents d ON wat.document_id = d.id
            JOIN workflow_executions we ON wat.execution_id = we.id
            JOIN workflows w ON we.workflow_id = w.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN workflow_approval_tokens token ON token.execution_id = wat.execution_id AND token.node_id = wat.node_id
            WHERE wat.status = 'pending'
            AND (
                wat.assigned_user_id = ?
                " . (count($groupIds) > 0 ? "OR wat.assigned_group_id IN (" . implode(',', array_fill(0, count($groupIds), '?')) . ")" : "") . "
            )
            ORDER BY 
                FIELD(wat.priority, 'urgent', 'high', 'normal', 'low'),
                wat.expires_at ASC
        ";
        
        $params = [$user['id'], $user['id']];
        if (count($groupIds) > 0) {
            $params = array_merge($params, $groupIds);
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Stats
        $stats = [
            'total' => count($tasks),
            'urgent' => count(array_filter($tasks, fn($t) => $t['priority'] === 'urgent')),
            'expiring_soon' => count(array_filter($tasks, fn($t) => 
                $t['expires_at'] && strtotime($t['expires_at']) < time() + 86400
            ))
        ];
        
        require __DIR__ . '/../../templates/tasks/my_tasks.php';
    }
}
```

---

### PHASE 5 : APIs pour le Designer

Créer/étendre `app/Controllers/Api/WorkflowApiController.php` :

```php
<?php
namespace KDocs\Controllers\Api;

use KDocs\Core\Database;
use KDocs\Workflow\Nodes\NodeExecutorFactory;

class WorkflowApiController
{
    /**
     * GET /api/workflow/node-catalog
     * Retourne le catalogue complet des nodes pour le designer
     */
    public function getNodeCatalog(): void
    {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => NodeExecutorFactory::getNodeTypes()
        ]);
    }
    
    /**
     * GET /api/workflow/node-config/{type}
     * Retourne le schema de configuration d'un type de node
     */
    public function getNodeConfig(string $type): void
    {
        header('Content-Type: application/json');
        
        $info = NodeExecutorFactory::getNodeInfo($type);
        if (!$info) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Node type not found']);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $info
        ]);
    }
    
    /**
     * GET /api/workflow/options
     * Retourne les options pour les selects (users, groups, tags, etc.)
     */
    public function getOptions(): void
    {
        header('Content-Type: application/json');
        $db = Database::getInstance();
        
        // Utilisateurs
        $stmt = $db->query("SELECT id, username, email, first_name, last_name FROM users WHERE is_active = 1 ORDER BY username");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Groupes
        $stmt = $db->query("SELECT id, name, code, description FROM user_groups ORDER BY name");
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Tags
        $stmt = $db->query("SELECT id, name, color FROM tags ORDER BY name");
        $tags = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Correspondants
        $stmt = $db->query("SELECT id, name FROM correspondents ORDER BY name");
        $correspondents = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Types de documents
        $stmt = $db->query("SELECT id, label, slug FROM document_types ORDER BY label");
        $documentTypes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Champs personnalisés
        $stmt = $db->query("SELECT id, name, label, data_type FROM custom_fields ORDER BY name");
        $customFields = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Champs de classification
        $stmt = $db->query("SELECT id, name, label FROM classification_fields WHERE is_active = 1 ORDER BY name");
        $classificationFields = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Chemins de stockage
        $stmt = $db->query("SELECT id, path, label FROM storage_paths ORDER BY path");
        $storagePaths = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'users' => $users,
                'groups' => $groups,
                'tags' => $tags,
                'correspondents' => $correspondents,
                'document_types' => $documentTypes,
                'custom_fields' => $customFields,
                'classification_fields' => $classificationFields,
                'storage_paths' => $storagePaths
            ]
        ]);
    }
}
```

---

## 📋 ROUTES À AJOUTER

```php
// Dans index.php ou routes.php

// Groupes (admin)
$router->get('/admin/groups', [GroupsController::class, 'index']);
$router->get('/admin/groups/create', [GroupsController::class, 'create']);
$router->post('/admin/groups', [GroupsController::class, 'create']);
$router->get('/admin/groups/{id}', [GroupsController::class, 'show']);
$router->post('/admin/groups/{id}/members', [GroupsController::class, 'addMember']);
$router->delete('/admin/groups/{id}/members/{userId}', [GroupsController::class, 'removeMember']);

// Approbation workflow
$router->get('/workflow/approve/{token}', [WorkflowApprovalController::class, 'handleApproval']);
$router->post('/workflow/approve/{token}', [WorkflowApprovalController::class, 'handleApproval']);

// Tâches utilisateur
$router->get('/tasks', [TasksController::class, 'myTasks']);

// API Workflow
$router->get('/api/workflow/node-catalog', [WorkflowApiController::class, 'getNodeCatalog']);
$router->get('/api/workflow/node-config/{type}', [WorkflowApiController::class, 'getNodeConfig']);
$router->get('/api/workflow/options', [WorkflowApiController::class, 'getOptions']);
```

---

## 📁 TEMPLATES À CRÉER

```
templates/
├── admin/
│   └── groups/
│       ├── index.php          # Liste des groupes
│       ├── create.php         # Formulaire création groupe
│       └── show.php           # Détail groupe + membres
├── tasks/
│   └── my_tasks.php           # Liste tâches en attente
└── workflow/
    ├── approval_page.php      # Page de décision
    ├── approval_confirmation.php  # Confirmation après décision
    └── approval_error.php     # Page d'erreur
```

---

## 🎯 ORDRE D'IMPLÉMENTATION RECOMMANDÉ

1. **Phase 1** : Designer UI complet (toolbox + formulaires config)
2. **Phase 5** : APIs pour charger les options dynamiquement
3. **Phase 3** : Administration des groupes
4. **Phase 2** : Endpoint d'approbation
5. **Phase 4** : Page tâches en attente

---

## 🧪 TEST DU WORKFLOW COMPLET

Une fois implémenté, créer ce workflow de test :

```
[Trigger: Document ajouté]
        ↓
[Condition: Type = Facture]
    ├── TRUE →
    │   [Condition: Montant > 5000]
    │       ├── TRUE → [Request Approval: MANAGEMENT]
    │       │              ├── approved → [Send Email: ACCOUNTING] → [Add Tag: "approuvé"]
    │       │              └── rejected → [Send Email: owner] → [Add Tag: "refusé"]
    │       └── FALSE → [Request Approval: SUPERVISORS]
    │                      ├── approved → [Send Email: ACCOUNTING]
    │                      └── rejected → [Send Email: owner]
    └── FALSE → [End]
```

Ce workflow représente exactement ton cas d'usage : factures avec approbation hiérarchique selon le montant.

---

## 📝 NOTES

- Le backend est déjà à 95% complet
- Les nodes `RequestApprovalAction` et `AssignToGroupAction` existent et fonctionnent
- Les migrations BDD pour les groupes et tokens d'approbation existent
- Il reste principalement du travail **frontend** (designer UI) et quelques **contrôleurs**
