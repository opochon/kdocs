<?php
// $tags est passé depuis le contrôleur
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Gestion des tags</h1>
        <a href="<?= url('/admin/tags/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + Nouveau tag
        </a>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tag</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Couleur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Documents</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php if (empty($tags)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                        Aucun tag trouvé. <a href="<?= url('/admin/tags/create') ?>" class="text-blue-600 hover:underline">Créer le premier</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($tags as $tag): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <span class="inline-block px-3 py-1 text-xs rounded-full mr-2"
                                  style="background-color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>20; color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>">
                                <?= htmlspecialchars($tag['name']) ?>
                            </span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-6 h-6 rounded border border-gray-300" style="background-color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>"></div>
                            <span class="ml-2 text-sm text-gray-500"><?= htmlspecialchars($tag['color'] ?? '#6b7280') ?></span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500"><?= htmlspecialchars($tag['match'] ?? '-') ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                            <?= (int)$tag['document_count'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/tags/' . $tag['id'] . '/edit') ?>" class="text-blue-600 hover:text-blue-900 mr-4">Modifier</a>
                        <form method="POST" action="<?= url('/admin/tags/' . $tag['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce tag ? Les associations avec les documents seront également supprimées.')">
                            <button type="submit" class="text-red-600 hover:text-red-900">Supprimer</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
