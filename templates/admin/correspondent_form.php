<?php
// $correspondent est passé depuis le contrôleur (null si création)
$isEdit = !empty($correspondent);
?>

<div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800"><?= $isEdit ? 'Modifier' : 'Créer' ?> un correspondant</h1>
        <a href="<?= url('/admin/correspondents') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="<?= url('/admin/correspondents' . ($isEdit ? '/' . $correspondent['id'] : '') . '/save') ?>">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        value="<?= htmlspecialchars($correspondent['name'] ?? '') ?>"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Ex: ACME Corporation"
                    >
                </div>

                <div>
                    <label for="slug" class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                    <input 
                        type="text" 
                        id="slug" 
                        name="slug" 
                        value="<?= htmlspecialchars($correspondent['slug'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Ex: acme-corporation (généré automatiquement si vide)"
                    >
                    <p class="mt-1 text-sm text-gray-500">Identifiant unique (généré automatiquement si vide)</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="match" class="block text-sm font-medium text-gray-700 mb-1">Match</label>
                        <input 
                            type="text" 
                            id="match" 
                            name="match" 
                            value="<?= htmlspecialchars($correspondent['match'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Texte à rechercher"
                        >
                    </div>
                    <div>
                        <label for="matching_algorithm" class="block text-sm font-medium text-gray-700 mb-1">Algorithme</label>
                        <select id="matching_algorithm" 
                                name="matching_algorithm"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0" <?= ((int)($correspondent['matching_algorithm'] ?? 0)) == 0 ? 'selected' : '' ?>>Aucun</option>
                            <option value="1" <?= ((int)($correspondent['matching_algorithm'] ?? 0)) == 1 ? 'selected' : '' ?>>N'importe lequel</option>
                            <option value="2" <?= ((int)($correspondent['matching_algorithm'] ?? 0)) == 2 ? 'selected' : '' ?>>Tous</option>
                            <option value="3" <?= ((int)($correspondent['matching_algorithm'] ?? 0)) == 3 ? 'selected' : '' ?>>Exact</option>
                            <option value="4" <?= ((int)($correspondent['matching_algorithm'] ?? 0)) == 4 ? 'selected' : '' ?>>Regex</option>
                            <option value="5" <?= ((int)($correspondent['matching_algorithm'] ?? 0)) == 5 ? 'selected' : '' ?>>Fuzzy</option>
                            <option value="6" <?= ((int)($correspondent['matching_algorithm'] ?? 0)) == 6 ? 'selected' : '' ?>>Auto (ML)</option>
                        </select>
                    </div>
                </div>
                <div class="mt-2">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_insensitive" value="1" 
                               <?= ($correspondent['is_insensitive'] ?? true) ? 'checked' : '' ?>>
                        <span class="ml-2 text-sm">Insensible à la casse</span>
                    </label>
                </div>
                <p class="text-sm text-gray-500">Expression pour détecter automatiquement ce correspondant</p>

                <div class="flex items-center justify-end gap-3 pt-4 border-t">
                    <a href="<?= url('/admin/correspondents') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
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
// Générer automatiquement le slug depuis le nom
document.getElementById('name').addEventListener('input', function() {
    const slugInput = document.getElementById('slug');
    if (!slugInput.value || slugInput.dataset.autoGenerated === 'true') {
        const slug = this.value.toLowerCase()
            .trim()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
        slugInput.value = slug;
        slugInput.dataset.autoGenerated = 'true';
    }
});

document.getElementById('slug').addEventListener('input', function() {
    this.dataset.autoGenerated = 'false';
});
</script>
