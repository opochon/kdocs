<?php
/**
 * K-Docs - WorkflowManager
 * Gestion CRUD complète des workflows
 */

namespace KDocs\Workflow;

use KDocs\Models\WorkflowDefinition;
use KDocs\Models\WorkflowNode;
use KDocs\Models\WorkflowConnection;

class WorkflowManager
{
    /**
     * Crée un nouveau workflow avec ses nodes et connections
     */
    public static function createWorkflow(array $data): int
    {
        $workflowId = WorkflowDefinition::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'enabled' => $data['enabled'] ?? true,
            'canvas_data' => $data['canvas_data'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
        
        // Créer les nodes
        if (isset($data['nodes']) && is_array($data['nodes'])) {
            foreach ($data['nodes'] as $nodeData) {
                $nodeData['workflow_id'] = $workflowId;
                WorkflowNode::create($nodeData);
            }
        }
        
        // Créer les connections
        if (isset($data['connections']) && is_array($data['connections'])) {
            foreach ($data['connections'] as $connData) {
                $connData['workflow_id'] = $workflowId;
                WorkflowConnection::create($connData);
            }
        }
        
        return $workflowId;
    }
    
    /**
     * Met à jour un workflow complet (définition + nodes + connections)
     */
    public static function updateWorkflow(int $workflowId, array $data): bool
    {
        // Mettre à jour la définition
        $definitionData = [];
        if (isset($data['name'])) $definitionData['name'] = $data['name'];
        if (isset($data['description'])) $definitionData['description'] = $data['description'];
        if (isset($data['enabled'])) $definitionData['enabled'] = $data['enabled'];
        if (isset($data['canvas_data'])) $definitionData['canvas_data'] = $data['canvas_data'];
        
        if (!empty($definitionData)) {
            WorkflowDefinition::update($workflowId, $definitionData);
        }
        
        // Supprimer les anciens nodes et connections
        $existingNodes = WorkflowNode::findByWorkflow($workflowId);
        foreach ($existingNodes as $node) {
            WorkflowNode::delete($node['id']);
        }
        WorkflowConnection::deleteByWorkflow($workflowId);
        
        // Créer les nouveaux nodes
        if (isset($data['nodes']) && is_array($data['nodes'])) {
            foreach ($data['nodes'] as $nodeData) {
                $nodeData['workflow_id'] = $workflowId;
                WorkflowNode::create($nodeData);
            }
        }
        
        // Créer les nouvelles connections
        if (isset($data['connections']) && is_array($data['connections'])) {
            foreach ($data['connections'] as $connData) {
                $connData['workflow_id'] = $workflowId;
                WorkflowConnection::create($connData);
            }
        }
        
        return true;
    }
    
    /**
     * Récupère un workflow complet avec ses nodes et connections
     */
    public static function getWorkflow(int $workflowId): ?array
    {
        $workflow = WorkflowDefinition::findById($workflowId);
        if (!$workflow) {
            return null;
        }
        
        // Décoder canvas_data si présent
        if (isset($workflow['canvas_data'])) {
            $workflow['canvas_data'] = json_decode($workflow['canvas_data'], true) ?: null;
        }
        
        $workflow['nodes'] = WorkflowNode::findByWorkflow($workflowId);
        $workflow['connections'] = WorkflowConnection::findByWorkflow($workflowId);
        
        return $workflow;
    }
    
    /**
     * Liste tous les workflows
     */
    public static function listWorkflows(bool $enabledOnly = false): array
    {
        return WorkflowDefinition::findAll($enabledOnly);
    }
    
    /**
     * Supprime un workflow (cascade sur nodes et connections)
     */
    public static function deleteWorkflow(int $workflowId): bool
    {
        return WorkflowDefinition::delete($workflowId);
    }
    
    /**
     * Active/désactive un workflow
     */
    public static function setEnabled(int $workflowId, bool $enabled): bool
    {
        return WorkflowDefinition::update($workflowId, ['enabled' => $enabled ? 1 : 0]);
    }
}
