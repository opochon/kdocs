<?php
/**
 * K-Docs - Workflow Designer Page
 * Interface drag & drop pour créer des workflows
 * Version 2.0 - Style Alfresco avec catalogue dynamique
 */
use KDocs\Core\Config;
use KDocs\Workflow\Nodes\NodeExecutorFactory;

$base = Config::basePath();
$nodeCatalog = NodeExecutorFactory::getNodeTypes();

// Mapping des icônes Heroicons par nom
$iconMap = [
    'document-add' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
    'tag' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>',
    'folder-open' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"></path>',
    'upload' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>',
    'play' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
    'document-text' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
    'currency-dollar' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
    'adjustments' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>',
    'user' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>',
    'document-search' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 21h7a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v11m0 5l4.879-4.879m0 0a3 3 0 104.243-4.242 3 3 0 00-4.243 4.242z"></path>',
    'sparkles' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>',
    'collection' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>',
    'badge-check' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>',
    'user-group' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>',
    'mail' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>',
    'link' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>',
    'clock' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
];

// Labels des catégories en français
$categoryLabels = [
    'triggers' => 'Déclencheurs',
    'conditions' => 'Conditions',
    'processing' => 'Traitement',
    'actions' => 'Actions',
    'waits' => 'Attentes',
    'timers' => 'Temporisateurs',
];

// Fonction helper pour obtenir l'icône
function getNodeIcon($iconName, $iconMap) {
    return $iconMap[$iconName] ?? $iconMap['document-text'];
}
?>

<div class="flex flex-col bg-white h-full">
    <!-- Header -->
    <div class="border-b border-gray-200 px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-medium text-gray-900">Workflow Designer</h1>
                <p class="text-sm text-gray-500 mt-1">Créez et modifiez vos workflows visuellement</p>
            </div>
            <div class="flex items-center gap-2">
                <button id="save-workflow"
                        class="px-4 py-2 bg-gray-900 text-white text-sm rounded hover:bg-gray-800">
                    Enregistrer
                </button>
                <button id="test-workflow"
                        class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded hover:bg-gray-200">
                    Tester
                </button>
            </div>
        </div>
    </div>

    <div class="flex flex-1 overflow-hidden" style="height: calc(100vh - 200px); min-height: 500px;">
        <!-- Sidebar gauche - Toolbox des nodes (généré dynamiquement) -->
        <div class="w-72 border-r border-gray-200 overflow-y-auto bg-gray-50">
            <div class="p-4">
                <h2 class="text-sm font-medium text-gray-700 mb-3">Composants</h2>

                <?php foreach ($nodeCatalog as $category => $nodes): ?>
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">
                        <?= $categoryLabels[$category] ?? ucfirst($category) ?>
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodes as $node): ?>
                        <div class="node-toolbox-item"
                             data-node-type="<?= htmlspecialchars($node['type']) ?>"
                             data-node-outputs='<?= htmlspecialchars(json_encode($node['outputs'])) ?>'
                             data-node-name="<?= htmlspecialchars($node['name']) ?>"
                             data-node-color="<?= htmlspecialchars($node['color']) ?>"
                             draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:border-blue-300 hover:bg-blue-50 transition-colors">
                                <div class="w-7 h-7 rounded flex items-center justify-center flex-shrink-0"
                                     style="background-color: <?= $node['color'] ?>20;">
                                    <svg class="w-4 h-4" fill="none" stroke="<?= $node['color'] ?>" viewBox="0 0 24 24">
                                        <?= getNodeIcon($node['icon'], $iconMap) ?>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-700 block truncate font-medium"><?= htmlspecialchars($node['name']) ?></span>
                                    <span class="text-xs text-gray-400 block truncate"><?= htmlspecialchars($node['description']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Canvas central - Workflow Designer -->
        <div class="flex-1 relative bg-gray-50" style="min-height: 400px;">
            <div id="react-flow-container" class="w-full h-full" style="min-height: 400px;"></div>
        </div>
        
        <!-- Sidebar droite - Configuration du node sélectionné -->
        <div id="config-panel" class="w-96 border-l border-gray-200 overflow-y-auto bg-gray-50 hidden">
            <div class="p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-medium text-gray-700">Configuration</h2>
                    <button id="close-config-panel" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div id="config-content">
                    <p class="text-sm text-gray-500">Sélectionnez un node pour le configurer</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charger les formulaires de configuration et le designer -->
<script src="<?= $base ?>/public/js/node-config-forms.js"></script>
<script src="<?= $base ?>/public/js/workflow-designer.js"></script>
<script>
(function() {
    'use strict';

    const workflowId = <?= json_encode($workflowId ?? null) ?>;
    const basePath = <?= json_encode($base) ?>;

    // Données du catalogue de nodes (généré côté serveur)
    const nodeCatalog = <?= json_encode($nodeCatalog) ?>;

    // Attendre que le DOM soit complètement chargé
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDesigner);
    } else {
        initDesigner();
    }

    function initDesigner() {
        const container = document.getElementById('react-flow-container');
        if (!container) {
            console.error('Container react-flow-container not found');
            return;
        }

        // Initialiser le designer avec le catalogue
        window.workflowDesigner = new WorkflowDesigner('react-flow-container', {
            basePath: basePath,
            workflowId: workflowId,
            nodeCatalog: nodeCatalog,
        });

        // Charger les options pour les selects dynamiques
        window.workflowDesigner.loadOptions();
    }

    // Sauvegarder le workflow
    document.getElementById('save-workflow').addEventListener('click', () => {
        window.workflowDesigner.saveWorkflow();
    });

    // Tester le workflow
    document.getElementById('test-workflow').addEventListener('click', () => {
        alert('Fonctionnalité de test à implémenter');
    });

    // Fermer le panneau de configuration
    document.getElementById('close-config-panel')?.addEventListener('click', () => {
        document.getElementById('config-panel')?.classList.add('hidden');
        if (window.workflowDesigner) {
            window.workflowDesigner.selectedNode = null;
            window.workflowDesigner.renderNodes();
        }
    });
})();
</script>

<style>
#workflow-canvas {
    background: #f9fafb;
    background-image: 
        linear-gradient(rgba(0,0,0,.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,0,0,.05) 1px, transparent 1px);
    background-size: 20px 20px;
}

.workflow-node {
    cursor: move;
}

.workflow-node:hover {
    filter: brightness(1.1);
}

.connection-handle {
    cursor: crosshair;
}

.connection-handle:hover {
    r: 8;
}
</style>
