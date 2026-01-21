<?php
// Liste des champs personnalisés (Phase 2.1)
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Champs personnalisés</h1>
        <a href="<?= url('/admin/custom-fields/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + Créer un champ
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($customFields)): ?>
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-500 mb-4">Aucun champ personnalisé créé.</p>
        <a href="<?= url('/admin/custom-fields/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 inline-block">
            Créer le premier champ
        </a>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requis</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Options</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($customFields as $field): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($field['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                            <?= htmlspecialchars($field['field_type']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($field['required']): ?>
                        <span class="text-red-600">✓</span>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($field['options']): ?>
                        <div class="text-sm text-gray-500">
                            <?php
                            $options = json_decode($field['options'], true);
                            if (is_array($options)) {
                                echo htmlspecialchars(implode(', ', array_slice($options, 0, 3)));
                                if (count($options) > 3) echo '...';
                            }
                            ?>
                        </div>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/custom-fields/' . $field['id'] . '/edit') ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                            Modifier
                        </a>
                        <form method="POST" action="<?= url('/admin/custom-fields/' . $field['id'] . '/delete') ?>" class="inline" 
                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce champ ?')">
                            <button type="submit" class="text-red-600 hover:text-red-900">
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
