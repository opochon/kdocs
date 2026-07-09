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
    $groups = $db->query("SELECT id, name, code FROM groups ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
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

<div class="flex flex-col h-full" style="background:var(--surface)">
    <!-- Header -->
    <div class="border-b px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-medium" style="color:var(--ink)">Workflow Designer</h1>
                <p class="text-sm mt-1" style="color:var(--dim)">Créez vos workflows d'approbation visuellement - Style Alfresco</p>
            </div>
            <div class="flex items-center gap-2">
                <button id="save-workflow" class="btn btn-primary text-sm">
                    <?= icon('save', ['class' => 'mr-1']) ?> Enregistrer
                </button>
                <button id="test-workflow" class="btn btn-secondary text-sm">
                    <?= icon('play', ['class' => 'mr-1']) ?> Tester
                </button>
                <a href="<?= url('/admin/workflows') ?>" class="btn btn-secondary text-sm">
                    <?= icon('arrow-left', ['class' => 'mr-1']) ?> Retour
                </a>
            </div>
        </div>
    </div>

    <div class="flex flex-1 overflow-hidden" style="height: calc(100vh - 200px); min-height: 500px;">
        <!-- Sidebar gauche - Toolbox des nodes -->
        <div class="w-72 overflow-y-auto" style="background:var(--rail);border-right:1px solid var(--border)">
            <div class="p-4">
                <h2 class="text-sm font-medium mb-3" style="color:var(--ink-soft)"><?= icon('cubes', ['class' => 'mr-1']) ?> Composants</h2>

                <!-- Déclencheurs -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium uppercase mb-2 flex items-center" style="color:var(--dim)">
                        <span class="w-2 h-2 rounded-full mr-2" style="background:var(--dim)"></span> Déclencheurs
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['triggers'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 rounded border cursor-move ds-row-hover transition-colors" style="background:var(--surface);border-color:var(--border)">
                                <div class="w-6 h-6 rounded flex items-center justify-center" style="background:var(--hover)">
                                    <?= icon('bolt', ['class' => 'text-xs', 'style' => 'color:var(--ink-soft)']) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm block truncate" style="color:var(--ink-soft)"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Conditions -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium uppercase mb-2 flex items-center" style="color:var(--dim)">
                        <span class="w-2 h-2 rounded-full mr-2" style="background:var(--dim)"></span> Conditions
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['conditions'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 rounded border cursor-move ds-row-hover transition-colors" style="background:var(--surface);border-color:var(--border)">
                                <div class="w-6 h-6 rounded flex items-center justify-center" style="background:var(--hover)">
                                    <?= icon('code-branch', ['class' => 'text-xs', 'style' => 'color:var(--ink-soft)']) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm block truncate" style="color:var(--ink-soft)"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Traitement -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium uppercase mb-2 flex items-center" style="color:var(--dim)">
                        <span class="w-2 h-2 rounded-full mr-2" style="background:var(--dim)"></span> Traitement
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['processing'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 rounded border cursor-move ds-row-hover transition-colors" style="background:var(--surface);border-color:var(--border)">
                                <div class="w-6 h-6 rounded flex items-center justify-center" style="background:var(--hover)">
                                    <?= icon('cog', ['class' => 'text-xs', 'style' => 'color:var(--ink-soft)']) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm block truncate" style="color:var(--ink-soft)"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium uppercase mb-2 flex items-center" style="color:var(--dim)">
                        <span class="w-2 h-2 rounded-full mr-2" style="background:var(--dim)"></span> Actions
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['actions'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 rounded border cursor-move ds-row-hover transition-colors" style="background:var(--surface);border-color:var(--border)">
                                <div class="w-6 h-6 rounded flex items-center justify-center" style="background:var(--hover)">
                                    <?= icon('play', ['class' => 'text-xs', 'style' => 'color:var(--ink-soft)']) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm block truncate" style="color:var(--ink-soft)"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Attentes & Timers -->
                <div class="mb-4">
                    <h3 class="text-xs font-medium uppercase mb-2 flex items-center" style="color:var(--dim)">
                        <span class="w-2 h-2 rounded-full mr-2" style="background:var(--dim)"></span> Attentes & Timers
                    </h3>
                    <div class="space-y-1">
                        <?php foreach ($nodeCatalog['waits'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 rounded border cursor-move ds-row-hover transition-colors" style="background:var(--surface);border-color:var(--border)">
                                <div class="w-6 h-6 rounded flex items-center justify-center" style="background:var(--hover)">
                                    <?= icon('clock', ['class' => 'text-xs', 'style' => 'color:var(--ink-soft)']) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm block truncate" style="color:var(--ink-soft)"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php foreach ($nodeCatalog['timers'] ?? [] as $node): ?>
                        <div class="node-toolbox-item" data-node-type="<?= $node['type'] ?>" draggable="true">
                            <div class="flex items-center gap-2 p-2 rounded border cursor-move ds-row-hover transition-colors" style="background:var(--surface);border-color:var(--border)">
                                <div class="w-6 h-6 rounded flex items-center justify-center" style="background:var(--hover)">
                                    <?= icon('hourglass-half', ['class' => 'text-xs', 'style' => 'color:var(--ink-soft)']) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm block truncate" style="color:var(--ink-soft)"><?= htmlspecialchars($node['name']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Canvas central - Workflow Designer -->
        <div class="flex-1 relative" style="background:var(--app-bg); min-height: 400px;">
            <div id="react-flow-container" class="w-full h-full" style="min-height: 400px;"></div>

            <!-- Help overlay -->
            <div id="help-overlay" class="absolute bottom-4 left-4 ds-card rounded-lg shadow-lg p-3 text-xs max-w-xs" style="color:var(--ink-soft)">
                <p class="font-medium mb-1" style="color:var(--ink)"><?= icon('info-circle', ['class' => 'mr-1']) ?> Guide rapide</p>
                <ul class="space-y-1">
                    <li>• Glissez les composants depuis la gauche</li>
                    <li>• Connectez les sorties (●) aux entrées</li>
                    <li>• Cliquez sur un node pour le configurer</li>
                    <li>• Double-cliquez sur une connexion pour la supprimer</li>
                </ul>
                <button onclick="this.parentElement.remove()" class="mt-2 hover:underline" style="color:var(--accent)">Masquer</button>
            </div>
        </div>

        <!-- Sidebar droite - Configuration du node sélectionné -->
        <div id="config-panel" class="w-96 overflow-y-auto hidden" style="background:var(--surface);border-left:1px solid var(--border)">
            <div class="p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-medium" style="color:var(--ink-soft)"><?= icon('sliders-h', ['class' => 'mr-1']) ?> Configuration</h2>
                    <button id="close-config" style="color:var(--dim)">
                        <?= icon('times') ?>
                    </button>
                </div>
                <div id="config-content">
                    <p class="text-sm" style="color:var(--dim)">Sélectionnez un node pour le configurer</p>
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
    background: var(--app-bg);
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
