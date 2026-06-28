<?php
// Liste des tâches planifiées
?>

<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Tâches Planifiées</h1>
        <button onclick="processQueue()" class="btn-primary">
            Traiter la file d'attente
        </button>
    </div>

    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Planification</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Dernière exécution</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center" style="color:var(--dim)">
                        Aucune tâche planifiée
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($task['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($task['task_type']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($task['schedule_cron']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($task['is_active']): ?>
                        <span class="px-2 py-1 text-xs font-semibold ds-chip ds-chip--green">Actif</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs font-semibold ds-chip ds-chip--neutral">Inactif</span>
                        <?php endif; ?>
                        <?php if ($task['last_status']): ?>
                        <span class="ml-2 px-2 py-1 text-xs font-semibold ds-chip <?= $task['last_status'] === 'success' ? 'ds-chip--green' : 'ds-chip--red' ?>">
                            <?= ucfirst($task['last_status']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                        <?= $task['last_run_at'] ? date('d/m/Y H:i', strtotime($task['last_run_at'])) : 'Jamais' ?>
                        <?php if ($task['last_error']): ?>
                        <div class="text-xs mt-1" style="color:var(--red)" title="<?= htmlspecialchars($task['last_error']) ?>">
                            ⚠ Erreur
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="runTask(<?= $task['id'] ?>)" style="color:var(--accent)">
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
