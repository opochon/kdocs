<?php
// Variables: $stats, $periodStats, $typeStats, $recentLogs, $period, $tableExists
?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Statistiques d'utilisation API Claude</h1>
        <div class="flex gap-2">
            <select id="period-selector" class="px-3 py-2 border rounded-md text-sm">
                <option value="7" <?= $period == '7' ? 'selected' : '' ?>>7 derniers jours</option>
                <option value="30" <?= $period == '30' ? 'selected' : '' ?>>30 derniers jours</option>
                <option value="90" <?= $period == '90' ? 'selected' : '' ?>>90 derniers jours</option>
                <option value="365" <?= $period == '365' ? 'selected' : '' ?>>1 an</option>
                <option value="all" <?= $period == 'all' ? 'selected' : '' ?>>Tout</option>
            </select>
        </div>
    </div>

    <?php if (!$tableExists): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
        <p class="text-yellow-800">
            ⚠️ La table de suivi des coûts API n'existe pas encore. 
            Exécutez la migration <code>015_api_usage_tracking.sql</code> pour activer le suivi.
        </p>
    </div>
    <?php else: ?>

    <!-- Statistiques globales -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total requêtes</p>
                    <p class="text-3xl font-bold text-gray-800"><?= number_format($stats['total_requests'] ?? 0) ?></p>
                    <p class="text-xs text-gray-400 mt-1">
                        <?= number_format($stats['successful_requests'] ?? 0) ?> réussies
                        <?php if (($stats['failed_requests'] ?? 0) > 0): ?>
                        <span class="text-red-600"><?= number_format($stats['failed_requests']) ?> échouées</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="text-4xl">📊</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Tokens totaux</p>
                    <p class="text-3xl font-bold text-gray-800"><?= number_format($stats['total_tokens'] ?? 0) ?></p>
                    <p class="text-xs text-gray-400 mt-1">
                        <?= number_format($stats['total_input_tokens'] ?? 0) ?> entrée
                        / <?= number_format($stats['total_output_tokens'] ?? 0) ?> sortie
                    </p>
                </div>
                <div class="text-4xl">🔢</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Coût total estimé</p>
                    <p class="text-3xl font-bold text-gray-800">$<?= number_format($stats['total_cost_usd'] ?? 0, 4) ?></p>
                    <p class="text-xs text-gray-400 mt-1">USD</p>
                </div>
                <div class="text-4xl">💰</div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Coût moyen/requête</p>
                    <p class="text-3xl font-bold text-gray-800">
                        $<?= $stats['total_requests'] > 0 ? number_format(($stats['total_cost_usd'] ?? 0) / $stats['total_requests'], 6) : '0.000000' ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">USD</p>
                </div>
                <div class="text-4xl">📈</div>
            </div>
        </div>
    </div>

    <!-- Statistiques par type -->
    <?php if (!empty($typeStats)): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Répartition par type de requête</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Requêtes</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tokens entrée</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tokens sortie</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Coût estimé</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
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
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Évolution sur la période</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Requêtes</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tokens</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Coût</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
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
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">50 dernières requêtes</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date/Heure</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tokens</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Coût</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Statut</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
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
                            <a href="<?= url('/documents/' . $log['document_id']) ?>" class="text-blue-600 hover:text-blue-800">
                                <?= htmlspecialchars($log['document_name']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right">
                            <?= number_format($log['total_tokens']) ?>
                            <span class="text-xs text-gray-400">
                                (<?= number_format($log['input_tokens']) ?>+<?= number_format($log['output_tokens']) ?>)
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right font-medium">$<?= number_format($log['estimated_cost_usd'], 6) ?></td>
                        <td class="px-4 py-3 text-sm text-center">
                            <?php if ($log['success']): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs">✓ Succès</span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-xs" title="<?= htmlspecialchars($log['error_message'] ?? '') ?>">✗ Erreur</span>
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
