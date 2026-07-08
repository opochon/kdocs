<?php
// Logs d'un webhook
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">
            Logs : <?= htmlspecialchars($webhook['name']) ?>
        </h1>
        <a href="<?= url('/admin/webhooks') ?>" class="btn btn-secondary">
            ← Retour
        </a>
    </div>

    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Événement</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Code HTTP</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Temps</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Erreur</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center" style="color:var(--dim)">
                        Aucun log pour ce webhook.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                        <?= date('d/m/Y H:i:s', strtotime($log['executed_at'])) ?>
                    </td>
                    <td class="px-6 py-4">
                        <code class="ds-chip ds-chip--neutral text-xs px-2 py-1">
                            <?= htmlspecialchars($log['event']) ?>
                        </code>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($log['response_code'] >= 200 && $log['response_code'] < 300): ?>
                        <span class="ds-chip ds-chip--green px-2 py-1 text-xs">
                            <?= $log['response_code'] ?>
                        </span>
                        <?php elseif ($log['response_code'] >= 400): ?>
                        <span class="ds-chip ds-chip--red px-2 py-1 text-xs">
                            <?= $log['response_code'] ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                        <?= $log['execution_time_ms'] ? number_format($log['execution_time_ms']) . ' ms' : '-' ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <?php if ($log['error_message']): ?>
                        <span style="color:var(--red)"><?= htmlspecialchars($log['error_message']) ?></span>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
