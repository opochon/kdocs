<?php
// Liste des workflows (Phase 3.3)
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Workflows</h1>
        <a href="<?= url('/admin/workflows/new/designer') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + Créer un workflow
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($workflows)): ?>
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-500 mb-4">Aucun workflow créé.</p>
        <a href="<?= url('/admin/workflows/new/designer') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 inline-block">
            Créer le premier workflow
        </a>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nodes</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Créé le</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($workflows as $workflow): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($workflow['name']) ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-500 max-w-md truncate">
                            <?= htmlspecialchars($workflow['description'] ?? '') ?: '<span class="text-gray-400">-</span>' ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($workflow['enabled']): ?>
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Actif</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-500">
                            <?php 
                            $nodes = $workflow['nodes'] ?? [];
                            $nodeCount = count($nodes);
                            if ($nodeCount > 0): 
                                // Compter par type
                                $nodeTypes = [];
                                foreach ($nodes as $node) {
                                    $type = $node['node_type'] ?? 'unknown';
                                    $nodeTypes[$type] = ($nodeTypes[$type] ?? 0) + 1;
                                }
                            ?>
                                <div class="space-y-1">
                                    <div class="text-xs font-medium"><?= $nodeCount ?> node(s)</div>
                                    <div class="flex flex-wrap gap-1">
                                        <?php foreach (array_slice($nodeTypes, 0, 3) as $type => $count): ?>
                                            <span class="inline-block px-2 py-0.5 text-xs bg-blue-100 text-blue-800 rounded">
                                                <?= htmlspecialchars($type) ?> (<?= $count ?>)
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($nodeTypes) > 3): ?>
                                            <span class="text-xs text-gray-400">+<?= count($nodeTypes) - 3 ?> autres</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="text-gray-400">Aucun node</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?= $workflow['created_at'] ? date('d/m/Y', strtotime($workflow['created_at'])) : '-' ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/workflows/' . $workflow['id'] . '/designer') ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                            Ouvrir dans le designer
                        </a>
                        <form method="POST" action="<?= url('/admin/workflows/' . $workflow['id'] . '/delete') ?>" class="inline" 
                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce workflow ?')">
                            <button type="submit" class="text-red-600 hover:text-red-900">
                                Supprimer
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
