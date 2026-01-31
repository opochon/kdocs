<?php
// Detail d'un snapshot
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="<?= url('/admin/snapshots') ?>" class="text-sm text-blue-600 hover:underline">&larr; Retour aux snapshots</a>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-2"><?= htmlspecialchars($snapshot['name']) ?></h1>
            <?php if ($snapshot['description']): ?>
            <p class="text-gray-600 dark:text-gray-400 mt-1"><?= htmlspecialchars($snapshot['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="flex space-x-2">
            <button onclick="document.getElementById('restoreModal').classList.remove('hidden')" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                Restaurer
            </button>
            <a href="<?= url('/api/snapshots/' . $snapshot['id'] . '/export') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Exporter JSON
            </a>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
    <div class="p-4 rounded-lg <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- Infos du snapshot -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Type</div>
            <div class="text-lg font-semibold text-gray-800 dark:text-gray-100 mt-1">
                <?php
                $typeLabels = ['manual' => 'Manuel', 'auto' => 'Automatique', 'backup' => 'Backup'];
                echo $typeLabels[$snapshot['snapshot_type']] ?? $snapshot['snapshot_type'];
                ?>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Date de creation</div>
            <div class="text-lg font-semibold text-gray-800 dark:text-gray-100 mt-1">
                <?= date('d/m/Y H:i:s', strtotime($snapshot['created_at'])) ?>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Elements</div>
            <div class="text-lg font-semibold text-gray-800 dark:text-gray-100 mt-1">
                <?= number_format($snapshot['item_count'] ?? 0) ?>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Taille</div>
            <div class="text-lg font-semibold text-gray-800 dark:text-gray-100 mt-1">
                <?php
                $size = $snapshot['total_size'] ?? 0;
                if ($size >= 1048576) {
                    echo number_format($size / 1048576, 1) . ' MB';
                } elseif ($size >= 1024) {
                    echo number_format($size / 1024, 1) . ' KB';
                } else {
                    echo $size . ' B';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Delta depuis le snapshot precedent -->
    <?php if ($delta && !empty($delta['changes'])): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Changements depuis le snapshot precedent</h2>
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-green-50 dark:bg-green-900/30 p-4 rounded-lg">
                <div class="text-sm text-green-600 dark:text-green-400">Ajoutes</div>
                <div class="text-2xl font-bold text-green-700 dark:text-green-300"><?= $delta['changes']['added'] ?? 0 ?></div>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg">
                <div class="text-sm text-blue-600 dark:text-blue-400">Modifies</div>
                <div class="text-2xl font-bold text-blue-700 dark:text-blue-300"><?= $delta['changes']['modified'] ?? 0 ?></div>
            </div>
            <div class="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg">
                <div class="text-sm text-red-600 dark:text-red-400">Supprimes</div>
                <div class="text-2xl font-bold text-red-700 dark:text-red-300"><?= $delta['changes']['removed'] ?? 0 ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filtre par type d'entite -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <form method="GET" class="flex items-center space-x-4">
            <label class="text-sm text-gray-600 dark:text-gray-400">Filtrer par type:</label>
            <select name="entity" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                <option value="">Tous</option>
                <option value="document" <?= $entityType === 'document' ? 'selected' : '' ?>>Documents</option>
                <option value="folder" <?= $entityType === 'folder' ? 'selected' : '' ?>>Dossiers</option>
                <option value="tag" <?= $entityType === 'tag' ? 'selected' : '' ?>>Tags</option>
                <option value="correspondent" <?= $entityType === 'correspondent' ? 'selected' : '' ?>>Correspondants</option>
                <option value="document_type" <?= $entityType === 'document_type' ? 'selected' : '' ?>>Types de document</option>
            </select>
        </form>
    </div>

    <!-- Liste des items -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Chemin</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Checksum</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                        Aucun element dans ce snapshot.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-6 py-4">
                        <?php
                        $typeColors = [
                            'document' => 'bg-blue-100 text-blue-800',
                            'folder' => 'bg-yellow-100 text-yellow-800',
                            'tag' => 'bg-green-100 text-green-800',
                            'correspondent' => 'bg-purple-100 text-purple-800',
                            'document_type' => 'bg-pink-100 text-pink-800'
                        ];
                        ?>
                        <span class="px-2 py-1 rounded text-xs font-medium <?= $typeColors[$item['entity_type']] ?? 'bg-gray-100 text-gray-800' ?>">
                            <?= ucfirst($item['entity_type']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        #<?= $item['entity_id'] ?>
                    </td>
                    <td class="px-6 py-4 text-sm font-medium text-gray-800 dark:text-gray-200">
                        <?= htmlspecialchars($item['entity_name'] ?? '-') ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?php if ($item['entity_path']): ?>
                        <code class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">
                            <?= htmlspecialchars($item['entity_path']) ?>
                        </code>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?php if ($item['checksum']): ?>
                        <code class="text-xs"><?= substr($item['checksum'], 0, 12) ?>...</code>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de restauration -->
<div id="restoreModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">Restaurer le snapshot</h3>
            <button onclick="document.getElementById('restoreModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" action="<?= url('/admin/snapshots/' . $snapshot['id'] . '/restore') ?>">
            <div class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Selectionnez les elements a restaurer:
                </p>
                <div class="space-y-2">
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="documents" checked class="rounded text-blue-600">
                        <span class="text-gray-800 dark:text-gray-200">Documents</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="folders" checked class="rounded text-blue-600">
                        <span class="text-gray-800 dark:text-gray-200">Dossiers</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="tags" class="rounded text-blue-600">
                        <span class="text-gray-800 dark:text-gray-200">Tags</span>
                    </label>
                </div>
                <div class="p-4 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                    <p class="text-sm text-yellow-800 dark:text-yellow-200">
                        <strong>Attention:</strong> Cette action va creer de nouvelles versions des elements modifies.
                        Les donnees actuelles ne seront pas perdues.
                    </p>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('restoreModal').classList.add('hidden')"
                    class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    Restaurer
                </button>
            </div>
        </form>
    </div>
</div>
