<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Champs de Classification</h1>
        <a href="<?= url('/admin/classification-fields/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
            + Nouveau champ
        </a>
    </div>
    
    <?php if (!empty($_SESSION['flash'])): ?>
    <div class="mb-4 p-4 rounded <?= $_SESSION['flash']['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>
    
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b">
            <h2 class="font-medium">Champs configurés (<?= count($fields) ?>)</h2>
        </div>
        
        <?php if (empty($fields)): ?>
        <div class="p-8 text-center text-gray-500">
            Aucun champ configuré. Créez votre premier champ pour commencer.
        </div>
        <?php else: ?>
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Code</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Nom</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Actif</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Obligatoire</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Stockage</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Position</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Tag</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Méthode</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($fields as $field): ?>
                <tr>
                    <td class="px-4 py-3 text-sm font-mono"><?= htmlspecialchars($field['field_code']) ?></td>
                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($field['field_name']) ?></td>
                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($field['field_type']) ?></td>
                    <td class="px-4 py-3 text-sm">
                        <?php if ($field['is_active']): ?>
                        <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs">✓ Actif</span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded text-xs">○ Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if (!empty($field['is_required'])): ?>
                        <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-xs">🔒 Oui</span>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if ($field['use_for_storage_path']): ?>
                        <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs">✓ Oui</span>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm"><?= $field['storage_path_position'] ?? '-' ?></td>
                    <td class="px-4 py-3 text-sm">
                        <?php if ($field['use_for_tag']): ?>
                        <span class="px-2 py-1 bg-purple-100 text-purple-800 rounded text-xs">✓ Oui</span>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if (!empty($field['use_ai'])): ?>
                        <span class="px-2 py-1 bg-indigo-100 text-indigo-800 rounded text-xs" title="Prompt: <?= htmlspecialchars(substr($field['ai_prompt'] ?? '', 0, 50)) ?>...">🤖 IA</span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded text-xs">🔑 Mots-clés</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <?php if (!empty($field['is_required'])): ?>
                        <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-xs" title="Ce champ est obligatoire">🔒 Obligatoire</span>
                        <?php endif; ?>
                        <a href="<?= url('/admin/classification-fields/' . $field['id'] . '/edit') ?>" class="text-blue-600 hover:underline">Modifier</a>
                        <?php if (empty($field['is_required'])): ?>
                        <form method="POST" action="<?= url('/admin/classification-fields/' . $field['id'] . '/delete') ?>" class="inline ml-2" onsubmit="return confirm('Supprimer ce champ ?');">
                            <button type="submit" class="text-red-600 hover:underline">Supprimer</button>
                        </form>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs ml-2">(non supprimable)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="font-semibold text-blue-900 mb-2">💡 Comment ça fonctionne ?</h3>
        <ul class="text-sm text-blue-800 space-y-1">
            <li>• Les champs <strong>actifs</strong> sont utilisés pour la classification automatique</li>
            <li>• Les champs avec <strong>Stockage</strong> apparaissent dans le chemin de stockage (ex: 2026/Fournisseurs/ABC/Factures)</li>
            <li>• La <strong>Position</strong> détermine l'ordre dans le chemin (1=premier niveau, 2=deuxième, etc.)</li>
            <li>• Les champs avec <strong>Tag</strong> créent automatiquement un tag si détectés</li>
        </ul>
    </div>
</div>
