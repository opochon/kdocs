<?php
/**
 * K-Docs - ScanTrigger
 * Trigger qui surveille un dossier pour de nouveaux fichiers
 */

namespace KDocs\Workflow\Nodes\Triggers;

use KDocs\Workflow\Nodes\AbstractNodeExecutor;
use KDocs\Workflow\ContextBag;
use KDocs\Workflow\ExecutionResult;

class ScanTrigger extends AbstractNodeExecutor
{
    public function execute(ContextBag $context, array $config): ExecutionResult
    {
        // Ce trigger est géré par le worker/cron qui surveille les dossiers
        // Il ne s'exécute pas directement dans le workflow
        return ExecutionResult::success([
            'message' => 'Scan trigger activé',
            'watch_folder' => $config['watch_folder'] ?? null,
        ]);
    }
    
    public function getConfigSchema(): array
    {
        return [
            'watch_folder' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Dossier à surveiller',
            ],
            'file_patterns' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Patterns de fichiers (ex: ["*.pdf", "*.tiff"])',
            ],
            'poll_interval_seconds' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Intervalle de vérification en secondes',
                'default' => 30,
            ],
        ];
    }
}
