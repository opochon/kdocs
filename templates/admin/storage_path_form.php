<?php
// Formulaire de création/édition de chemin de stockage (Phase 2.2)
?>

<div class="max-w-2xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">
            <?= $storagePath ? 'Modifier le chemin' : 'Créer un chemin de stockage' ?>
        </h1>
        <a href="<?= url('/admin/storage-paths') ?>" class="px-4 py-2 border rounded-lg btn-secondary">
            ← Retour
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--red) 12%, transparent);border-color:color-mix(in srgb, var(--red) 40%, var(--border));color:var(--red)">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url($storagePath ? '/admin/storage-paths/' . $storagePath['id'] . '/save' : '/admin/storage-paths/save') ?>" 
          class="rounded-lg shadow p-6 space-y-6" style="background:var(--surface)">
        
        <?php if ($storagePath): ?>
        <input type="hidden" name="id" value="<?= $storagePath['id'] ?>">
        <?php endif; ?>

        <div>
            <label for="name" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nom *</label>
            <input type="text" 
                   id="name" 
                   name="name" 
                   value="<?= htmlspecialchars($storagePath['name'] ?? '') ?>"
                   class="form-input"
                   required>
            <p class="text-xs mt-1" style="color:var(--dim)">Nom affiché dans l'interface</p>
        </div>

        <div>
            <label for="path" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Chemin relatif *</label>
            <input type="text" 
                   id="path" 
                   name="path" 
                   value="<?= htmlspecialchars($storagePath['path'] ?? '') ?>"
                   placeholder="ex: factures/2024"
                   class="form-input font-mono"
                   required>
            <p class="text-xs mt-1" style="color:var(--dim)">Chemin relatif dans le filesystem (sans slash initial)</p>
        </div>

        <div>
            <label for="match" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Texte de correspondance</label>
            <input type="text" 
                   id="match" 
                   name="match" 
                   value="<?= htmlspecialchars($storagePath['match'] ?? '') ?>"
                   placeholder="ex: facture"
                   class="form-input">
            <p class="text-xs mt-1" style="color:var(--dim)">Texte utilisé pour le matching automatique (optionnel)</p>
        </div>

        <div>
            <label for="matching_algorithm" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Algorithme de matching</label>
            <select id="matching_algorithm" 
                    name="matching_algorithm"
                    class="form-select">
                <option value="none" <?= ($storagePath['matching_algorithm'] ?? 'none') === 'none' ? 'selected' : '' ?>>Aucun</option>
                <option value="any" <?= ($storagePath['matching_algorithm'] ?? '') === 'any' ? 'selected' : '' ?>>Any (n'importe quel mot)</option>
                <option value="all" <?= ($storagePath['matching_algorithm'] ?? '') === 'all' ? 'selected' : '' ?>>All (tous les mots)</option>
                <option value="exact" <?= ($storagePath['matching_algorithm'] ?? '') === 'exact' ? 'selected' : '' ?>>Exact (correspondance exacte)</option>
                <option value="regex" <?= ($storagePath['matching_algorithm'] ?? '') === 'regex' ? 'selected' : '' ?>>Regex (expression régulière)</option>
                <option value="fuzzy" <?= ($storagePath['matching_algorithm'] ?? '') === 'fuzzy' ? 'selected' : '' ?>>Fuzzy (approximatif)</option>
            </select>
            <p class="text-xs mt-1" style="color:var(--dim)">Algorithme utilisé pour le matching automatique</p>
        </div>

        <div class="border rounded-lg p-4" style="background:var(--app-bg);border-color:var(--border)">
            <h3 class="font-semibold mb-2" style="color:var(--ink)">💡 Aide</h3>
            <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
                <li>• Le chemin doit être relatif au dossier racine des documents</li>
                <li>• Le matching automatique assigne ce chemin aux documents correspondants</li>
                <li>• Utilisez "Any" pour correspondre à n'importe quel mot du texte de correspondance</li>
                <li>• Utilisez "All" pour exiger tous les mots</li>
            </ul>
        </div>

        <div class="flex items-center justify-between pt-6 border-t" style="border-color:var(--border)">
            <a href="<?= url('/admin/storage-paths') ?>" class="px-4 py-2 border rounded-lg btn-secondary">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 rounded-lg btn-primary">
                Enregistrer
            </button>
        </div>
    </form>
</div>
