<?php
// $tags est passé depuis le contrôleur
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Gestion des tags</h1>
        <a href="<?= url('/admin/tags/create') ?>" class="btn btn-primary">
            + Nouveau tag
        </a>
    </div>

    <div class="ds-card shadow overflow-hidden">
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Tag</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Couleur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Match</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Documents</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tags)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center" style="color:var(--dim)">
                        Aucun tag trouvé. <a href="<?= url('/admin/tags/create') ?>" class="hover:underline">Créer le premier</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($tags as $tag): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <span class="inline-block px-3 py-1 text-xs rounded-full mr-2"
                                  style="--_tc:<?= htmlspecialchars($tag['color'] ?? '') ?>; background-color:color-mix(in srgb,var(--_tc,var(--dim)) 20%,transparent); color:var(--_tc,var(--dim))">
                                <?= htmlspecialchars($tag['name']) ?>
                            </span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-6 h-6 rounded border" style="background-color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>; border-color:var(--border)"></div>
                            <span class="ml-2 text-sm" style="color:var(--dim)"><?= htmlspecialchars($tag['color'] ?? '#6b7280') ?></span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($tag['match'] ?? '-') ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs font-medium">
                            <?= (int)$tag['document_count'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/tags/' . $tag['id'] . '/edit') ?>" class="mr-4">Modifier</a>
                        <form method="POST" action="<?= url('/admin/tags/' . $tag['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce tag ? Les associations avec les documents seront également supprimées.')">
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
