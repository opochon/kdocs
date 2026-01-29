<?php
/**
 * Logs d'exécution d'une règle d'attribution
 */
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="<?= url('/admin/attribution-rules') ?>" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Logs: <?= htmlspecialchars($rule['name']) ?></h1>
                <p class="text-gray-500 mt-1">Historique des exécutions de cette règle</p>
            </div>
        </div>
        <a href="<?= url('/admin/attribution-rules/' . $rule['id'] . '/edit') ?>"
           class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
            <i class="fas fa-edit mr-2"></i>Modifier
        </a>
    </div>

    <!-- Logs Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <?php if (empty($logs)): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fas fa-history text-4xl mb-4"></i>
                <p>Aucun log d'exécution</p>
            </div>
        <?php else: ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Résultat</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Temps</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Détails</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4">
                                <a href="<?= url('/documents/' . $log['document_id']) ?>" class="text-blue-600 hover:underline">
                                    <?= htmlspecialchars($log['document_title'] ?? 'Document #' . $log['document_id']) ?>
                                </a>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($log['matched']): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <i class="fas fa-check mr-1"></i> Match
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                        <i class="fas fa-times mr-1"></i> Non match
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $log['execution_time_ms'] ?? 0 ?> ms
                            </td>
                            <td class="px-6 py-4">
                                <?php
                                $conditionsEvaluated = json_decode($log['conditions_evaluated'] ?? '[]', true);
                                $actionsApplied = json_decode($log['actions_applied'] ?? '[]', true);
                                ?>
                                <?php if ($log['matched'] && !empty($actionsApplied)): ?>
                                    <span class="text-sm text-gray-600">
                                        <?= count($actionsApplied) ?> action(s) appliquée(s)
                                    </span>
                                <?php elseif (!empty($conditionsEvaluated)): ?>
                                    <button onclick="showDetails(<?= $log['id'] ?>, <?= htmlspecialchars(json_encode($conditionsEvaluated)) ?>)"
                                            class="text-sm text-blue-600 hover:underline">
                                        Voir conditions
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal for details -->
<div id="details-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[80vh] overflow-hidden">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-medium text-gray-800">Détails de l'évaluation</h3>
            <button onclick="closeDetails()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="details-content" class="p-6 overflow-y-auto max-h-[60vh]">
        </div>
    </div>
</div>

<script>
function showDetails(logId, conditions) {
    const content = document.getElementById('details-content');

    let html = '<div class="space-y-3">';

    if (Array.isArray(conditions)) {
        conditions.forEach((group, idx) => {
            html += `<div class="p-3 rounded-lg ${group.matched ? 'bg-green-50' : 'bg-red-50'}">`;
            html += `<div class="font-medium mb-2">Groupe ${idx + 1}: ${group.matched ? '✓ Match' : '✗ Non match'}</div>`;

            if (group.conditions) {
                group.conditions.forEach(cond => {
                    const icon = cond.matched ? '✓' : '✗';
                    const bgClass = cond.matched ? 'bg-green-100' : 'bg-red-100';
                    html += `<div class="p-2 rounded ${bgClass} mb-1 text-sm">
                        ${icon} ${cond.field_type} ${cond.operator} "${JSON.stringify(cond.condition_value)}"
                        <span class="text-gray-500">(valeur: ${JSON.stringify(cond.document_value)})</span>
                    </div>`;
                });
            }

            html += '</div>';
        });
    } else {
        html += '<pre class="text-sm">' + JSON.stringify(conditions, null, 2) + '</pre>';
    }

    html += '</div>';
    content.innerHTML = html;
    document.getElementById('details-modal').classList.remove('hidden');
}

function closeDetails() {
    document.getElementById('details-modal').classList.add('hidden');
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDetails();
});
</script>
