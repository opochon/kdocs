<?php
// Liste des types de documents
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Types de documents</h1>
        <a href="<?= url('/admin/document-types/create') ?>" class="btn btn-primary">
            + Créer un type
        </a>
    </div>

    <?php if (empty($documentTypes)): ?>
    <div class="ds-card shadow p-8 text-center">
        <p class="mb-4" style="color:var(--dim)">Aucun type de document créé.</p>
        <a href="<?= url('/admin/document-types/create') ?>" class="btn btn-primary">
            Créer le premier type
        </a>
    </div>
    <?php else: ?>
    <div class="ds-card shadow overflow-hidden">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Rapprochement</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Documents</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody>
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
                        <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($type['label']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm" style="color:var(--ink-soft)"><?= htmlspecialchars($algorithmNames[$type['matching_algorithm'] ?? 6] ?? 'Automatique') ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm" style="color:var(--ink)"><?= $type['document_count'] ?></span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/document-types/' . $type['id'] . '/edit') ?>" class="mr-3">
                            Modifier
                        </a>
                        <form method="POST" action="<?= url('/admin/document-types/' . $type['id'] . '/delete') ?>" class="inline"
                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce type ?')">
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
