<div class="max-w-2xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">
            <?= $field ? 'Modifier le champ' : 'Créer un champ de classification' ?>
        </h1>
        <a href="<?= url('/admin/classification-fields') ?>" class="px-4 py-2 border rounded-lg btn-secondary">
            ← Retour
        </a>
    </div>
    
    <?php if (!empty($_SESSION['flash'])): ?>
    <div class="mb-4 p-4 rounded" style="<?= $_SESSION['flash']['type'] === 'success' ? 'background:color-mix(in srgb, var(--green) 15%, transparent);color:var(--green)' : 'background:color-mix(in srgb, var(--red) 15%, transparent);color:var(--red)' ?>">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>
    
    <form method="POST" action="<?= url($field ? '/admin/classification-fields/' . $field['id'] . '/save' : '/admin/classification-fields/save') ?>" 
          class="rounded-lg shadow p-6 space-y-6" style="background:var(--surface)">
        
        <?php if ($field): ?>
        <input type="hidden" name="id" value="<?= $field['id'] ?>">
        <?php endif; ?>
        
        <div>
            <label for="field_code" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Code du champ *</label>
            <input type="text" id="field_code" name="field_code" 
                   value="<?= htmlspecialchars($field['field_code'] ?? '') ?>"
                   class="form-input" required
                   <?= $field ? 'readonly' : '' ?>>
            <p class="text-xs mt-1" style="color:var(--dim)">Code unique (ex: year, supplier, type). Ne peut pas être modifié après création.</p>
        </div>
        
        <div>
            <label for="field_name" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nom affiché *</label>
            <input type="text" id="field_name" name="field_name" 
                   value="<?= htmlspecialchars($field['field_name'] ?? '') ?>"
                   class="form-input" required>
        </div>
        
        <div>
            <label for="field_type" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Type de champ *</label>
            <select id="field_type" name="field_type" class="form-select" required <?= ($field && !empty($field['is_required'])) ? 'disabled' : '' ?>>
                <option value="year" <?= ($field['field_type'] ?? '') === 'year' ? 'selected' : '' ?>>Année</option>
                <option value="supplier" <?= ($field['field_type'] ?? '') === 'supplier' ? 'selected' : '' ?>>Fournisseur</option>
                <option value="type" <?= ($field['field_type'] ?? '') === 'type' ? 'selected' : '' ?>>Type de document</option>
                <option value="amount" <?= ($field['field_type'] ?? '') === 'amount' ? 'selected' : '' ?>>Montant</option>
                <option value="date" <?= ($field['field_type'] ?? '') === 'date' ? 'selected' : '' ?>>Date</option>
                <option value="custom" <?= ($field['field_type'] ?? '') === 'custom' ? 'selected' : '' ?>>Personnalisé</option>
            </select>
            <?php if ($field && !empty($field['is_required'])): ?>
            <input type="hidden" name="field_type" value="<?= htmlspecialchars($field['field_type']) ?>">
            <p class="text-xs mt-1" style="color:var(--red)">Ce champ est obligatoire, le type ne peut pas être modifié</p>
            <?php endif; ?>
        </div>
        
        <?php if ($field && !empty($field['is_required'])): ?>
        <div class="p-3 border rounded-md" style="background:color-mix(in srgb, var(--amber) 14%, transparent);border-color:color-mix(in srgb, var(--amber) 40%, var(--border))">
            <p class="text-sm" style="color:var(--amber)">
                <strong>⚠️ Champ obligatoire</strong><br>
                Ce champ ne peut pas être supprimé car il est essentiel au fonctionnement du système.
            </p>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="flex items-center">
                    <input type="checkbox" name="is_active" value="1" 
                           <?= ($field['is_active'] ?? true) ? 'checked' : '' ?>
                           class="mr-2" style="accent-color:var(--accent)">
                    <span class="text-sm" style="color:var(--ink-soft)">Champ actif</span>
                </label>
                <p class="text-xs mt-1" style="color:var(--dim)">Utilisé pour la classification</p>
            </div>
            
            <div>
                <label class="flex items-center">
                    <input type="checkbox" name="use_for_storage_path" value="1" 
                           <?= ($field['use_for_storage_path'] ?? false) ? 'checked' : '' ?>
                           class="mr-2" style="accent-color:var(--accent)">
                    <span class="text-sm" style="color:var(--ink-soft)">Utiliser dans le chemin de stockage</span>
                </label>
                <p class="text-xs mt-1" style="color:var(--dim)">Apparaît dans le chemin (ex: 2026/Fournisseurs/ABC)</p>
            </div>
            
            <div>
                <label class="flex items-center">
                    <input type="checkbox" name="use_for_tag" value="1" 
                           <?= ($field['use_for_tag'] ?? false) ? 'checked' : '' ?>
                           class="mr-2" style="accent-color:var(--accent)">
                    <span class="text-sm" style="color:var(--ink-soft)">Créer un tag automatiquement</span>
                </label>
                <p class="text-xs mt-1" style="color:var(--dim)">Si détecté, crée un tag</p>
            </div>
            
            <div>
                <label for="storage_path_position" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Position dans le chemin</label>
                <input type="number" id="storage_path_position" name="storage_path_position" 
                       value="<?= htmlspecialchars($field['storage_path_position'] ?? '') ?>"
                       min="1" max="10"
                       class="form-input">
                <p class="text-xs mt-1" style="color:var(--dim)">1=premier niveau, 2=deuxième, etc.</p>
            </div>
        </div>
        
        <!-- Méthode de matching -->
        <div class="border-t pt-4" style="border-color:var(--border)">
            <h3 class="text-sm font-semibold mb-3" style="color:var(--ink-soft)">Méthode de détection</h3>
            
            <div class="mb-4">
                <label class="flex items-center">
                    <input type="checkbox" name="use_ai" value="1" id="use_ai_checkbox"
                           <?= ($field['use_ai'] ?? false) ? 'checked' : '' ?>
                           class="mr-2" style="accent-color:var(--accent)" onchange="toggleMatchingMethod()">
                    <span class="text-sm font-medium" style="color:var(--ink-soft)">Utiliser l'IA (Claude) si disponible</span>
                </label>
                <p class="text-xs mt-1 ml-6" style="color:var(--dim)">Si coché, utilise Claude avec un prompt personnalisé. Sinon, utilise les mots-clés.</p>
            </div>
            
            <!-- Section Mots-clés (masquée si IA activée) -->
            <div id="keywords_section" style="display: <?= ($field['use_ai'] ?? false) ? 'none' : 'block' ?>;">
                <div class="mb-4">
                    <label for="matching_keywords" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Mots-clés pour matching</label>
                    <textarea id="matching_keywords" name="matching_keywords" rows="3"
                              class="form-textarea"
                              placeholder="Ex: facture, invoice, rechnung (séparés par virgule)"><?= htmlspecialchars($field['matching_keywords'] ?? '') ?></textarea>
                    <p class="text-xs mt-1" style="color:var(--dim)">Mots-clés séparés par virgule pour détecter automatiquement ce champ</p>
                </div>
                
                <div>
                    <label for="matching_algorithm" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Algorithme de matching</label>
                    <select id="matching_algorithm" name="matching_algorithm" class="form-select">
                        <option value="any" <?= ($field['matching_algorithm'] ?? 'any') === 'any' ? 'selected' : '' ?>>N'importe quel mot-clé (any)</option>
                        <option value="all" <?= ($field['matching_algorithm'] ?? '') === 'all' ? 'selected' : '' ?>>Tous les mots-clés (all)</option>
                        <option value="literal" <?= ($field['matching_algorithm'] ?? '') === 'literal' ? 'selected' : '' ?>>Littéral</option>
                        <option value="regex" <?= ($field['matching_algorithm'] ?? '') === 'regex' ? 'selected' : '' ?>>Expression régulière</option>
                        <option value="fuzzy" <?= ($field['matching_algorithm'] ?? '') === 'fuzzy' ? 'selected' : '' ?>>Approximatif (fuzzy)</option>
                    </select>
                </div>
            </div>
            
            <!-- Section IA (affichée si IA activée) -->
            <div id="ai_section" style="display: <?= ($field['use_ai'] ?? false) ? 'block' : 'none' ?>;">
                <div>
                    <label for="ai_prompt" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Prompt pour l'IA (Claude)</label>
                    <textarea id="ai_prompt" name="ai_prompt" rows="6"
                              class="form-textarea font-mono"
                              placeholder="Ex: Extrais le type de document depuis le texte. Réponds uniquement avec le type (ex: Facture, Note de crédit, Contrat)."><?= htmlspecialchars($field['ai_prompt'] ?? '') ?></textarea>
                    <p class="text-xs mt-1" style="color:var(--dim)">
                        Prompt personnalisé pour guider Claude. Le texte du document sera automatiquement ajouté.
                        <br>Variables disponibles: <code>{field_name}</code>, <code>{field_type}</code>
                    </p>
                </div>
                
                <div class="mt-3 p-3 border rounded-md" style="background:var(--app-bg);border-color:var(--border)">
                    <p class="text-xs" style="color:var(--ink-soft)">
                        <strong>💡 Exemple de prompt:</strong><br>
                        "Extrais le <?= htmlspecialchars($field['field_name'] ?? 'champ') ?> depuis le texte du document. 
                        Réponds uniquement avec la valeur extraite, sans explication."
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        function toggleMatchingMethod() {
            const useAI = document.getElementById('use_ai_checkbox').checked;
            document.getElementById('keywords_section').style.display = useAI ? 'none' : 'block';
            document.getElementById('ai_section').style.display = useAI ? 'block' : 'none';
        }
        </script>
        
        <div class="flex gap-3 pt-4 border-t" style="border-color:var(--border)">
            <button type="submit" class="px-6 py-2 rounded-md btn-primary">
                Enregistrer
            </button>
            <a href="<?= url('/admin/classification-fields') ?>" class="px-4 py-2 border rounded-md btn-secondary">
                Annuler
            </a>
        </div>
    </form>
</div>
