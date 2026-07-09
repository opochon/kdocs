<?php
/**
 * Logs d'exécution d'une règle d'attribution
 */
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="<?= url('/admin/attribution-rules') ?>" style="color:var(--dim)">
                <?= icon('arrow-left') ?>
            </a>
            <div>
                <h1 class="text-2xl font-bold" style="color:var(--ink)">Logs: <?= htmlspecialchars($rule['name']) ?></h1>
                <p class="mt-1" style="color:var(--dim)">Historique des exécutions de cette règle</p>
            </div>
        </div>
        <a href="<?= url('/admin/attribution-rules/' . $rule['id'] . '/edit') ?>"
           class="btn btn-primary">
            <?= icon('edit', ['class' => 'mr-2']) ?>Modifier
        </a>
    </div>

    <!-- Logs Table -->
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <?php if (empty($logs)): ?>
            <div class="p-8 text-center" style="color:var(--dim)">
                <?= icon('history', ['class' => 'text-4xl mb-4']) ?>
                <p>Aucun log d'exécution</p>
            </div>
        <?php else: ?>
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Document</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Résultat</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Temps</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Détails</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4">
                                <a href="<?= url('/documents/' . $log['document_id']) ?>" class="hover:underline" style="color:var(--accent)">
                                    <?= htmlspecialchars($log['document_title'] ?? 'Document #' . $log['document_id']) ?>
                                </a>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($log['matched']): ?>
                                    <span class="ds-chip ds-chip--green px-2.5 py-0.5 text-xs font-medium">
                                        <?= icon('check', ['class' => 'mr-1']) ?> Match
                                    </span>
                                <?php else: ?>
                                    <span class="ds-chip ds-chip--neutral px-2.5 py-0.5 text-xs font-medium">
                                        <?= icon('times', ['class' => 'mr-1']) ?> Non match
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= $log['execution_time_ms'] ?? 0 ?> ms
                            </td>
                            <td class="px-6 py-4">
                                <?php
                                $conditionsEvaluated = json_decode($log['conditions_evaluated'] ?? '[]', true);
                                $actionsApplied = json_decode($log['actions_applied'] ?? '[]', true);
                                ?>
                                <?php if ($log['matched'] && !empty($actionsApplied)): ?>
                                    <span class="text-sm" style="color:var(--ink-soft)">
                                        <?= count($actionsApplied) ?> action(s) appliquée(s)
                                    </span>
                                <?php elseif (!empty($conditionsEvaluated)): ?>
                                    <button onclick="showDetails(<?= $log['id'] ?>, <?= htmlspecialchars(json_encode($conditionsEvaluated)) ?>)"
                                            class="text-sm hover:underline" style="color:var(--accent)">
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
    <div class="ds-card rounded-lg shadow-xl max-w-2xl w-full max-h-[80vh] overflow-hidden">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-medium" style="color:var(--ink)">Détails de l'évaluation</h3>
            <button onclick="closeDetails()" style="color:var(--dim)">
                <?= icon('times') ?>
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
            html += `<div class="p-3 rounded-lg" style="${group.matched ? 'background:color-mix(in srgb,var(--green) 12%,transparent)' : 'background:color-mix(in srgb,var(--red) 12%,transparent)'}">`;
            html += `<div class="font-medium mb-2">Groupe ${idx + 1}: ${group.matched ? '✓ Match' : '✗ Non match'}</div>`;

            if (group.conditions) {
                group.conditions.forEach(cond => {
                    const icon = cond.matched ? '✓' : '✗';
                    const bgClass = cond.matched ? 'background:color-mix(in srgb,var(--green) 16%,transparent)' : 'background:color-mix(in srgb,var(--red) 16%,transparent)';
                    html += `<div class="p-2 rounded mb-1 text-sm" style="${bgClass}">
                        ${icon} ${cond.field_type} ${cond.operator} "${JSON.stringify(cond.condition_value)}"
                        <span style="color:var(--dim)">(valeur: ${JSON.stringify(cond.document_value)})</span>
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
