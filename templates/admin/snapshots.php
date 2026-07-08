<?php
// Liste des snapshots
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Snapshots</h1>
        <div class="flex space-x-2">
            <a href="<?= url('/admin/snapshots/compare') ?>" class="btn btn-secondary">
                Comparer
            </a>
            <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="btn btn-primary">
                + Nouveau snapshot
            </button>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
    <div class="p-4 rounded-lg" style="<?= $_SESSION['flash']['type'] === 'success' ? 'background:color-mix(in srgb,var(--green) 10%,transparent);color:var(--green)' : 'background:color-mix(in srgb,var(--red) 10%,transparent);color:var(--red)' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Total</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['total'] ?></div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Manuels</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['manual'] ?></div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Automatiques</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['auto'] ?></div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Backups</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['backup'] ?></div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Taille totale</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['total_size'] ?></div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="ds-card rounded-lg shadow p-4">
        <form method="GET" class="flex items-center space-x-4">
            <label class="text-sm" style="color:var(--ink-soft)">Type:</label>
            <select name="type" onchange="this.form.submit()" class="px-3 py-2 rounded-lg">
                <option value="">Tous</option>
                <option value="manual" <?= $type === 'manual' ? 'selected' : '' ?>>Manuel</option>
                <option value="auto" <?= $type === 'auto' ? 'selected' : '' ?>>Automatique</option>
                <option value="backup" <?= $type === 'backup' ? 'selected' : '' ?>>Backup</option>
            </select>
            <?php if ($type): ?>
            <a href="<?= url('/admin/snapshots') ?>" class="text-sm hover:underline" style="color:var(--accent)">Effacer filtre</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Liste des snapshots -->
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Entites</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Taille</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($snapshots)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center" style="color:var(--dim)">
                        Aucun snapshot. Cliquez sur "Nouveau snapshot" pour en creer un.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($snapshots as $snap): ?>
                <tr>
                    <td class="px-6 py-4">
                        <a href="<?= url('/admin/snapshots/' . $snap['id']) ?>" class="font-medium hover:underline" style="color:var(--accent)">
                            <?= htmlspecialchars($snap['name']) ?>
                        </a>
                        <?php if ($snap['description']): ?>
                        <div class="text-xs mt-1" style="color:var(--dim)">
                            <?= htmlspecialchars(substr($snap['description'], 0, 60)) ?>...
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php
                        $typeColors = [
                            'manual' => 'ds-chip--neutral',
                            'auto' => 'ds-chip--neutral',
                            'backup' => 'ds-chip--neutral'
                        ];
                        $typeLabels = ['manual' => 'Manuel', 'auto' => 'Auto', 'backup' => 'Backup'];
                        ?>
                        <span class="px-2 py-1 text-xs font-medium ds-chip <?= $typeColors[$snap['snapshot_type']] ?? 'ds-chip--neutral' ?>">
                            <?= $typeLabels[$snap['snapshot_type']] ?? $snap['snapshot_type'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <?= date('d/m/Y H:i', strtotime($snap['created_at'])) ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <?= number_format($snap['item_count'] ?? 0) ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
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
                        <a href="<?= url('/admin/snapshots/' . $snap['id']) ?>" class="hover:underline text-sm" style="color:var(--accent)">
                            Voir
                        </a>
                        <?php if ($snap['snapshot_type'] !== 'backup'): ?>
                        <form method="POST" action="<?= url('/admin/snapshots/' . $snap['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Supprimer ce snapshot ?');">
                            <button type="submit" class="hover:underline text-sm" style="color:var(--red)">Supprimer</button>
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
        <div class="px-6 py-4 border-t flex items-center justify-between">
            <div class="text-sm" style="color:var(--ink-soft)">
                Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> snapshots)
            </div>
            <div class="flex space-x-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $type ? '&type=' . $type : '' ?>" class="btn btn-secondary">Precedent</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $type ? '&type=' . $type : '' ?>" class="btn btn-secondary">Suivant</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de creation -->
<div id="createModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="ds-card rounded-lg shadow-xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold" style="color:var(--ink)">Nouveau snapshot</h3>
            <button onclick="document.getElementById('createModal').classList.add('hidden')" style="color:var(--dim)">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" action="<?= url('/admin/snapshots/create') ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nom</label>
                    <input type="text" name="name" value="Snapshot <?= date('Y-m-d H:i') ?>" required
                        class="w-full px-3 py-2 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Description (optionnel)</label>
                    <textarea name="description" rows="3"
                        class="w-full px-3 py-2 rounded-lg"
                        placeholder="Description du snapshot..."></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')"
                    class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    Creer le snapshot
                </button>
            </div>
        </form>
    </div>
</div>
