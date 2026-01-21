<?php
// $tasks, $page, $totalPages, $total, $status, $showMine, $stats sont passés depuis le contrôleur
$success = $_GET['success'] ?? null;
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Tâches</h1>
        <a href="<?= url('/tasks/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            ➕ Créer une tâche
        </a>
    </div>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded">
            Tâche créée/mise à jour avec succès !
        </div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-sm text-gray-500">Total</div>
            <div class="text-2xl font-bold text-gray-800"><?= $stats['total'] ?></div>
        </div>
        <div class="bg-yellow-50 rounded-lg shadow p-4">
            <div class="text-sm text-yellow-700">En attente</div>
            <div class="text-2xl font-bold text-yellow-800"><?= $stats['pending'] ?></div>
        </div>
        <div class="bg-blue-50 rounded-lg shadow p-4">
            <div class="text-sm text-blue-700">En cours</div>
            <div class="text-2xl font-bold text-blue-800"><?= $stats['in_progress'] ?></div>
        </div>
        <div class="bg-green-50 rounded-lg shadow p-4">
            <div class="text-sm text-green-700">Terminées</div>
            <div class="text-2xl font-bold text-green-800"><?= $stats['completed'] ?></div>
        </div>
        <div class="bg-purple-50 rounded-lg shadow p-4">
            <div class="text-sm text-purple-700">Mes tâches</div>
            <div class="text-2xl font-bold text-purple-800"><?= $stats['my_tasks'] ?></div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="bg-white rounded-lg shadow p-4">
        <form method="GET" action="<?= url('/tasks') ?>" class="flex flex-wrap gap-4 items-end">
            <div class="min-w-[200px]">
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                <select 
                    id="status" 
                    name="status"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
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
                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                    >
                    <span class="ml-2 text-sm text-gray-700">Mes tâches uniquement</span>
                </label>
            </div>
            <div>
                <button 
                    type="submit"
                    class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"
                >
                    🔍 Filtrer
                </button>
            </div>
            <?php if ($status || $showMine): ?>
                <div>
                    <a href="<?= url('/tasks') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Réinitialiser
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($tasks)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <p class="text-gray-500 text-lg mb-4">Aucune tâche pour le moment</p>
            <a href="<?= url('/tasks/create') ?>" class="inline-block px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Créer votre première tâche
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Titre</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigné à</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priorité</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Échéance</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($task['title']) ?>
                                </div>
                                <?php if ($task['description']): ?>
                                    <div class="text-sm text-gray-500 mt-1">
                                        <?= htmlspecialchars(substr($task['description'], 0, 50)) ?><?= strlen($task['description']) > 50 ? '...' : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php if ($task['document_id']): ?>
                                    <a href="<?= url('/documents/' . $task['document_id']) ?>" class="text-blue-600 hover:text-blue-900">
                                        <?= htmlspecialchars($task['document_title'] ?: $task['document_filename']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($task['assigned_to_username'] ?: 'Non assigné') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php
                                $priorityColors = [
                                    'low' => 'bg-gray-100 text-gray-800',
                                    'medium' => 'bg-yellow-100 text-yellow-800',
                                    'high' => 'bg-orange-100 text-orange-800',
                                    'urgent' => 'bg-red-100 text-red-800',
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
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    'in_progress' => 'bg-blue-100 text-blue-800',
                                    'completed' => 'bg-green-100 text-green-800',
                                    'cancelled' => 'bg-gray-100 text-gray-800',
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
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $task['due_date'] ? date('d/m/Y', strtotime($task['due_date'])) : '-' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <?php if ($task['status'] !== 'completed' && $task['status'] !== 'cancelled'): ?>
                                    <form method="POST" action="<?= url('/tasks/' . $task['id'] . '/status') ?>" class="inline">
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" class="text-green-600 hover:text-green-900">✓ Terminer</button>
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
                <div class="text-sm text-gray-700">
                    Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> tâches)
                </div>
                <div class="flex space-x-2">
                    <?php
                    $queryString = '';
                    if ($status) $queryString .= '&status=' . urlencode($status);
                    if ($showMine) $queryString .= '&mine=1';
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="<?= url('/tasks?page=' . ($page - 1) . $queryString) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Précédent
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('/tasks?page=' . ($page + 1) . $queryString) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Suivant
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
