<?php
// $tag est passé depuis le contrôleur (null si création)
$isEdit = !empty($tag);
$defaultColors = [
    '#6b7280' => 'Gris',
    '#ef4444' => 'Rouge',
    '#f59e0b' => 'Orange',
    '#10b981' => 'Vert',
    '#3b82f6' => 'Bleu',
    '#8b5cf6' => 'Violet',
    '#ec4899' => 'Rose',
];
?>

<div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800"><?= $isEdit ? 'Modifier' : 'Créer' ?> un tag</h1>
        <a href="<?= url('/admin/tags') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="<?= url('/admin/tags' . ($isEdit ? '/' . $tag['id'] : '') . '/save') ?>">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        value="<?= htmlspecialchars($tag['name'] ?? '') ?>"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Ex: Important"
                    >
                </div>

                <div>
                    <label for="color" class="block text-sm font-medium text-gray-700 mb-1">Couleur *</label>
                    <div class="flex items-center gap-3">
                        <input 
                            type="color" 
                            id="color" 
                            name="color" 
                            value="<?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>"
                            class="w-16 h-10 border border-gray-300 rounded cursor-pointer"
                        >
                        <input 
                            type="text" 
                            id="color-hex" 
                            value="<?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>"
                            pattern="^#[0-9A-Fa-f]{6}$"
                            class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="#6b7280"
                        >
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <?php foreach ($defaultColors as $hex => $label): ?>
                        <button 
                            type="button"
                            onclick="setColor('<?= $hex ?>')"
                            class="px-3 py-1 text-xs rounded border border-gray-300 hover:border-gray-400"
                            style="background-color: <?= $hex ?>20; color: <?= $hex ?>"
                        >
                            <?= $label ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label for="parent_id" class="block text-sm font-medium text-gray-700 mb-1">Tag parent (Phase 3.2)</label>
                    <select id="parent_id" 
                            name="parent_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Aucun parent (tag racine) --</option>
                        <?php foreach ($allTags ?? [] as $parentTag): ?>
                            <?php if ($parentTag['id'] != ($tag['id'] ?? 0)): ?>
                            <option value="<?= $parentTag['id'] ?>" <?= ($tag['parent_id'] ?? null) == $parentTag['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($parentTag['name']) ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">Créer une hiérarchie de tags (max 5 niveaux)</p>
                </div>
                
                <div>
                    <label for="match" class="block text-sm font-medium text-gray-700 mb-1">Expression de correspondance</label>
                    <input 
                        type="text" 
                        id="match" 
                        name="match" 
                        value="<?= htmlspecialchars($tag['match'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Ex: important|urgent"
                    >
                    <p class="mt-1 text-sm text-gray-500">Texte utilisé pour le matching automatique</p>
                </div>
                
                <div>
                    <label for="matching_algorithm" class="block text-sm font-medium text-gray-700 mb-1">Algorithme de matching</label>
                    <select id="matching_algorithm" 
                            name="matching_algorithm"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="none" <?= ($tag['matching_algorithm'] ?? 'none') === 'none' ? 'selected' : '' ?>>Aucun</option>
                        <option value="any" <?= ($tag['matching_algorithm'] ?? '') === 'any' ? 'selected' : '' ?>>Any (n'importe quel mot)</option>
                        <option value="all" <?= ($tag['matching_algorithm'] ?? '') === 'all' ? 'selected' : '' ?>>All (tous les mots)</option>
                        <option value="exact" <?= ($tag['matching_algorithm'] ?? '') === 'exact' ? 'selected' : '' ?>>Exact (correspondance exacte)</option>
                        <option value="regex" <?= ($tag['matching_algorithm'] ?? '') === 'regex' ? 'selected' : '' ?>>Regex (expression régulière)</option>
                        <option value="fuzzy" <?= ($tag['matching_algorithm'] ?? '') === 'fuzzy' ? 'selected' : '' ?>>Fuzzy (approximatif)</option>
                    </select>
                    <p class="mt-1 text-sm text-gray-500">Algorithme utilisé pour le matching automatique</p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t">
                    <a href="<?= url('/admin/tags') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Annuler
                    </a>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <?= $isEdit ? 'Enregistrer' : 'Créer' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const colorInput = document.getElementById('color');
const colorHexInput = document.getElementById('color-hex');

colorInput.addEventListener('input', function() {
    colorHexInput.value = this.value;
});

colorHexInput.addEventListener('input', function() {
    if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
        colorInput.value = this.value;
    }
});

function setColor(hex) {
    colorInput.value = hex;
    colorHexInput.value = hex;
}
</script>
