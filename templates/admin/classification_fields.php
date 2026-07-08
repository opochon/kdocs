<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Champs de Classification</h1>
        <a href="<?= url('/admin/classification-fields/create') ?>" class="btn btn-primary">
            + Nouveau champ
        </a>
    </div>

    <?php if (!empty($_SESSION['flash'])): ?>
    <div class="mb-4 p-4 rounded" style="<?= $_SESSION['flash']['type'] === 'success' ? 'background:color-mix(in srgb, var(--green) 14%, transparent); color:var(--green)' : 'background:color-mix(in srgb, var(--red) 14%, transparent); color:var(--red)' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>

    <div class="ds-card shadow">
        <div class="px-4 py-3 border-b">
            <h2 class="font-medium">Champs configurés (<?= count($fields) ?>)</h2>
        </div>

        <?php if (empty($fields)): ?>
        <div class="p-8 text-center" style="color:var(--dim)">
            Aucun champ configuré. Créez votre premier champ pour commencer.
        </div>
        <?php else: ?>
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium">Code</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Nom</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Actif</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Obligatoire</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Stockage</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Position</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Tag</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Méthode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fields as $field): ?>
                <tr>
                    <td class="px-4 py-3 text-sm font-mono"><?= htmlspecialchars($field['field_code']) ?></td>
                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($field['field_name']) ?></td>
                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($field['field_type']) ?></td>
                    <td class="px-4 py-3 text-sm">
                        <?php if ($field['is_active']): ?>
                        <span class="ds-chip ds-chip--green px-2 py-1 text-xs">✓ Actif</span>
                        <?php else: ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">○ Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if (!empty($field['is_required'])): ?>
                        <span class="ds-chip ds-chip--red px-2 py-1 text-xs">🔒 Oui</span>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if ($field['use_for_storage_path']): ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">✓ Oui</span>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm"><?= $field['storage_path_position'] ?? '-' ?></td>
                    <td class="px-4 py-3 text-sm">
                        <?php if ($field['use_for_tag']): ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">✓ Oui</span>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if (!empty($field['use_ai'])): ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs" title="Prompt: <?= htmlspecialchars(substr($field['ai_prompt'] ?? '', 0, 50)) ?>...">🤖 IA</span>
                        <?php else: ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">🔑 Mots-clés</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if (!empty($field['is_required'])): ?>
                        <span class="ds-chip ds-chip--red px-2 py-1 text-xs" title="Ce champ est obligatoire">🔒 Obligatoire</span>
                        <?php endif; ?>
                        <a href="<?= url('/admin/classification-fields/' . $field['id'] . '/edit') ?>" class="hover:underline">Modifier</a>
                        <?php if (empty($field['is_required'])): ?>
                        <form method="POST" action="<?= url('/admin/classification-fields/' . $field['id'] . '/delete') ?>" class="inline ml-2" onsubmit="return confirm('Supprimer ce champ ?');">
                            <button type="submit" class="hover:underline" style="color:var(--red)">Supprimer</button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs ml-2" style="color:var(--dim)">(non supprimable)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="mt-6 border rounded-lg p-4" style="background:var(--accent-soft); border-color:color-mix(in srgb, var(--accent) 25%, var(--border))">
        <h3 class="font-semibold mb-2">💡 Comment ça fonctionne ?</h3>
        <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
            <li>• Les champs <strong>actifs</strong> sont utilisés pour la classification automatique</li>
            <li>• Les champs avec <strong>Stockage</strong> apparaissent dans le chemin de stockage (ex: 2026/Fournisseurs/ABC/Factures)</li>
            <li>• La <strong>Position</strong> détermine l'ordre dans le chemin (1=premier niveau, 2=deuxième, etc.)</li>
            <li>• Les champs avec <strong>Tag</strong> créent automatiquement un tag si détectés</li>
        </ul>
    </div>
</div>
