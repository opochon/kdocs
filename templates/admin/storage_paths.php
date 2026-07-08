<?php
// Liste des chemins de stockage (Phase 2.2)
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Chemins de stockage</h1>
        <a href="<?= url('/admin/storage-paths/create') ?>" class="btn btn-primary">
            + Créer un chemin
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--red) 10%, transparent); border-color:color-mix(in srgb, var(--red) 35%, var(--border)); color:var(--red)">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($storagePaths)): ?>
    <div class="ds-card shadow p-8 text-center">
        <p class="mb-4" style="color:var(--dim)">Aucun chemin de stockage créé.</p>
        <a href="<?= url('/admin/storage-paths/create') ?>" class="btn btn-primary">
            Créer le premier chemin
        </a>
    </div>
    <?php else: ?>
    <div class="ds-card shadow overflow-hidden">
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Chemin</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Matching</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Algorithme</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($storagePaths as $path): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($path['name']) ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm font-mono" style="color:var(--ink)"><?= htmlspecialchars($path['path']) ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($path['match']): ?>
                        <div class="text-sm" style="color:var(--ink-soft)"><?= htmlspecialchars($path['match']) ?></div>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">
                            <?= htmlspecialchars($path['matching_algorithm']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/storage-paths/' . $path['id'] . '/edit') ?>" class="mr-3">
                            Modifier
                        </a>
                        <form method="POST" action="<?= url('/admin/storage-paths/' . $path['id'] . '/delete') ?>" class="inline"
                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce chemin ?')">
                            <button type="submit" style="color:var(--red)">
                                Supprimer
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
