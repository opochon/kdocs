<?php
// Detail d'un snapshot
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="<?= url('/admin/snapshots') ?>" class="text-sm hover:underline" style="color:var(--accent)">&larr; Retour aux snapshots</a>
            <h1 class="text-2xl font-bold mt-2" style="color:var(--ink)"><?= htmlspecialchars($snapshot['name']) ?></h1>
            <?php if ($snapshot['description']): ?>
            <p class="mt-1" style="color:var(--ink-soft)"><?= htmlspecialchars($snapshot['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="flex space-x-2">
            <button onclick="document.getElementById('restoreModal').classList.remove('hidden')" class="btn btn-primary">
                Restaurer
            </button>
            <a href="<?= url('/api/snapshots/' . $snapshot['id'] . '/export') ?>" class="btn btn-secondary">
                Exporter JSON
            </a>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
    <div class="p-4 rounded-lg" style="<?= $_SESSION['flash']['type'] === 'success' ? 'background:color-mix(in srgb,var(--green) 10%,transparent);color:var(--green)' : 'background:color-mix(in srgb,var(--red) 10%,transparent);color:var(--red)' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- Infos du snapshot -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Type</div>
            <div class="text-lg font-semibold mt-1" style="color:var(--ink)">
                <?php
                $typeLabels = ['manual' => 'Manuel', 'auto' => 'Automatique', 'backup' => 'Backup'];
                echo $typeLabels[$snapshot['snapshot_type']] ?? $snapshot['snapshot_type'];
                ?>
            </div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Date de creation</div>
            <div class="text-lg font-semibold mt-1" style="color:var(--ink)">
                <?= date('d/m/Y H:i:s', strtotime($snapshot['created_at'])) ?>
            </div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Elements</div>
            <div class="text-lg font-semibold mt-1" style="color:var(--ink)">
                <?= number_format($snapshot['item_count'] ?? 0) ?>
            </div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--ink-soft)">Taille</div>
            <div class="text-lg font-semibold mt-1" style="color:var(--ink)">
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
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">Changements depuis le snapshot precedent</h2>
        <div class="grid grid-cols-3 gap-4">
            <div class="p-4 rounded-lg" style="background:color-mix(in srgb,var(--green) 10%,transparent)">
                <div class="text-sm" style="color:var(--green)">Ajoutes</div>
                <div class="text-2xl font-bold" style="color:var(--green)"><?= $delta['changes']['added'] ?? 0 ?></div>
            </div>
            <div class="p-4 rounded-lg" style="background:color-mix(in srgb,var(--amber) 10%,transparent)">
                <div class="text-sm" style="color:var(--amber)">Modifies</div>
                <div class="text-2xl font-bold" style="color:var(--amber)"><?= $delta['changes']['modified'] ?? 0 ?></div>
            </div>
            <div class="p-4 rounded-lg" style="background:color-mix(in srgb,var(--red) 10%,transparent)">
                <div class="text-sm" style="color:var(--red)">Supprimes</div>
                <div class="text-2xl font-bold" style="color:var(--red)"><?= $delta['changes']['removed'] ?? 0 ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filtre par type d'entite -->
    <div class="ds-card rounded-lg shadow p-4">
        <form method="GET" class="flex items-center space-x-4">
            <label class="text-sm" style="color:var(--ink-soft)">Filtrer par type:</label>
            <select name="entity" onchange="this.form.submit()" class="px-3 py-2 rounded-lg">
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
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Chemin</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Checksum</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center" style="color:var(--dim)">
                        Aucun element dans ce snapshot.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="px-6 py-4">
                        <?php
                        $typeColors = [
                            'document' => 'ds-chip--neutral',
                            'folder' => 'ds-chip--neutral',
                            'tag' => 'ds-chip--neutral',
                            'correspondent' => 'ds-chip--neutral',
                            'document_type' => 'ds-chip--neutral'
                        ];
                        ?>
                        <span class="px-2 py-1 text-xs font-medium ds-chip <?= $typeColors[$item['entity_type']] ?? 'ds-chip--neutral' ?>">
                            <?= ucfirst($item['entity_type']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        #<?= $item['entity_id'] ?>
                    </td>
                    <td class="px-6 py-4 text-sm font-medium" style="color:var(--ink)">
                        <?= htmlspecialchars($item['entity_name'] ?? '-') ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <?php if ($item['entity_path']): ?>
                        <code class="text-xs px-2 py-1 rounded" style="background:var(--hover)">
                            <?= htmlspecialchars($item['entity_path']) ?>
                        </code>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
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
    <div class="ds-card rounded-lg shadow-xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold" style="color:var(--ink)">Restaurer le snapshot</h3>
            <button onclick="document.getElementById('restoreModal').classList.add('hidden')" style="color:var(--dim)">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" action="<?= url('/admin/snapshots/' . $snapshot['id'] . '/restore') ?>">
            <div class="space-y-4">
                <p class="text-sm" style="color:var(--ink-soft)">
                    Selectionnez les elements a restaurer:
                </p>
                <div class="space-y-2">
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="documents" checked class="rounded" style="accent-color:var(--accent)">
                        <span style="color:var(--ink)">Documents</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="folders" checked class="rounded" style="accent-color:var(--accent)">
                        <span style="color:var(--ink)">Dossiers</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="tags" class="rounded" style="accent-color:var(--accent)">
                        <span style="color:var(--ink)">Tags</span>
                    </label>
                </div>
                <div class="p-4 rounded-lg" style="background:color-mix(in srgb,var(--amber) 10%,transparent)">
                    <p class="text-sm" style="color:var(--amber)">
                        <strong>Attention:</strong> Cette action va creer de nouvelles versions des elements modifies.
                        Les donnees actuelles ne seront pas perdues.
                    </p>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="document.getElementById('restoreModal').classList.add('hidden')"
                    class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    Restaurer
                </button>
            </div>
        </form>
    </div>
</div>
