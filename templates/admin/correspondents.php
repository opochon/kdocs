<?php
// $correspondents est passé depuis le contrôleur
$error = $_GET['error'] ?? '';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Gestion des correspondants</h1>
        <a href="<?= url('/admin/correspondents/create') ?>" class="btn btn-primary">
            + Nouveau correspondant
        </a>
    </div>

    <?php if ($error === 'has_documents'): ?>
    <div class="px-4 py-3 rounded" style="background:color-mix(in srgb,var(--red) 12%,transparent);border:1px solid color-mix(in srgb,var(--red) 45%,var(--border));color:var(--red)">
        ⚠️ Impossible de supprimer ce correspondant car il est associé à des documents.
    </div>
    <?php endif; ?>

    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Slug</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Match</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Documents</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($correspondents)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center" style="color:var(--dim)">
                        Aucun correspondant trouvé. <a href="<?= url('/admin/correspondents/create') ?>" class="hover:underline" style="color:var(--accent)">Créer le premier</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($correspondents as $correspondent): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($correspondent['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($correspondent['slug'] ?? '-') ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($correspondent['match'] ?? '-') ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs font-medium">
                            <?= (int)$correspondent['document_count'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/correspondents/' . $correspondent['id'] . '/edit') ?>" class="mr-4" style="color:var(--accent)">Modifier</a>
                        <form method="POST" action="<?= url('/admin/correspondents/' . $correspondent['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce correspondant ?')">
                            <button type="submit" style="color:var(--red)">Supprimer</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
