<?php
// Liste des workflows (Phase 3.3)
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Workflows</h1>
        <a href="<?= url('/admin/workflows/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
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
        <a href="<?= url('/admin/workflows/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 inline-block">
            Créer le premier workflow
        </a>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Triggers</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($workflows as $workflow): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($workflow['name']) ?></div>
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
                            <?php if (!empty($workflow['triggers'])): ?>
                                <?php foreach ($workflow['triggers'] as $trigger): ?>
                                    <span class="inline-block px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded mr-1 mb-1">
                                        <?= htmlspecialchars($trigger['trigger_type']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-500">
                            <?php if (!empty($workflow['actions'])): ?>
                                <?php foreach ($workflow['actions'] as $action): ?>
                                    <span class="inline-block px-2 py-1 text-xs bg-green-100 text-green-800 rounded mr-1 mb-1">
                                        <?= htmlspecialchars($action['action_type']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/workflows/' . $workflow['id'] . '/edit') ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                            Modifier
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
