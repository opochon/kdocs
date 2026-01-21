<?php
// $correspondents est passé depuis le contrôleur
$error = $_GET['error'] ?? '';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Gestion des correspondants</h1>
        <a href="<?= url('/admin/correspondents/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + Nouveau correspondant
        </a>
    </div>

    <?php if ($error === 'has_documents'): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
        ⚠️ Impossible de supprimer ce correspondant car il est associé à des documents.
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Slug</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Documents</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php if (empty($correspondents)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                        Aucun correspondant trouvé. <a href="<?= url('/admin/correspondents/create') ?>" class="text-blue-600 hover:underline">Créer le premier</a>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($correspondents as $correspondent): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($correspondent['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500"><?= htmlspecialchars($correspondent['slug'] ?? '-') ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500"><?= htmlspecialchars($correspondent['match'] ?? '-') ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                            <?= (int)$correspondent['document_count'] ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/correspondents/' . $correspondent['id'] . '/edit') ?>" class="text-blue-600 hover:text-blue-900 mr-4">Modifier</a>
                        <form method="POST" action="<?= url('/admin/correspondents/' . $correspondent['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce correspondant ?')">
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
