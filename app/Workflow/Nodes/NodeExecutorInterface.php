<?php
/**
 * K-Docs - NodeExecutorInterface pour Workflow Designer
 * Interface pour tous les executors de nodes
 */

namespace KDocs\Workflow\Nodes;

use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;

interface NodeExecutorInterface
{
    /**
     * Exécute le node avec le contexte donné
     */
    public function execute(ContextBag $context, array $config): ExecutionResult;
    
    /**
     * Valide la configuration du node
     * Retourne un tableau d'erreurs (vide si valide)
     */
    public function validateConfig(array $config): array;
    
    /**
     * Retourne les outputs possibles du node
     * Ex: ['default', 'true', 'false'] pour une condition
     */
    public function getOutputs(): array;
    
    /**
     * Retourne le schéma de configuration attendu
     * Pour validation et génération d'UI
     */
    public function getConfigSchema(): array;
}
