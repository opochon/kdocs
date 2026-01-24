<?php
/**
 * K-Docs - Workflow Designer Page
 * Interface drag & drop pour créer des workflows
 * Version 2.0 - Style Alfresco complet
 */
use KDocs\Core\Config;
use KDocs\Workflow\Nodes\NodeExecutorFactory;

$base = Config::basePath();

// Récupérer le catalogue des nodes
$nodeCatalog = NodeExecutorFactory::getNodeTypes();

// Récupérer les utilisateurs et groupes pour les selects
$db = \KDocs\Core\Database::getInstance();
$users = $db->query("SELECT id, username, email, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE is_active = 1 ORDER BY username")->fetchAll(\PDO::FETCH_ASSOC);
$groups = [];
try {
    $groups = $db->query("SELECT id, name, code FROM user_groups ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) {}

// Récupérer les types de documents, tags, correspondants
$documentTypes = $db->query("SELECT id, code, label FROM document_types ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);
$tags = $db->query("SELECT id, name, color FROM tags ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
$correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);

// Champs de classification
$classificationFields = [];
try {
    $classificationFields = $db->query("SELECT id, field_code, field_name FROM classification_fields WHERE is_active = 1 ORDER BY field_name")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Exception $e) {}
?>

<div class="flex flex-col bg-white h-full">
    <!-- Header -->
    <div class="border-b border-gray-200 px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-medium text-gray-900">Workflow Designer</h1>
                <p class="text-sm text-gray-500 mt-1">Créez vos workflows d'approbation visuellement - Style Alfresco</p>
            </div>
            <div class="flex items-center gap-2">
                <button id="save-workflow" class="px-4 py-2 bg-gray-900 text-white text-sm rounded hover:bg-gray-800">
                    <i class="fas fa-save mr-1"></i> Enregistrer
                </button>
                <button id="test-workflow" class="px-4 py-2 bg-blue-100 text-blue-700 text-sm rounded hover:bg-blue-200">
                    <i class="fas fa-play mr-1"></i> Tester
                </button>
                <a href="<?= url('/admin/workflows') ?>" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded hover:bg-gray-200">
                    <i class="fas fa-arrow-left mr-1"></i> Retour
                </a>
            </div>
        </div>
    </div>
    
    <div class="flex flex-1 overflow-hidden" style="height: calc(100vh - 200px); min-height: 500px;">
        <!-- Sidebar gauche - Toolbox des nodes -->
        <div class="w-72 border-r border-gray-200 overflow-y-auto bg-gray-50">
            <div class="p-4">
                <h2 class="text-sm font-medium text-gray-700 mb-3"><i class="fas fa-cubes mr-1"></i> Composants</h2>
                
                <!-- Déclencheurs -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2 flex items-center">
                        <span class="w-2 h-2 bg-blue-500 rounded-full mr-2"></span> Déclencheurs
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['triggers'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-blue-50 hover:border-blue-300 transition-colors">
                                <div class="w-6 h-6 rounded flex items-center justify-center bg-blue-100">
                                    <i class="fas fa-bolt text-xs text-blue-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-700 block truncate"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Conditions -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2 flex items-center">
                        <span class="w-2 h-2 bg-yellow-500 rounded-full mr-2"></span> Conditions
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['conditions'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-yellow-50 hover:border-yellow-300 transition-colors">
                                <div class="w-6 h-6 rounded flex items-center justify-center bg-yellow-100">
                                    <i class="fas fa-code-branch text-xs text-yellow-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-700 block truncate"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Traitement -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2 flex items-center">
                        <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span> Traitement
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['processing'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-green-50 hover:border-green-300 transition-colors">
                                <div class="w-6 h-6 rounded flex items-center justify-center bg-green-100">
                                    <i class="fas fa-cog text-xs text-green-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-700 block truncate"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2 flex items-center">
                        <span class="w-2 h-2 bg-purple-500 rounded-full mr-2"></span> Actions
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['actions'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-purple-50 hover:border-purple-300 transition-colors">
                                <div class="w-6 h-6 rounded flex items-center justify-center bg-purple-100">
                                    <i class="fas fa-play text-xs text-purple-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-700 block truncate"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Attentes & Timers -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2 flex items-center">
                        <span class="w-2 h-2 bg-orange-500 rounded-full mr-2"></span> Attentes & Timers
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['waits'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-orange-50 hover:border-orange-300 transition-colors">
                                <div class="w-6 h-6 rounded flex items-center justify-center bg-orange-100">
                                    <i class="fas fa-clock text-xs text-orange-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-700 block truncate"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php foreach ($nodeCatalog['timers'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-cyan-50 hover:border-cyan-300 transition-colors">
                                <div class="w-6 h-6 rounded flex items-center justify-center bg-cyan-100">
                                    <i class="fas fa-hourglass-half text-xs text-cyan-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-700 block truncate"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Canvas central - Workflow Designer -->
        <div class="flex-1 relative bg-gray-100" style="min-height: 400px;">
            <div id="react-flow-container" class="w-full h-full" style="min-height: 400px;"></div>
            
            <!-- Help overlay -->
            <div id="help-overlay" class="absolute bottom-4 left-4 bg-white rounded-lg shadow-lg p-3 text-xs text-gray-600 max-w-xs">
                <p class="font-medium text-gray-800 mb-1"><i class="fas fa-info-circle mr-1"></i> Guide rapide</p>
                <ul class="space-y-1">
                    <li>• Glissez les composants depuis la gauche</li>
                    <li>• Connectez les sorties (●) aux entrées</li>
                    <li>• Cliquez sur un node pour le configurer</li>
                    <li>• Double-cliquez sur une connexion pour la supprimer</li>
                </ul>
                <button onclick="this.parentElement.remove()" class="mt-2 text-blue-600 hover:underline">Masquer</button>
            </div>
        </div>
        
        <!-- Sidebar droite - Configuration du node sélectionné -->
        <div id="config-panel" class="w-96 border-l border-gray-200 overflow-y-auto bg-white hidden">
            <div class="p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-medium text-gray-700"><i class="fas fa-sliders-h mr-1"></i> Configuration</h2>
                    <button id="close-config" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="config-content">
                    <p class="text-sm text-gray-500">Sélectionnez un node pour le configurer</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Données pour JavaScript -->
<script>
window.KDOCS_WORKFLOW_DATA = {
    workflowId: <?= json_encode($workflowId ?? null) ?>,
    basePath: <?= json_encode($base) ?>,
    users: <?= json_encode($users) ?>,
    groups: <?= json_encode($groups) ?>,
    documentTypes: <?= json_encode($documentTypes) ?>,
    tags: <?= json_encode($tags) ?>,
    correspondents: <?= json_encode($correspondents) ?>,
    classificationFields: <?= json_encode($classificationFields) ?>,
    nodeCatalog: <?= json_encode($nodeCatalog) ?>
};
</script>

<script src="<?= $base ?>/public/js/workflow-designer.js"></script>
<script>
(function() {
    'use strict';
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDesigner);
    } else {
        initDesigner();
    }
    
    function initDesigner() {
        const container = document.getElementById('react-flow-container');
        if (!container) {
            console.error('Container not found');
            return;
        }
        
        window.workflowDesigner = new WorkflowDesigner('react-flow-container', {
            basePath: window.KDOCS_WORKFLOW_DATA.basePath,
            workflowId: window.KDOCS_WORKFLOW_DATA.workflowId,
            data: window.KDOCS_WORKFLOW_DATA
        });
    }
    
    document.getElementById('save-workflow')?.addEventListener('click', () => {
        window.workflowDesigner?.saveWorkflow();
    });
    
    document.getElementById('test-workflow')?.addEventListener('click', () => {
        alert('Sélectionnez un document pour tester ce workflow');
    });
    
    document.getElementById('close-config')?.addEventListener('click', () => {
        document.getElementById('config-panel')?.classList.add('hidden');
    });
})();
</script>

<style>
#workflow-canvas {
    background: #f3f4f6;
    background-image: 
        linear-gradient(rgba(0,0,0,.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,0,0,.03) 1px, transparent 1px);
    background-size: 20px 20px;
}
.workflow-node { cursor: move; }
.workflow-node:hover { filter: brightness(1.05); }
.connection-handle { cursor: crosshair; transition: all 0.15s; }
.connection-handle:hover { r: 8; }
.node-toolbox-item { user-select: none; }
.node-toolbox-item:active { opacity: 0.7; }
</style>
