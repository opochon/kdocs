<?php
// $documents, $page, $totalPages, $total sont passés depuis le contrôleur
$success = $_GET['success'] ?? null;
?>

<?php
// $documents, $page, $totalPages, $total, $search, $typeId, $documentTypes sont passés depuis le contrôleur
$success = $_GET['success'] ?? null;
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Documents</h1>
        <a href="<?= url('/documents/upload') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            📤 Uploader un document
        </a>
    </div>

    <!-- Filtres et recherche -->
    <div class="bg-white rounded-lg shadow p-4">
        <form method="GET" action="<?= url('/documents') ?>" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Rechercher</label>
                <input 
                    type="text" 
                    id="search" 
                    name="search" 
                    value="<?= htmlspecialchars($search ?? '') ?>"
                    placeholder="Titre ou nom de fichier..."
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
            </div>
            <div class="min-w-[200px]">
                <label for="type_id" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select 
                    id="type_id" 
                    name="type_id"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">Tous les types</option>
                    <?php foreach ($documentTypes as $type): ?>
                        <option value="<?= $type['id'] ?>" <?= ($typeId == $type['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button 
                    type="submit"
                    class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"
                >
                    🔍 Filtrer
                </button>
            </div>
            <?php if ($search || $typeId): ?>
                <div>
                    <a href="<?= url('/documents') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Réinitialiser
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded">
            Document uploadé avec succès !
        </div>
    <?php endif; ?>

    <?php if (empty($documents)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <p class="text-gray-500 text-lg mb-4">Aucun document pour le moment</p>
            <a href="<?= url('/documents/upload') ?>" class="inline-block px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Uploader votre premier document
            </a>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Titre</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fichier</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Taille</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($doc['title'] ?: $doc['original_filename']) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded">
                                    <?= htmlspecialchars($doc['document_type_label'] ?: 'Non défini') ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($doc['original_filename']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $doc['doc_date'] ? date('d/m/Y', strtotime($doc['doc_date'])) : '-' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $doc['file_size'] ? number_format($doc['file_size'] / 1024, 2) . ' KB' : '-' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="<?= url('/documents/' . $doc['id']) ?>" class="text-blue-600 hover:text-blue-900">Voir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> documents)
                </div>
                <div class="flex space-x-2">
                    <?php
                    $queryString = '';
                    if ($search) $queryString .= '&search=' . urlencode($search);
                    if ($typeId) $queryString .= '&type_id=' . $typeId;
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="<?= url('/documents?page=' . ($page - 1) . $queryString) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Précédent
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('/documents?page=' . ($page + 1) . $queryString) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Suivant
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
