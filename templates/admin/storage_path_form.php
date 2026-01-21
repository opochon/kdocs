<?php
// Formulaire de création/édition de chemin de stockage (Phase 2.2)
?>

<div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">
            <?= $storagePath ? 'Modifier le chemin' : 'Créer un chemin de stockage' ?>
        </h1>
        <a href="<?= url('/admin/storage-paths') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url($storagePath ? '/admin/storage-paths/' . $storagePath['id'] . '/save' : '/admin/storage-paths/save') ?>" 
          class="bg-white rounded-lg shadow p-6 space-y-6">
        
        <?php if ($storagePath): ?>
        <input type="hidden" name="id" value="<?= $storagePath['id'] ?>">
        <?php endif; ?>

        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
            <input type="text" 
                   id="name" 
                   name="name" 
                   value="<?= htmlspecialchars($storagePath['name'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                   required>
            <p class="text-xs text-gray-500 mt-1">Nom affiché dans l'interface</p>
        </div>

        <div>
            <label for="path" class="block text-sm font-medium text-gray-700 mb-1">Chemin relatif *</label>
            <input type="text" 
                   id="path" 
                   name="path" 
                   value="<?= htmlspecialchars($storagePath['path'] ?? '') ?>"
                   placeholder="ex: factures/2024"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                   required>
            <p class="text-xs text-gray-500 mt-1">Chemin relatif dans le filesystem (sans slash initial)</p>
        </div>

        <div>
            <label for="match" class="block text-sm font-medium text-gray-700 mb-1">Texte de correspondance</label>
            <input type="text" 
                   id="match" 
                   name="match" 
                   value="<?= htmlspecialchars($storagePath['match'] ?? '') ?>"
                   placeholder="ex: facture"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <p class="text-xs text-gray-500 mt-1">Texte utilisé pour le matching automatique (optionnel)</p>
        </div>

        <div>
            <label for="matching_algorithm" class="block text-sm font-medium text-gray-700 mb-1">Algorithme de matching</label>
            <select id="matching_algorithm" 
                    name="matching_algorithm"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="none" <?= ($storagePath['matching_algorithm'] ?? 'none') === 'none' ? 'selected' : '' ?>>Aucun</option>
                <option value="any" <?= ($storagePath['matching_algorithm'] ?? '') === 'any' ? 'selected' : '' ?>>Any (n'importe quel mot)</option>
                <option value="all" <?= ($storagePath['matching_algorithm'] ?? '') === 'all' ? 'selected' : '' ?>>All (tous les mots)</option>
                <option value="exact" <?= ($storagePath['matching_algorithm'] ?? '') === 'exact' ? 'selected' : '' ?>>Exact (correspondance exacte)</option>
                <option value="regex" <?= ($storagePath['matching_algorithm'] ?? '') === 'regex' ? 'selected' : '' ?>>Regex (expression régulière)</option>
                <option value="fuzzy" <?= ($storagePath['matching_algorithm'] ?? '') === 'fuzzy' ? 'selected' : '' ?>>Fuzzy (approximatif)</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Algorithme utilisé pour le matching automatique</p>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h3 class="font-semibold text-blue-900 mb-2">💡 Aide</h3>
            <ul class="text-sm text-blue-800 space-y-1">
                <li>• Le chemin doit être relatif au dossier racine des documents</li>
                <li>• Le matching automatique assigne ce chemin aux documents correspondants</li>
                <li>• Utilisez "Any" pour correspondre à n'importe quel mot du texte de correspondance</li>
                <li>• Utilisez "All" pour exiger tous les mots</li>
            </ul>
        </div>

        <div class="flex items-center justify-between pt-6 border-t">
            <a href="<?= url('/admin/storage-paths') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Enregistrer
            </button>
        </div>
    </form>
</div>
