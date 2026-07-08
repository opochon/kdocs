<?php
// $tasks, $page, $totalPages, $total, $status, $showMine, $stats sont passés depuis le contrôleur
$success = $_GET['success'] ?? null;
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Tâches</h1>
        <a href="<?= url('/tasks/create') ?>" class="px-4 py-2 rounded-lg btn-primary">
            ➕ Créer une tâche
        </a>
    </div>

    <?php if ($success): ?>
        <div class="ds-chip--green px-4 py-3 rounded">
            Tâche créée/mise à jour avec succès !
        </div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="ds-card shadow p-4">
            <div class="text-sm" style="color:var(--dim)">Total</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['total'] ?></div>
        </div>
        <div class="ds-chip--amber rounded-lg shadow p-4">
            <div class="text-sm">En attente</div>
            <div class="text-2xl font-bold"><?= $stats['pending'] ?></div>
        </div>
        <div class="ds-chip--accent rounded-lg shadow p-4">
            <div class="text-sm">En cours</div>
            <div class="text-2xl font-bold"><?= $stats['in_progress'] ?></div>
        </div>
        <div class="ds-chip--green rounded-lg shadow p-4">
            <div class="text-sm">Terminées</div>
            <div class="text-2xl font-bold"><?= $stats['completed'] ?></div>
        </div>
        <div class="ds-chip--neutral rounded-lg shadow p-4">
            <div class="text-sm">Mes tâches</div>
            <div class="text-2xl font-bold"><?= $stats['my_tasks'] ?></div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="ds-card shadow p-4">
        <form method="GET" action="<?= url('/tasks') ?>" class="flex flex-wrap gap-4 items-end">
            <div class="min-w-[200px]">
                <label for="status" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Statut</label>
                <select
                    id="status"
                    name="status"
                    class="block w-full px-3 py-2 rounded-md shadow-sm"
                >
                    <option value="">Tous les statuts</option>
                    <option value="pending" <?= ($status === 'pending') ? 'selected' : '' ?>>En attente</option>
                    <option value="in_progress" <?= ($status === 'in_progress') ? 'selected' : '' ?>>En cours</option>
                    <option value="completed" <?= ($status === 'completed') ? 'selected' : '' ?>>Terminée</option>
                    <option value="cancelled" <?= ($status === 'cancelled') ? 'selected' : '' ?>>Annulée</option>
                </select>
            </div>
            <div>
                <label class="flex items-center">
                    <input
                        type="checkbox"
                        name="mine"
                        value="1"
                        <?= $showMine ? 'checked' : '' ?>
                        class="rounded focus:ring-blue-500"
                        style="accent-color:var(--accent)"
                    >
                    <span class="ml-2 text-sm" style="color:var(--ink-soft)">Mes tâches uniquement</span>
                </label>
            </div>
            <div>
                <button
                    type="submit"
                    class="px-4 py-2 border rounded-lg btn-secondary"
                >
                    🔍 Filtrer
                </button>
            </div>
            <?php if ($status || $showMine): ?>
                <div>
                    <a href="<?= url('/tasks') ?>" class="px-4 py-2 border rounded-lg btn-secondary">
                        Réinitialiser
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($tasks)): ?>
        <div class="ds-card shadow p-12 text-center">
            <p class="text-lg mb-4" style="color:var(--dim)">Aucune tâche pour le moment</p>
            <a href="<?= url('/tasks/create') ?>" class="inline-block px-6 py-2 rounded-lg btn-primary">
                Créer votre première tâche
            </a>
        </div>
    <?php else: ?>
        <div class="ds-card shadow overflow-hidden">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Titre</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Document</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Assigné à</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Priorité</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Échéance</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium" style="color:var(--ink)">
                                    <?= htmlspecialchars($task['title'] ?? '(sans titre)') ?>
                                </div>
                                <?php if (!empty($task['description'])): ?>
                                    <div class="text-sm mt-1" style="color:var(--dim)">
                                        <?= htmlspecialchars(substr($task['description'], 0, 50)) ?><?= strlen($task['description']) > 50 ? '...' : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                                <?php if ($task['document_id']): ?>
                                    <a href="<?= url('/documents/' . $task['document_id']) ?>">
                                        <?= htmlspecialchars($task['document_title'] ?: $task['document_filename']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--dim)">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                                <?= htmlspecialchars($task['assigned_to_username'] ?: 'Non assigné') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $priorityColors = [
                                    'low' => 'ds-chip--neutral',
                                    'medium' => 'ds-chip--amber',
                                    'high' => 'ds-chip--amber',
                                    'urgent' => 'ds-chip--red',
                                ];
                                $priorityLabels = [
                                    'low' => 'Basse',
                                    'medium' => 'Moyenne',
                                    'high' => 'Haute',
                                    'urgent' => 'Urgente',
                                ];
                                $priority = $task['priority'] ?: 'medium';
                                ?>
                                <span class="px-2 py-1 text-xs rounded <?= $priorityColors[$priority] ?? $priorityColors['medium'] ?>">
                                    <?= $priorityLabels[$priority] ?? 'Moyenne' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $statusColors = [
                                    'pending' => 'ds-chip--amber',
                                    'in_progress' => 'ds-chip--accent',
                                    'completed' => 'ds-chip--green',
                                    'cancelled' => 'ds-chip--neutral',
                                ];
                                $statusLabels = [
                                    'pending' => 'En attente',
                                    'in_progress' => 'En cours',
                                    'completed' => 'Terminée',
                                    'cancelled' => 'Annulée',
                                ];
                                $taskStatus = $task['status'] ?: 'pending';
                                ?>
                                <span class="px-2 py-1 text-xs rounded <?= $statusColors[$taskStatus] ?? $statusColors['pending'] ?>">
                                    <?= $statusLabels[$taskStatus] ?? 'En attente' ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                                <?= $task['due_date'] ? date('d/m/Y', strtotime($task['due_date'])) : '-' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
                                    <form method="POST" action="<?= url('/tasks/' . $task['id'] . '/status') ?>" class="inline">
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" style="color:var(--green)">✓ Terminer</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between">
                <div class="text-sm" style="color:var(--ink-soft)">
                    Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> tâches)
                </div>
                <div class="flex space-x-2">
                    <?php
                    $queryString = '';
                    if ($status) $queryString .= '&status=' . urlencode($status);
                    if ($showMine) $queryString .= '&mine=1';
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="<?= url('/tasks?page=' . ($page - 1) . $queryString) ?>" class="px-4 py-2 border rounded-lg btn-secondary">
                            Précédent
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('/tasks?page=' . ($page + 1) . $queryString) ?>" class="px-4 py-2 border rounded-lg btn-secondary">
                            Suivant
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
