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
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Documents</h1>
        <a href="<?= url('/documents/upload') ?>" class="btn btn-primary">
            📤 Uploader un document
        </a>
    </div>

    <!-- Filtres et recherche -->
    <div class="ds-card p-4">
        <form method="GET" action="<?= url('/documents') ?>" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="search" class="form-label">Rechercher</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= htmlspecialchars($search ?? '') ?>"
                    placeholder="Titre ou nom de fichier..."
                    class="form-input"
                >
            </div>
            <div class="min-w-[200px]">
                <label for="type_id" class="form-label">Type</label>
                <select
                    id="type_id"
                    name="type_id"
                    class="form-select"
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
                    class="btn btn-secondary"
                >
                    🔍 Filtrer
                </button>
            </div>
            <?php if ($search || $typeId): ?>
                <div>
                    <a href="<?= url('/documents') ?>" class="btn btn-ghost">
                        Réinitialiser
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($success): ?>
        <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--green) 12%, transparent); border-color:color-mix(in srgb, var(--green) 35%, transparent); color:var(--green)">
            Document uploadé avec succès !
        </div>
    <?php endif; ?>

    <?php if (empty($documents)): ?>
        <div class="ds-card p-12 text-center">
            <p class="text-lg mb-4" style="color:var(--dim)">Aucun document pour le moment</p>
            <a href="<?= url('/documents/upload') ?>" class="btn btn-primary">
                Uploader votre premier document
            </a>
        </div>
    <?php else: ?>
        <div class="ds-card overflow-hidden">
            <table class="min-w-full">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Titre</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Fichier</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Taille</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium" style="color:var(--ink)">
                                    <?= htmlspecialchars($doc['title'] ?: $doc['original_filename']) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">
                                    <?= htmlspecialchars($doc['document_type_label'] ?: 'Non défini') ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= htmlspecialchars($doc['original_filename']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= $doc['doc_date'] ? date('d/m/Y', strtotime($doc['doc_date'])) : '-' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= $doc['file_size'] ? number_format($doc['file_size'] / 1024, 2) . ' KB' : '-' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="<?= url('/documents/' . $doc['id']) ?>" style="color:var(--accent)">Voir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between">
                <div class="text-sm" style="color:var(--ink-soft)">
                    Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> documents)
                </div>
                <div class="flex space-x-2">
                    <?php
                    $queryString = '';
                    if ($search) $queryString .= '&search=' . urlencode($search);
                    if ($typeId) $queryString .= '&type_id=' . $typeId;
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="<?= url('/documents?page=' . ($page - 1) . $queryString) ?>" class="btn btn-secondary">
                            Précédent
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('/documents?page=' . ($page + 1) . $queryString) ?>" class="btn btn-secondary">
                            Suivant
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
