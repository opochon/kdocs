<?php
// Formulaire d'édition de document (Priorité 1.2)
use KDocs\Core\Config;
$base = Config::basePath();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Modifier le document</h1>
        <a href="<?= url('/documents/' . $document['id']) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/documents/' . $document['id'] . '/edit') ?>" class="bg-white rounded-lg shadow p-6 space-y-6">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- ASN (Phase 2.3) -->
            <div>
                <label for="asn" class="block text-sm font-medium text-gray-700 mb-1">ASN (Archive Serial Number)</label>
                <input type="number" 
                       id="asn" 
                       name="asn" 
                       value="<?= htmlspecialchars($document['asn'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Généré automatiquement">
                <p class="text-xs text-gray-500 mt-1">Numéro de série d'archive pour documents physiques</p>
            </div>
            
            <!-- Titre -->
            <div>
                <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Titre *</label>
                <input type="text" 
                       id="title" 
                       name="title" 
                       value="<?= htmlspecialchars($document['title'] ?? '') ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       required>
            </div>

            <!-- Type de document -->
            <div>
                <label for="document_type_id" class="block text-sm font-medium text-gray-700 mb-1">Type de document</label>
                <select id="document_type_id" 
                        name="document_type_id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Aucun --</option>
                    <?php foreach ($documentTypes as $type): ?>
                    <option value="<?= $type['id'] ?>" <?= ($document['document_type_id'] ?? null) == $type['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($type['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Correspondant -->
            <div>
                <label for="correspondent_id" class="block text-sm font-medium text-gray-700 mb-1">Correspondant</label>
                <select id="correspondent_id" 
                        name="correspondent_id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Aucun --</option>
                    <?php foreach ($correspondents as $corr): ?>
                    <option value="<?= $corr['id'] ?>" <?= ($document['correspondent_id'] ?? null) == $corr['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($corr['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Date du document -->
            <div>
                <label for="document_date" class="block text-sm font-medium text-gray-700 mb-1">Date du document</label>
                <input type="date" 
                       id="document_date" 
                       name="document_date" 
                       value="<?= htmlspecialchars($document['document_date'] ?? ($document['doc_date'] ?? '')) ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <!-- Montant -->
            <div>
                <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Montant</label>
                <div class="flex">
                    <input type="number" 
                           id="amount" 
                           name="amount" 
                           step="0.01"
                           value="<?= htmlspecialchars($document['amount'] ?? '') ?>"
                           class="flex-1 px-3 py-2 border border-gray-300 rounded-l-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <select name="currency" 
                            class="px-3 py-2 border border-l-0 border-gray-300 rounded-r-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="CHF" <?= ($document['currency'] ?? 'CHF') === 'CHF' ? 'selected' : '' ?>>CHF</option>
                        <option value="EUR" <?= ($document['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR</option>
                        <option value="USD" <?= ($document['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Tags -->
        <?php if (!empty($allTags)): ?>
        <div class="tags-container">
            <label class="block text-sm font-medium text-gray-700 mb-2">Tags</label>
            <div class="flex flex-wrap gap-2">
                <?php 
                $documentTagIds = array_column($tags ?? [], 'id');
                foreach ($allTags as $tag): 
                ?>
                <label class="flex items-center px-3 py-2 border rounded-lg cursor-pointer hover:bg-gray-50 transition-colors
                       <?= in_array($tag['id'], $documentTagIds) ? 'bg-blue-50 border-blue-300 ring-2 ring-blue-200' : 'border-gray-300' ?>">
                    <input type="checkbox" 
                           name="tags[]" 
                           value="<?= $tag['id'] ?>"
                           <?= in_array($tag['id'], $documentTagIds) ? 'checked' : '' ?>
                           class="mr-2"
                           onchange="this.parentElement.classList.toggle('bg-blue-50', this.checked); this.parentElement.classList.toggle('border-blue-300', this.checked); this.parentElement.classList.toggle('ring-2', this.checked); this.parentElement.classList.toggle('ring-blue-200', this.checked);">
                    <span class="inline-block px-2 py-1 rounded-full text-xs font-medium" 
                          style="background-color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>20; color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>">
                        <?= htmlspecialchars($tag['name']) ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Custom Fields (Phase 2.1) -->
        <?php if (!empty($customFields)): ?>
        <div class="border-t pt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Champs personnalisés</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($customFields as $field): 
                    $value = $customFieldValues[$field['id']] ?? '';
                ?>
                <div>
                    <label for="custom_field_<?= $field['id'] ?>" class="block text-sm font-medium text-gray-700 mb-1">
                        <?= htmlspecialchars($field['name']) ?>
                        <?php if ($field['required']): ?><span class="text-red-600">*</span><?php endif; ?>
                    </label>
                    <?php if ($field['field_type'] === 'text' || $field['field_type'] === 'url' || $field['field_type'] === 'email'): ?>
                    <input type="<?= $field['field_type'] === 'email' ? 'email' : ($field['field_type'] === 'url' ? 'url' : 'text') ?>" 
                           id="custom_field_<?= $field['id'] ?>"
                           name="custom_fields[<?= $field['id'] ?>]"
                           value="<?= htmlspecialchars($value) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           <?= $field['required'] ? 'required' : '' ?>>
                    <?php elseif ($field['field_type'] === 'number'): ?>
                    <input type="number" 
                           id="custom_field_<?= $field['id'] ?>"
                           name="custom_fields[<?= $field['id'] ?>]"
                           value="<?= htmlspecialchars($value) ?>"
                           step="any"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           <?= $field['required'] ? 'required' : '' ?>>
                    <?php elseif ($field['field_type'] === 'date'): ?>
                    <input type="date" 
                           id="custom_field_<?= $field['id'] ?>"
                           name="custom_fields[<?= $field['id'] ?>]"
                           value="<?= htmlspecialchars($value) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                           <?= $field['required'] ? 'required' : '' ?>>
                    <?php elseif ($field['field_type'] === 'boolean'): ?>
                    <label class="flex items-center">
                        <input type="checkbox" 
                               id="custom_field_<?= $field['id'] ?>"
                               name="custom_fields[<?= $field['id'] ?>]"
                               value="1"
                               <?= $value ? 'checked' : '' ?>
                               class="mr-2">
                        <span class="text-sm text-gray-700">Oui</span>
                    </label>
                    <?php elseif ($field['field_type'] === 'select'): ?>
                    <?php
                    $options = [];
                    if ($field['options']) {
                        $options = json_decode($field['options'], true);
                    }
                    ?>
                    <select id="custom_field_<?= $field['id'] ?>"
                            name="custom_fields[<?= $field['id'] ?>]"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                            <?= $field['required'] ? 'required' : '' ?>>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= $value === $option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex items-center justify-between pt-6 border-t">
            <a href="<?= url('/documents/' . $document['id']) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Annuler
            </a>
            <div class="flex gap-2">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Enregistrer
                </button>
            </div>
        </div>
    </form>
</div>

<script src="<?= url('/js/document-edit.js') ?>"></script>
