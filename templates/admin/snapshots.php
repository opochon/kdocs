<?php
// Liste des snapshots
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Snapshots</h1>
        <div class="flex space-x-2">
            <a href="<?= url('/admin/snapshots/compare') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Comparer
            </a>
            <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                + Nouveau snapshot
            </button>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
    <div class="p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Total</div>
            <div class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $stats['total'] ?></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Manuels</div>
            <div class="text-2xl font-bold text-blue-600"><?= $stats['manual'] ?></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Automatiques</div>
            <div class="text-2xl font-bold text-green-600"><?= $stats['auto'] ?></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Backups</div>
            <div class="text-2xl font-bold text-purple-600"><?= $stats['backup'] ?></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Taille totale</div>
            <div class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $stats['total_size'] ?></div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <form method="GET" class="flex items-center space-x-4">
            <label class="text-sm text-gray-600 dark:text-gray-400">Type:</label>
            <select name="type" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                <option value="">Tous</option>
                <option value="manual" <?= $type === 'manual' ? 'selected' : '' ?>>Manuel</option>
                <option value="auto" <?= $type === 'auto' ? 'selected' : '' ?>>Automatique</option>
                <option value="backup" <?= $type === 'backup' ? 'selected' : '' ?>>Backup</option>
            </select>
            <?php if ($type): ?>
            <a href="<?= url('/admin/snapshots') ?>" class="text-sm text-blue-600 hover:underline">Effacer filtre</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Liste des snapshots -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Entites</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Taille</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($snapshots)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                        Aucun snapshot. Cliquez sur "Nouveau snapshot" pour en creer un.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($snapshots as $snap): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-6 py-4">
                        <a href="<?= url('/admin/snapshots/' . $snap['id']) ?>" class="font-medium text-blue-600 hover:underline">
                            <?= htmlspecialchars($snap['name']) ?>
                        </a>
                        <?php if ($snap['description']): ?>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            <?= htmlspecialchars(substr($snap['description'], 0, 60)) ?>...
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php
                        $typeColors = [
                            'manual' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                            'auto' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                            'backup' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'
                        ];
                        $typeLabels = ['manual' => 'Manuel', 'auto' => 'Auto', 'backup' => 'Backup'];
                        ?>
                        <span class="px-2 py-1 rounded text-xs font-medium <?= $typeColors[$snap['snapshot_type']] ?? 'bg-gray-100 text-gray-800' ?>">
                            <?= $typeLabels[$snap['snapshot_type']] ?? $snap['snapshot_type'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?= date('d/m/Y H:i', strtotime($snap['created_at'])) ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?= number_format($snap['item_count'] ?? 0) ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?php
                        $size = $snap['total_size'] ?? 0;
                        if ($size >= 1048576) {
                            echo number_format($size / 1048576, 1) . ' MB';
                        } elseif ($size >= 1024) {
                            echo number_format($size / 1024, 1) . ' KB';
                        } else {
                            echo $size . ' B';
                        }
                        ?>
                    </td>
                    <td class="px-6 py-4 text-right space-x-2">
                        <a href="<?= url('/admin/snapshots/' . $snap['id']) ?>" class="text-blue-600 hover:underline text-sm">
                            Voir
                        </a>
                        <?php if ($snap['snapshot_type'] !== 'backup'): ?>
                        <form method="POST" action="<?= url('/admin/snapshots/' . $snap['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Supprimer ce snapshot ?');">
                            <button type="submit" class="text-red-600 hover:underline text-sm">Supprimer</button>
                        </form>
                        <?php endif; ?>
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
                Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> snapshots)
            </div>
            <div class="flex space-x-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $type ? '&type=' . $type : '' ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">Precedent</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $type ? '&type=' . $type : '' ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">Suivant</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de creation -->
<div id="createModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">Nouveau snapshot</h3>
            <button onclick="document.getElementById('createModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" action="<?= url('/admin/snapshots/create') ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom</label>
                    <input type="text" name="name" value="Snapshot <?= date('Y-m-d H:i') ?>" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description (optionnel)</label>
                    <textarea name="description" rows="3"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                        placeholder="Description du snapshot..."></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')"
                    class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Creer le snapshot
                </button>
            </div>
        </form>
    </div>
</div>
