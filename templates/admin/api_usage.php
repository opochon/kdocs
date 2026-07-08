<?php
// Variables: $stats, $periodStats, $typeStats, $recentLogs, $period, $tableExists
?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Statistiques d'utilisation API Claude</h1>
        <div class="flex gap-2">
            <select id="period-selector" class="px-3 py-2 rounded-md text-sm">
                <option value="7" <?= $period == '7' ? 'selected' : '' ?>>7 derniers jours</option>
                <option value="30" <?= $period == '30' ? 'selected' : '' ?>>30 derniers jours</option>
                <option value="90" <?= $period == '90' ? 'selected' : '' ?>>90 derniers jours</option>
                <option value="365" <?= $period == '365' ? 'selected' : '' ?>>1 an</option>
                <option value="all" <?= $period == 'all' ? 'selected' : '' ?>>Tout</option>
            </select>
        </div>
    </div>

    <?php if (!$tableExists): ?>
    <div class="border rounded-lg p-4 mb-6" style="border-color:var(--amber);background:color-mix(in srgb,var(--amber) 10%,transparent)">
        <p style="color:var(--amber)">
            ⚠️ La table de suivi des coûts API n'existe pas encore. 
            Exécutez la migration <code>015_api_usage_tracking.sql</code> pour activer le suivi.
        </p>
    </div>
    <?php else: ?>

    <!-- Statistiques globales -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="ds-card rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm" style="color:var(--dim)">Total requêtes</p>
                    <p class="text-3xl font-bold" style="color:var(--ink)"><?= number_format($stats['total_requests'] ?? 0) ?></p>
                    <p class="text-xs mt-1" style="color:var(--dim)">
                        <?= number_format($stats['successful_requests'] ?? 0) ?> réussies
                        <?php if (($stats['failed_requests'] ?? 0) > 0): ?>
                        <span style="color:var(--red)"><?= number_format($stats['failed_requests']) ?> échouées</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-4xl">📊</div>
            </div>
        </div>

        <div class="ds-card rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm" style="color:var(--dim)">Tokens totaux</p>
                    <p class="text-3xl font-bold" style="color:var(--ink)"><?= number_format($stats['total_tokens'] ?? 0) ?></p>
                    <p class="text-xs mt-1" style="color:var(--dim)">
                        <?= number_format($stats['total_input_tokens'] ?? 0) ?> entrée
                        / <?= number_format($stats['total_output_tokens'] ?? 0) ?> sortie
                    </p>
                </div>
                <div class="text-4xl">🔢</div>
            </div>
        </div>

        <div class="ds-card rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm" style="color:var(--dim)">Coût total estimé</p>
                    <p class="text-3xl font-bold" style="color:var(--ink)">$<?= number_format($stats['total_cost_usd'] ?? 0, 4) ?></p>
                    <p class="text-xs mt-1" style="color:var(--dim)">USD</p>
                </div>
                <div class="text-4xl">💰</div>
            </div>
        </div>

        <div class="ds-card rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm" style="color:var(--dim)">Coût moyen/requête</p>
                    <p class="text-3xl font-bold" style="color:var(--ink)">
                        $<?= $stats['total_requests'] > 0 ? number_format(($stats['total_cost_usd'] ?? 0) / $stats['total_requests'], 6) : '0.000000' ?>
                    </p>
                    <p class="text-xs mt-1" style="color:var(--dim)">USD</p>
                </div>
                <div class="text-4xl">📈</div>
            </div>
        </div>
    </div>

    <!-- Statistiques par type -->
    <?php if (!empty($typeStats)): ?>
    <div class="ds-card rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">Répartition par type de requête</h2>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Requêtes</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Tokens entrée</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Tokens sortie</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Coût estimé</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($typeStats as $type): ?>
                    <tr>
                        <td class="px-4 py-3 text-sm">
                            <?php
                            $typeLabels = [
                                'text' => '📝 Texte',
                                'file' => '📎 Fichier',
                                'complex' => '🔬 Complexe'
                            ];
                            echo $typeLabels[$type['request_type']] ?? $type['request_type'];
                            ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right"><?= number_format($type['count']) ?></td>
                        <td class="px-4 py-3 text-sm text-right"><?= number_format($type['input_tokens'] ?? 0) ?></td>
                        <td class="px-4 py-3 text-sm text-right"><?= number_format($type['output_tokens'] ?? 0) ?></td>
                        <td class="px-4 py-3 text-sm text-right font-medium">$<?= number_format($type['cost_usd'] ?? 0, 4) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Évolution dans le temps -->
    <?php if (!empty($periodStats)): ?>
    <div class="ds-card rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">Évolution sur la période</h2>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Requêtes</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Tokens</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Coût</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($periodStats as $day): ?>
                    <tr>
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y', strtotime($day['date'])) ?></td>
                        <td class="px-4 py-3 text-sm text-right"><?= number_format($day['requests']) ?></td>
                        <td class="px-4 py-3 text-sm text-right"><?= number_format($day['total_tokens'] ?? 0) ?></td>
                        <td class="px-4 py-3 text-sm text-right font-medium">$<?= number_format($day['cost_usd'] ?? 0, 4) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logs récents -->
    <?php if (!empty($recentLogs)): ?>
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">50 dernières requêtes</h2>
        <div class="overflow-x-auto">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Date/Heure</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase">Document</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Tokens</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase">Coût</th>
                        <th class="px-4 py-3 text-center text-xs font-medium uppercase">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                        <td class="px-4 py-3 text-sm">
                            <?php
                            $typeLabels = [
                                'text' => '📝 Texte',
                                'file' => '📎 Fichier',
                                'complex' => '🔬 Complexe'
                            ];
                            echo $typeLabels[$log['request_type']] ?? $log['request_type'];
                            ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php if ($log['document_name']): ?>
                            <a href="<?= url('/documents/' . $log['document_id']) ?>" style="color:var(--accent)">
                                <?= htmlspecialchars($log['document_name']) ?>
                            </a>
                            <?php else: ?>
                            <span style="color:var(--dim)">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right">
                            <?= number_format($log['total_tokens']) ?>
                            <span class="text-xs" style="color:var(--dim)">
                                (<?= number_format($log['input_tokens']) ?>+<?= number_format($log['output_tokens']) ?>)
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right font-medium">$<?= number_format($log['estimated_cost_usd'], 6) ?></td>
                        <td class="px-4 py-3 text-sm text-center">
                            <?php if ($log['success']): ?>
                            <span class="px-2 py-1 ds-chip ds-chip--green text-xs">✓ Succès</span>
                            <?php else: ?>
                            <span class="px-2 py-1 ds-chip ds-chip--red text-xs" title="<?= htmlspecialchars($log['error_message'] ?? '') ?>">✗ Erreur</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
document.getElementById('period-selector')?.addEventListener('change', function() {
    const period = this.value;
    const url = new URL(window.location);
    url.searchParams.set('period', period);
    window.location.href = url.toString();
});
</script>
