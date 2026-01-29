<?php
/**
 * Liste des règles d'attribution
 */
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Règles d'attribution</h1>
            <p class="text-gray-500 mt-1">Définissez des règles automatiques pour classer vos documents</p>
        </div>
        <a href="<?= url('/admin/attribution-rules/create') ?>"
           class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Nouvelle règle
        </a>
    </div>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Total règles</div>
            <div class="text-2xl font-bold text-gray-800"><?= $stats['total'] ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Règles actives</div>
            <div class="text-2xl font-bold text-green-600"><?= $stats['active'] ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Exécutions aujourd'hui</div>
            <div class="text-2xl font-bold text-blue-600"><?= $stats['executions_today'] ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Correspondances</div>
            <div class="text-2xl font-bold text-purple-600"><?= $stats['matches_today'] ?></div>
        </div>
    </div>

    <!-- Liste des règles -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (empty($rules)): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fas fa-layer-group text-4xl mb-4"></i>
                <p>Aucune règle d'attribution configurée</p>
                <a href="<?= url('/admin/attribution-rules/create') ?>" class="text-blue-600 hover:underline mt-2 inline-block">
                    Créer votre première règle
                </a>
            </div>
        <?php else: ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Règle</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priorité</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Conditions</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($rules as $rule): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900"><?= htmlspecialchars($rule['name']) ?></div>
                                <?php if (!empty($rule['description'])): ?>
                                    <div class="text-sm text-gray-500"><?= htmlspecialchars(substr($rule['description'], 0, 100)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    <?= $rule['priority'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm text-gray-600">
                                    <?= count($rule['conditions']) ?> condition(s)
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm text-gray-600">
                                    <?= count($rule['actions']) ?> action(s)
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($rule['is_active']): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i> Active
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        <i class="fas fa-pause-circle mr-1"></i> Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="<?= url('/admin/attribution-rules/' . $rule['id'] . '/edit') ?>"
                                       class="text-blue-600 hover:text-blue-800" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="<?= url('/admin/attribution-rules/' . $rule['id'] . '/logs') ?>"
                                       class="text-gray-600 hover:text-gray-800" title="Logs">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <button onclick="duplicateRule(<?= $rule['id'] ?>)"
                                            class="text-gray-600 hover:text-gray-800" title="Dupliquer">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    <button onclick="deleteRule(<?= $rule['id'] ?>, '<?= htmlspecialchars(addslashes($rule['name'])) ?>')"
                                            class="text-red-600 hover:text-red-800" title="Supprimer">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
async function duplicateRule(id) {
    if (!confirm('Dupliquer cette règle ?')) return;

    try {
        const response = await fetch(`<?= url('/api/attribution-rules') ?>/${id}/duplicate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Erreur lors de la duplication');
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}

async function deleteRule(id, name) {
    if (!confirm(`Supprimer la règle "${name}" ?`)) return;

    try {
        const response = await fetch(`<?= url('/api/attribution-rules') ?>/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Erreur lors de la suppression');
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}
</script>
