<?php
// Formulaire de création/édition de type de document
?>

<div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">
            <?= $documentType ? 'Modifier le type' : 'Créer un type de document' ?>
        </h1>
        <a href="<?= url('/admin/document-types') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour
        </a>
    </div>

    <form method="POST" action="<?= url($documentType ? '/admin/document-types/' . $documentType['id'] . '/save' : '/admin/document-types/save') ?>" 
          class="bg-white rounded-lg shadow p-6 space-y-6">
        
        <?php if ($documentType): ?>
        <input type="hidden" name="id" value="<?= $documentType['id'] ?>">
        <?php endif; ?>

        <div>
            <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Code *</label>
            <input type="text" 
                   id="code" 
                   name="code" 
                   value="<?= htmlspecialchars($documentType['code'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                   required>
            <p class="text-xs text-gray-500 mt-1">Code unique pour identifier le type</p>
        </div>

        <div>
            <label for="label" class="block text-sm font-medium text-gray-700 mb-1">Label *</label>
            <input type="text" 
                   id="label" 
                   name="label" 
                   value="<?= htmlspecialchars($documentType['label'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                   required>
            <p class="text-xs text-gray-500 mt-1">Nom affiché dans l'interface</p>
        </div>

        <div>
            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea id="description" 
                      name="description"
                      rows="3"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"><?= htmlspecialchars($documentType['description'] ?? '') ?></textarea>
        </div>

        <div>
            <label for="retention_days" class="block text-sm font-medium text-gray-700 mb-1">Jours de rétention</label>
            <input type="number" 
                   id="retention_days" 
                   name="retention_days" 
                   value="<?= htmlspecialchars($documentType['retention_days'] ?? '') ?>"
                   min="0"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <p class="text-xs text-gray-500 mt-1">Nombre de jours avant suppression automatique (optionnel)</p>
        </div>

        <div>
            <label for="match" class="block text-sm font-medium text-gray-700 mb-1">Match (pour matching automatique)</label>
            <input type="text" 
                   id="match" 
                   name="match" 
                   value="<?= htmlspecialchars($documentType['match'] ?? '') ?>"
                   placeholder="ex: facture"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <p class="text-xs text-gray-500 mt-1">Texte utilisé pour le matching automatique</p>
        </div>

        <div>
            <label for="matching_algorithm" class="block text-sm font-medium text-gray-700 mb-1">Algorithme de matching</label>
            <select id="matching_algorithm" 
                    name="matching_algorithm"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="none" <?= ($documentType['matching_algorithm'] ?? 'none') === 'none' ? 'selected' : '' ?>>Aucun</option>
                <option value="any" <?= ($documentType['matching_algorithm'] ?? '') === 'any' ? 'selected' : '' ?>>Any (n'importe quel mot)</option>
                <option value="all" <?= ($documentType['matching_algorithm'] ?? '') === 'all' ? 'selected' : '' ?>>All (tous les mots)</option>
                <option value="exact" <?= ($documentType['matching_algorithm'] ?? '') === 'exact' ? 'selected' : '' ?>>Exact (correspondance exacte)</option>
                <option value="regex" <?= ($documentType['matching_algorithm'] ?? '') === 'regex' ? 'selected' : '' ?>>Regex (expression régulière)</option>
                <option value="fuzzy" <?= ($documentType['matching_algorithm'] ?? '') === 'fuzzy' ? 'selected' : '' ?>>Fuzzy (approximatif)</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Algorithme utilisé pour le matching automatique</p>
        </div>

        <div class="flex items-center justify-between pt-6 border-t">
            <a href="<?= url('/admin/document-types') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Enregistrer
            </button>
        </div>
    </form>
</div>
