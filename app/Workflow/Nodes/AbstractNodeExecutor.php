<?php
/**
 * K-Docs - AbstractNodeExecutor pour Workflow Designer
 * Classe de base pour tous les executors de nodes
 */

namespace KDocs\Workflow\Nodes;

use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;

abstract class AbstractNodeExecutor implements NodeExecutorInterface
{
    /**
     * Valide la configuration par défaut (peut être surchargée)
     */
    public function validateConfig(array $config): array
    {
        $errors = [];
        $schema = $this->getConfigSchema();
        
        foreach ($schema as $field => $rules) {
            $required = $rules['required'] ?? false;
            $value = $config[$field] ?? null;
            
            if ($required && ($value === null || $value === '')) {
                $errors[] = "Le champ '$field' est requis";
            }
            
            if ($value !== null && isset($rules['type'])) {
                $type = $rules['type'];
                if ($type === 'integer' && !is_int($value)) {
                    $errors[] = "Le champ '$field' doit être un entier";
                } elseif ($type === 'string' && !is_string($value)) {
                    $errors[] = "Le champ '$field' doit être une chaîne";
                } elseif ($type === 'array' && !is_array($value)) {
                    $errors[] = "Le champ '$field' doit être un tableau";
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Outputs par défaut (peut être surchargé)
     */
    public function getOutputs(): array
    {
        return ['default'];
    }
    
    /**
     * Schéma par défaut vide (doit être surchargé)
     */
    public function getConfigSchema(): array
    {
        return [];
    }
}
