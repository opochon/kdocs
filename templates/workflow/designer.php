<?php
/**
 * K-Docs - Workflow Designer Page
 * Interface drag & drop pour créer des workflows
 */
use KDocs\Core\Config;
$base = Config::basePath();
?>

<div class="flex flex-col h-full bg-white">
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
    
    <div class="flex flex-1 overflow-hidden">
        <!-- Sidebar gauche - Toolbox des nodes -->
        <div class="w-64 border-r border-gray-200 overflow-y-auto bg-gray-50">
            <div class="p-4">
                <h2 class="text-sm font-medium text-gray-700 mb-3">Composants</h2>
                
                <!-- Triggers -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">Déclencheurs</h3>
                    <div class="space-y-1">
                        <div class="node-toolbox-item" data-node-type="trigger_scan" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Scan dossier</span>
                            </div>
                        </div>
                        <div class="node-toolbox-item" data-node-type="trigger_upload" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Upload</span>
                            </div>
                        </div>
                        <div class="node-toolbox-item" data-node-type="trigger_manual" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Manuel</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Processing -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">Traitement</h3>
                    <div class="space-y-1">
                        <div class="node-toolbox-item" data-node-type="process_ocr" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <span class="text-sm text-gray-700">OCR</span>
                            </div>
                        </div>
                        <div class="node-toolbox-item" data-node-type="process_ai_extract" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Extraction IA</span>
                            </div>
                        </div>
                        <div class="node-toolbox-item" data-node-type="process_classify" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Classification</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Conditions -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">Conditions</h3>
                    <div class="space-y-1">
                        <div class="node-toolbox-item" data-node-type="condition_category" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Type document</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">Actions</h3>
                    <div class="space-y-1">
                        <div class="node-toolbox-item" data-node-type="action_assign_user" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Assigner utilisateur</span>
                            </div>
                        </div>
                        <div class="node-toolbox-item" data-node-type="action_add_tag" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Ajouter tag</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Waits -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium text-gray-500 uppercase mb-2">Attentes</h3>
                    <div class="space-y-1">
                        <div class="node-toolbox-item" data-node-type="wait_approval" draggable="true">
                            <div class="flex items-center gap-2 p-2 bg-white rounded border border-gray-200 cursor-move hover:bg-gray-50">
                                <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Approbation</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Canvas central - Workflow Designer -->
        <div class="flex-1 relative bg-gray-50">
            <div id="react-flow-container" class="w-full h-full"></div>
        </div>
        
        <!-- Sidebar droite - Configuration du node sélectionné -->
        <div id="config-panel" class="w-80 border-l border-gray-200 overflow-y-auto bg-gray-50 hidden">
            <div class="p-4">
                <h2 class="text-sm font-medium text-gray-700 mb-4">Configuration</h2>
                <div id="config-content">
                    <p class="text-sm text-gray-500">Sélectionnez un node pour le configurer</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= $base ?>/public/js/workflow-designer.js"></script>
<script>
(function() {
    'use strict';
    
    const workflowId = <?= json_encode($workflowId ?? null) ?>;
    const basePath = <?= json_encode($base) ?>;
    
    // Initialiser le designer
    window.workflowDesigner = new WorkflowDesigner('react-flow-container', {
        basePath: basePath,
        workflowId: workflowId,
    });
    
    // Sauvegarder le workflow
    document.getElementById('save-workflow').addEventListener('click', () => {
        window.workflowDesigner.saveWorkflow();
    });
    
    // Tester le workflow
    document.getElementById('test-workflow').addEventListener('click', () => {
        alert('Fonctionnalité de test à implémenter');
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
