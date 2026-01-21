<?php
// Liste des tâches planifiées
?>

<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Tâches Planifiées</h1>
        <button onclick="processQueue()" class="btn-primary">
            Traiter la file d'attente
        </button>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Planification</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Dernière exécution</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">
                        Aucune tâche planifiée
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($task['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($task['task_type']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($task['schedule_cron']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($task['is_active']): ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Actif</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Inactif</span>
                        <?php endif; ?>
                        <?php if ($task['last_status']): ?>
                        <span class="ml-2 px-2 py-1 text-xs font-semibold rounded-full <?= $task['last_status'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                            <?= ucfirst($task['last_status']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                        <?= $task['last_run_at'] ? date('d/m/Y H:i', strtotime($task['last_run_at'])) : 'Jamais' ?>
                        <?php if ($task['last_error']): ?>
                        <div class="text-xs text-red-600 dark:text-red-400 mt-1" title="<?= htmlspecialchars($task['last_error']) ?>">
                            ⚠ Erreur
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="runTask(<?= $task['id'] ?>)" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                            Exécuter maintenant
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function runTask(id) {
    if (!confirm('Exécuter cette tâche maintenant ?')) return;
    fetch('<?= url('/admin/scheduled-tasks') ?>/' + id + '/run', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ ' + (data.message || 'Tâche exécutée avec succès'));
                location.reload();
            } else {
                alert('❌ ' + (data.error || 'Erreur lors de l\'exécution'));
            }
        });
}

function processQueue() {
    if (!confirm('Traiter toutes les tâches en attente dans la file ?')) return;
    fetch('<?= url('/admin/scheduled-tasks/process-queue') ?>', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            alert('✅ ' + data.processed + ' tâche(s) traitée(s)');
            if (data.errors && data.errors.length > 0) {
                console.error('Erreurs:', data.errors);
            }
            location.reload();
        });
}
</script>
