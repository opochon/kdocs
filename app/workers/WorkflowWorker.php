<?php
/**
 * K-Docs - WorkflowWorker
 * Worker pour exécuter les workflows automatiquement
 * À exécuter via cron toutes les minutes
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Config.php';

use KDocs\Core\Database;
use KDocs\Workflow\ExecutionEngine;
use KDocs\Models\WorkflowDefinition;
use KDocs\Models\WorkflowNode;
use KDocs\Models\WorkflowExecution;
use KDocs\Services\FilesystemReader;

class WorkflowWorker
{
    private $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Exécute le worker
     */
    public function run(): void
    {
        echo "=== Workflow Worker ===\n";
        echo date('Y-m-d H:i:s') . "\n\n";
        
        // 1. Vérifier les triggers scan
        $this->checkScanTriggers();
        
        // 2. Continuer les exécutions en cours
        $this->processRunningExecutions();
        
        // 3. Gérer les timeouts
        $this->handleTimeouts();
        
        echo "\n✅ Worker terminé\n";
    }
    
    /**
     * Vérifie les triggers de type "scan"
     */
    private function checkScanTriggers(): void
    {
        echo "1. Vérification des triggers scan...\n";
        
        // Récupérer tous les workflows actifs avec des triggers scan
        $workflows = WorkflowDefinition::findAll(true);
        
        foreach ($workflows as $workflow) {
            $nodes = WorkflowNode::findByWorkflow($workflow['id']);
            
            foreach ($nodes as $node) {
                if ($node['node_type'] === 'trigger_scan') {
                    $config = $node['config'];
                    $watchFolder = $config['watch_folder'] ?? null;
                    
                    if (!$watchFolder) {
                        continue;
                    }
                    
                    // Vérifier les nouveaux fichiers dans le dossier
                    $this->checkFolderForNewFiles($workflow['id'], $watchFolder, $config);
                }
            }
        }
    }
    
    /**
     * Vérifie un dossier pour de nouveaux fichiers
     */
    private function checkFolderForNewFiles(int $workflowId, string $watchFolder, array $config): void
    {
        if (!is_dir($watchFolder)) {
            echo "   ⚠️  Dossier non trouvé: $watchFolder\n";
            return;
        }
        
        $filePatterns = $config['file_patterns'] ?? ['*.pdf', '*.tiff', '*.jpg'];
        $fsReader = new FilesystemReader();
        
        try {
            $content = $fsReader->readDirectory($watchFolder, false);
            
            foreach ($content['files'] as $file) {
                // Vérifier si le fichier correspond aux patterns
                $matches = false;
                foreach ($filePatterns as $pattern) {
                    if (fnmatch($pattern, $file['name'])) {
                        $matches = true;
                        break;
                    }
                }
                
                if (!$matches) {
                    continue;
                }
                
                // Vérifier si une exécution existe déjà pour ce fichier
                $filePath = $watchFolder . DIRECTORY_SEPARATOR . $file['name'];
                $stmt = $this->db->prepare("
                    SELECT id FROM documents 
                    WHERE file_path = ? AND deleted_at IS NULL
                ");
                $stmt->execute([$filePath]);
                $document = $stmt->fetch();
                
                if ($document) {
                    // Vérifier si une exécution existe déjà
                    $stmt = $this->db->prepare("
                        SELECT id FROM workflow_executions 
                        WHERE workflow_id = ? AND document_id = ? 
                        AND status IN ('pending', 'running', 'waiting')
                    ");
                    $stmt->execute([$workflowId, $document['id']]);
                    
                    if ($stmt->fetch()) {
                        continue; // Déjà en cours
                    }
                    
                    // Démarrer le workflow
                    echo "   → Démarrage workflow $workflowId pour document {$document['id']}\n";
                    ExecutionEngine::startWorkflow($workflowId, $document['id']);
                }
            }
        } catch (\Exception $e) {
            echo "   ❌ Erreur vérification dossier $watchFolder: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Continue les exécutions en cours
     */
    private function processRunningExecutions(): void
    {
        echo "2. Traitement des exécutions en cours...\n";
        
        $executions = WorkflowExecution::findByStatus('running');
        $count = 0;
        
        foreach ($executions as $execution) {
            try {
                ExecutionEngine::step($execution['id']);
                $count++;
            } catch (\Exception $e) {
                echo "   ❌ Erreur exécution {$execution['id']}: " . $e->getMessage() . "\n";
            }
        }
        
        echo "   → $count exécutions traitées\n";
    }
    
    /**
     * Gère les timeouts et escalades
     */
    private function handleTimeouts(): void
    {
        echo "3. Gestion des timeouts...\n";
        
        // Vérifier les approbations expirées
        $stmt = $this->db->query("
            SELECT * FROM workflow_approval_tasks 
            WHERE status = 'pending' 
            AND expires_at IS NOT NULL 
            AND expires_at < NOW()
        ");
        $expiredTasks = $stmt->fetchAll();
        
        foreach ($expiredTasks as $task) {
            // Escalader ou marquer comme expiré
            if ($task['escalate_to_user_id']) {
                echo "   → Escalade tâche {$task['id']}\n";
                $this->db->prepare("
                    UPDATE workflow_approval_tasks 
                    SET assigned_user_id = ?, expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR)
                    WHERE id = ?
                ")->execute([
                    $task['escalate_to_user_id'],
                    $task['escalate_after_hours'],
                    $task['id'],
                ]);
            } else {
                echo "   → Timeout tâche {$task['id']}\n";
                $this->db->prepare("
                    UPDATE workflow_approval_tasks 
                    SET status = 'expired' 
                    WHERE id = ?
                ")->execute([$task['id']]);
                
                // Reprendre l'exécution avec output "timeout"
                ExecutionEngine::resume($task['execution_id'], 'timeout');
            }
        }
        
        echo "   → " . count($expiredTasks) . " timeouts gérés\n";
    }
}

// Exécuter le worker si appelé directement
if (php_sapi_name() === 'cli') {
    $worker = new WorkflowWorker();
    $worker->run();
}
