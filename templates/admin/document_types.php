<?php
// Liste des types de documents
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Types de documents</h1>
        <a href="<?= url('/admin/document-types/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + Créer un type
        </a>
    </div>

    <?php if (empty($documentTypes)): ?>
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-500 mb-4">Aucun type de document créé.</p>
        <a href="<?= url('/admin/document-types/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 inline-block">
            Créer le premier type
        </a>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rapprochement</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Documents</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php 
                $algorithmNames = [
                    0 => 'Aucun',
                    1 => 'Any',
                    2 => 'All',
                    3 => 'Exact',
                    4 => 'Regex',
                    5 => 'Fuzzy',
                    6 => 'Automatique'
                ];
                foreach ($documentTypes as $type): 
                ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($type['label']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm text-gray-600"><?= htmlspecialchars($algorithmNames[$type['matching_algorithm'] ?? 6] ?? 'Automatique') ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm text-gray-900"><?= $type['document_count'] ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/document-types/' . $type['id'] . '/edit') ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                            Modifier
                        </a>
                        <form method="POST" action="<?= url('/admin/document-types/' . $type['id'] . '/delete') ?>" class="inline" 
                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce type ?')">
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
