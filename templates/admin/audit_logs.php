<?php
// Liste des logs d'audit
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Logs d'audit</h1>
    </div>

    <!-- Statistiques (7 derniers jours) -->
    <?php if (!empty($stats)): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Actions des 7 derniers jours</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach ($stats as $stat): ?>
            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                <div class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($stat['action']) ?></div>
                <div class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= number_format($stat['count']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filtres -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <form method="GET" action="<?= url('/admin/audit-logs') ?>" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label for="user_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Utilisateur</label>
                <select id="user_id" name="user_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">Tous</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($filters['user_id'] ?? null) == $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="action" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Action</label>
                <select id="action" name="action" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">Toutes</option>
                    <option value="document.created" <?= ($filters['action'] ?? '') === 'document.created' ? 'selected' : '' ?>>Document créé</option>
                    <option value="document.updated" <?= ($filters['action'] ?? '') === 'document.updated' ? 'selected' : '' ?>>Document modifié</option>
                    <option value="document.deleted" <?= ($filters['action'] ?? '') === 'document.deleted' ? 'selected' : '' ?>>Document supprimé</option>
                    <option value="document.restored" <?= ($filters['action'] ?? '') === 'document.restored' ? 'selected' : '' ?>>Document restauré</option>
                    <option value="tag.created" <?= ($filters['action'] ?? '') === 'tag.created' ? 'selected' : '' ?>>Tag créé</option>
                    <option value="tag.updated" <?= ($filters['action'] ?? '') === 'tag.updated' ? 'selected' : '' ?>>Tag modifié</option>
                    <option value="tag.deleted" <?= ($filters['action'] ?? '') === 'tag.deleted' ? 'selected' : '' ?>>Tag supprimé</option>
                </select>
            </div>
            
            <div>
                <label for="date_from" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date début</label>
                <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
            </div>
            
            <div>
                <label for="date_to" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date fin</label>
                <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
            </div>
            
            <div class="md:col-span-4 flex justify-end space-x-2">
                <a href="<?= url('/admin/audit-logs') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Réinitialiser</a>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Filtrer</button>
            </div>
        </form>
    </div>

    <!-- Liste des logs -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Objet</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Changements</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                        Aucun log d'audit trouvé.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        <?= htmlspecialchars($log['user_username'] ?? 'Système') ?>
                    </td>
                    <td class="px-6 py-4">
                        <code class="text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded">
                            <?= htmlspecialchars($log['action']) ?>
                        </code>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <div class="font-medium"><?= htmlspecialchars($log['object_type']) ?> #<?= $log['object_id'] ?></div>
                        <?php if ($log['object_name']): ?>
                        <div class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($log['object_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?php if (!empty($log['changes'])): ?>
                        <details class="cursor-pointer">
                            <summary class="text-blue-600 dark:text-blue-400 hover:underline">Voir changements</summary>
                            <pre class="mt-2 text-xs bg-gray-100 dark:bg-gray-700 p-2 rounded overflow-auto max-h-40"><?= htmlspecialchars(json_encode($log['changes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </details>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                        <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> logs)
            </div>
            <div class="flex space-x-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= !empty($filters) ? '&' . http_build_query($filters) : '' ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">Précédent</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= !empty($filters) ? '&' . http_build_query($filters) : '' ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">Suivant</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
