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
        <h1 class="text-2xl font-bold" style="color:var(--ink)"><?= $isEdit ? 'Modifier' : 'Créer' ?> un tag</h1>
        <a href="<?= url('/admin/tags') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
            ← Retour
        </a>
    </div>

    <div class="rounded-lg shadow p-6" style="background:var(--surface)">
        <form method="POST" action="<?= url('/admin/tags' . ($isEdit ? '/' . $tag['id'] : '') . '/save') ?>">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nom *</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="<?= htmlspecialchars($tag['name'] ?? '') ?>"
                        required
                        class="form-input"
                        placeholder="Ex: Important"
                    >
                </div>

                <div>
                    <label for="color" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Couleur *</label>
                    <div class="flex items-center gap-3">
                        <input
                            type="color"
                            id="color"
                            name="color"
                            value="<?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>"
                            class="w-16 h-10 border rounded cursor-pointer" style="border-color:var(--border)"
                        >
                        <input
                            type="text"
                            id="color-hex"
                            value="<?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>"
                            pattern="^#[0-9A-Fa-f]{6}$"
                            class="form-input flex-1"
                            placeholder="#6b7280"
                        >
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <?php foreach ($defaultColors as $hex => $label): ?>
                        <button
                            type="button"
                            onclick="setColor('<?= $hex ?>')"
                            class="px-3 py-1 text-xs rounded border"
                            style="background-color: <?= $hex ?>20; color: <?= $hex ?>; border-color:var(--border)"
                        >
                            <?= $label ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label for="parent_id" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Tag parent (Phase 3.2)</label>
                    <select id="parent_id"
                            name="parent_id"
                            class="form-select">
                        <option value="">-- Aucun parent (tag racine) --</option>
                        <?php foreach ($allTags ?? [] as $parentTag): ?>
                            <?php if ($parentTag['id'] != ($tag['id'] ?? 0)): ?>
                            <option value="<?= $parentTag['id'] ?>" <?= ($tag['parent_id'] ?? null) == $parentTag['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($parentTag['name']) ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-sm" style="color:var(--dim)">Créer une hiérarchie de tags (max 5 niveaux)</p>
                </div>

                <div>
                    <label for="match" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Expression de correspondance</label>
                    <input
                        type="text"
                        id="match"
                        name="match"
                        value="<?= htmlspecialchars($tag['match'] ?? '') ?>"
                        class="form-input"
                        placeholder="Ex: important|urgent"
                    >
                    <p class="mt-1 text-sm" style="color:var(--dim)">Texte utilisé pour le matching automatique</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="matching_algorithm" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Algorithme</label>
                        <select id="matching_algorithm"
                                name="matching_algorithm"
                                class="form-select">
                            <option value="0" <?= ((int)($tag['matching_algorithm'] ?? 0)) == 0 ? 'selected' : '' ?>>Aucun</option>
                            <option value="1" <?= ((int)($tag['matching_algorithm'] ?? 0)) == 1 ? 'selected' : '' ?>>N'importe lequel</option>
                            <option value="2" <?= ((int)($tag['matching_algorithm'] ?? 0)) == 2 ? 'selected' : '' ?>>Tous</option>
                            <option value="3" <?= ((int)($tag['matching_algorithm'] ?? 0)) == 3 ? 'selected' : '' ?>>Exact</option>
                            <option value="4" <?= ((int)($tag['matching_algorithm'] ?? 0)) == 4 ? 'selected' : '' ?>>Regex</option>
                            <option value="5" <?= ((int)($tag['matching_algorithm'] ?? 0)) == 5 ? 'selected' : '' ?>>Fuzzy</option>
                            <option value="6" <?= ((int)($tag['matching_algorithm'] ?? 0)) == 6 ? 'selected' : '' ?>>Auto (ML)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">&nbsp;</label>
                        <label class="flex items-center h-10 px-3 py-2 border rounded-lg" style="border-color:var(--border)">
                            <input type="checkbox" name="is_insensitive" value="1"
                                   <?= ($tag['is_insensitive'] ?? true) ? 'checked' : '' ?>
                                   class="mr-2" style="accent-color:var(--accent)">
                            <span class="text-sm">Insensible à la casse</span>
                        </label>
                    </div>
                </div>
                <p class="text-sm" style="color:var(--dim)">Algorithme utilisé pour le matching automatique</p>

                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="is_inbox_tag" value="1"
                               <?= ($tag['is_inbox_tag'] ?? false) ? 'checked' : '' ?>
                               class="mr-2" style="accent-color:var(--accent)">
                        <span class="text-sm" style="color:var(--ink-soft)">Est un tag Inbox</span>
                    </label>
                    <p class="mt-1 text-sm" style="color:var(--dim)">Marque les documents non traités</p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t" style="border-color:var(--border)">
                    <a href="<?= url('/admin/tags') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
                        Annuler
                    </a>
                    <button type="submit" class="btn-primary px-4 py-2 rounded-lg">
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
