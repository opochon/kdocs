<?php
// Formulaire de création/édition de champ personnalisé (Phase 2.1)
?>

<div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">
            <?= $customField ? 'Modifier le champ' : 'Créer un champ personnalisé' ?>
        </h1>
        <a href="<?= url('/admin/custom-fields') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url($customField ? '/admin/custom-fields/' . $customField['id'] . '/save' : '/admin/custom-fields/save') ?>" 
          class="bg-white rounded-lg shadow p-6 space-y-6">
        
        <?php if ($customField): ?>
        <input type="hidden" name="id" value="<?= $customField['id'] ?>">
        <?php endif; ?>

        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom du champ *</label>
            <input type="text" 
                   id="name" 
                   name="name" 
                   value="<?= htmlspecialchars($customField['name'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                   required>
            <p class="text-xs text-gray-500 mt-1">Nom affiché dans l'interface</p>
        </div>

        <div>
            <label for="field_type" class="block text-sm font-medium text-gray-700 mb-1">Type de champ *</label>
            <select id="field_type" 
                    name="field_type"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    onchange="toggleOptionsField()"
                    required>
                <option value="text" <?= ($customField['field_type'] ?? 'text') === 'text' ? 'selected' : '' ?>>Texte</option>
                <option value="number" <?= ($customField['field_type'] ?? '') === 'number' ? 'selected' : '' ?>>Nombre</option>
                <option value="date" <?= ($customField['field_type'] ?? '') === 'date' ? 'selected' : '' ?>>Date</option>
                <option value="boolean" <?= ($customField['field_type'] ?? '') === 'boolean' ? 'selected' : '' ?>>Booléen (Oui/Non)</option>
                <option value="url" <?= ($customField['field_type'] ?? '') === 'url' ? 'selected' : '' ?>>URL</option>
                <option value="email" <?= ($customField['field_type'] ?? '') === 'email' ? 'selected' : '' ?>>Email</option>
                <option value="select" <?= ($customField['field_type'] ?? '') === 'select' ? 'selected' : '' ?>>Liste déroulante</option>
            </select>
        </div>

        <div id="options-field" style="display: <?= ($customField['field_type'] ?? '') === 'select' ? 'block' : 'none' ?>;">
            <label for="options" class="block text-sm font-medium text-gray-700 mb-1">Options (une par ligne)</label>
            <textarea id="options" 
                      name="options"
                      rows="5"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      placeholder="Option 1&#10;Option 2&#10;Option 3"><?php
            if ($customField && $customField['options']) {
                $options = json_decode($customField['options'], true);
                if (is_array($options)) {
                    echo htmlspecialchars(implode("\n", $options));
                }
            }
            ?></textarea>
            <p class="text-xs text-gray-500 mt-1">Pour les champs de type "Liste déroulante", une option par ligne</p>
        </div>

        <div>
            <label class="flex items-center">
                <input type="checkbox" 
                       name="required" 
                       value="1"
                       <?= ($customField['required'] ?? false) ? 'checked' : '' ?>
                       class="mr-2">
                <span class="text-sm text-gray-700">Champ requis</span>
            </label>
        </div>

        <div class="flex items-center justify-between pt-6 border-t">
            <a href="<?= url('/admin/custom-fields') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Enregistrer
            </button>
        </div>
    </form>
</div>

<script>
function toggleOptionsField() {
    const fieldType = document.getElementById('field_type').value;
    const optionsField = document.getElementById('options-field');
    optionsField.style.display = fieldType === 'select' ? 'block' : 'none';
}
</script>
