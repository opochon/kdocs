<?php
// Liste des champs personnalisés (Phase 2.1)
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Champs personnalisés</h1>
        <a href="<?= url('/admin/custom-fields/create') ?>" class="btn btn-primary">
            + Créer un champ
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--red) 10%, transparent); border-color:color-mix(in srgb, var(--red) 35%, var(--border)); color:var(--red)">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($customFields)): ?>
    <div class="ds-card shadow p-8 text-center">
        <p class="mb-4" style="color:var(--dim)">Aucun champ personnalisé créé.</p>
        <a href="<?= url('/admin/custom-fields/create') ?>" class="btn btn-primary">
            Créer le premier champ
        </a>
    </div>
    <?php else: ?>
    <div class="ds-card shadow overflow-hidden">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Requis</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Options</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customFields as $field): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($field['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">
                            <?= htmlspecialchars($field['field_type']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($field['required']): ?>
                        <span style="color:var(--red)">✓</span>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($field['options']): ?>
                        <div class="text-sm" style="color:var(--dim)">
                            <?php
                            $options = json_decode($field['options'], true);
                            if (is_array($options)) {
                                echo htmlspecialchars(implode(', ', array_slice($options, 0, 3)));
                                if (count($options) > 3) echo '...';
                            }
                            ?>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/custom-fields/' . $field['id'] . '/edit') ?>" class="mr-3">
                            Modifier
                        </a>
                        <form method="POST" action="<?= url('/admin/custom-fields/' . $field['id'] . '/delete') ?>" class="inline"
                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce champ ?')">
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
