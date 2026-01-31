<?php
// Comparaison de snapshots
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="<?= url('/admin/snapshots') ?>" class="text-sm text-blue-600 hover:underline">&larr; Retour aux snapshots</a>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-2">Comparer les snapshots</h1>
        </div>
    </div>

    <!-- Selecteurs -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Snapshot de base</label>
                <select name="from" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">Selectionnez...</option>
                    <?php foreach ($snapshots as $snap): ?>
                    <option value="<?= $snap['id'] ?>" <?= $fromId == $snap['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($snap['name']) ?> (<?= date('d/m/Y', strtotime($snap['created_at'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Comparer a</label>
                <select name="to" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">Selectionnez...</option>
                    <?php foreach ($snapshots as $snap): ?>
                    <option value="<?= $snap['id'] ?>" <?= $toId == $snap['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($snap['name']) ?> (<?= date('d/m/Y', strtotime($snap['created_at'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Comparer
                </button>
            </div>
        </form>
    </div>

    <?php if ($diff): ?>
    <!-- Resume -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-green-50 dark:bg-green-900/30 rounded-lg shadow p-6">
            <div class="text-sm text-green-600 dark:text-green-400">Ajoutes</div>
            <div class="text-3xl font-bold text-green-700 dark:text-green-300"><?= count($diff['added'] ?? []) ?></div>
        </div>
        <div class="bg-blue-50 dark:bg-blue-900/30 rounded-lg shadow p-6">
            <div class="text-sm text-blue-600 dark:text-blue-400">Modifies</div>
            <div class="text-3xl font-bold text-blue-700 dark:text-blue-300"><?= count($diff['modified'] ?? []) ?></div>
        </div>
        <div class="bg-red-50 dark:bg-red-900/30 rounded-lg shadow p-6">
            <div class="text-sm text-red-600 dark:text-red-400">Supprimes</div>
            <div class="text-3xl font-bold text-red-700 dark:text-red-300"><?= count($diff['removed'] ?? []) ?></div>
        </div>
    </div>

    <!-- Details des changements -->
    <?php if (!empty($diff['added'])): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-green-600">Elements ajoutes (<?= count($diff['added']) ?>)</h3>
        </div>
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach (array_slice($diff['added'], 0, 50) as $item): ?>
                <tr class="hover:bg-green-50 dark:hover:bg-green-900/20">
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800"><?= ucfirst($item['entity_type']) ?></span>
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-400">#<?= $item['entity_id'] ?></td>
                    <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?= htmlspecialchars($item['entity_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($diff['added']) > 50): ?>
                <tr>
                    <td colspan="3" class="px-6 py-3 text-center text-sm text-gray-500">
                        ... et <?= count($diff['added']) - 50 ?> autres elements
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($diff['modified'])): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-blue-600">Elements modifies (<?= count($diff['modified']) ?>)</h3>
        </div>
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Changement</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach (array_slice($diff['modified'], 0, 50) as $item): ?>
                <tr class="hover:bg-blue-50 dark:hover:bg-blue-900/20">
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800"><?= ucfirst($item['entity_type']) ?></span>
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-400">#<?= $item['entity_id'] ?></td>
                    <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?= htmlspecialchars($item['entity_name'] ?? '-') ?></td>
                    <td class="px-6 py-3 text-sm text-gray-500">
                        <?php if (!empty($item['changes'])): ?>
                        <code class="text-xs"><?= htmlspecialchars(json_encode($item['changes'])) ?></code>
                        <?php else: ?>
                        Checksum modifie
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($diff['modified']) > 50): ?>
                <tr>
                    <td colspan="4" class="px-6 py-3 text-center text-sm text-gray-500">
                        ... et <?= count($diff['modified']) - 50 ?> autres elements
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($diff['removed'])): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-red-600">Elements supprimes (<?= count($diff['removed']) ?>)</h3>
        </div>
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach (array_slice($diff['removed'], 0, 50) as $item): ?>
                <tr class="hover:bg-red-50 dark:hover:bg-red-900/20">
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800"><?= ucfirst($item['entity_type']) ?></span>
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-400">#<?= $item['entity_id'] ?></td>
                    <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?= htmlspecialchars($item['entity_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($diff['removed']) > 50): ?>
                <tr>
                    <td colspan="3" class="px-6 py-3 text-center text-sm text-gray-500">
                        ... et <?= count($diff['removed']) - 50 ?> autres elements
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php elseif ($fromId && $toId): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-8 text-center">
        <p class="text-gray-500 dark:text-gray-400">Aucune difference trouvee entre ces deux snapshots.</p>
    </div>
    <?php else: ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-8 text-center">
        <p class="text-gray-500 dark:text-gray-400">Selectionnez deux snapshots a comparer.</p>
    </div>
    <?php endif; ?>
</div>
