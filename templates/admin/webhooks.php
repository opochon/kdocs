<?php
// Liste des webhooks
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Webhooks</h1>
        <a href="<?= url('/admin/webhooks/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + Créer un webhook
        </a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">URL</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Événements</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Statistiques (7j)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($webhooks)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                        Aucun webhook configuré. <a href="<?= url('/admin/webhooks/create') ?>" class="text-blue-600 hover:text-blue-800">Créer le premier webhook</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($webhooks as $webhook): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($webhook['name']) ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-600 dark:text-gray-400 max-w-md truncate" title="<?= htmlspecialchars($webhook['url']) ?>">
                            <?= htmlspecialchars($webhook['url']) ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-wrap gap-1">
                            <?php foreach ($webhook['events'] as $event): ?>
                            <span class="px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 rounded">
                                <?= htmlspecialchars($event) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($webhook['is_active']): ?>
                        <span class="px-2 py-1 text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 rounded">Actif</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded">Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        <?php if (!empty($webhook['stats'])): ?>
                        <div>
                            <span class="font-medium"><?= number_format($webhook['stats']['total_executions'] ?? 0) ?></span> exécutions
                        </div>
                        <div class="text-xs">
                            <?= number_format($webhook['stats']['success_count'] ?? 0) ?> réussies,
                            <?= number_format($webhook['stats']['error_count'] ?? 0) ?> erreurs
                        </div>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div class="flex items-center space-x-2">
                            <a href="<?= url('/admin/webhooks/' . $webhook['id'] . '/logs') ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                                Logs
                            </a>
                            <button onclick="testWebhook(<?= $webhook['id'] ?>)" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300">
                                Test
                            </button>
                            <a href="<?= url('/admin/webhooks/' . $webhook['id'] . '/edit') ?>" class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300">
                                Modifier
                            </a>
                            <form method="POST" action="<?= url('/admin/webhooks/' . $webhook['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce webhook ?');">
                                <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                    Supprimer
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function testWebhook(webhookId) {
    fetch('<?= url('/admin/webhooks') ?>/' + webhookId + '/test', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Webhook de test envoyé avec succès !');
        } else {
            alert('Erreur : ' + (data.error || 'Erreur inconnue'));
        }
    })
    .catch(error => {
        alert('Erreur lors du test du webhook : ' + error.message);
    });
}
</script>
