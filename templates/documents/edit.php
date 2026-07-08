<?php
// Formulaire d'édition de document (Priorité 1.2)
use KDocs\Core\Config;
$base = Config::basePath();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Modifier le document</h1>
        <a href="<?= url('/documents/' . $document['id']) ?>" class="btn btn-secondary">
            ← Retour
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--red) 12%, transparent); border-color:color-mix(in srgb, var(--red) 35%, transparent); color:var(--red)">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/documents/' . $document['id'] . '/edit') ?>" class="ds-card p-6 space-y-6">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- ASN (Phase 2.3) -->
            <div>
                <label for="asn" class="form-label">ASN (Archive Serial Number)</label>
                <input type="number"
                       id="asn"
                       name="asn"
                       value="<?= htmlspecialchars($document['asn'] ?? '') ?>"
                       class="form-input"
                       placeholder="Généré automatiquement">
                <p class="text-xs mt-1" style="color:var(--dim)">Numéro de série d'archive pour documents physiques</p>
            </div>

            <!-- Titre -->
            <div>
                <label for="title" class="form-label">Titre *</label>
                <input type="text"
                       id="title"
                       name="title"
                       value="<?= htmlspecialchars($document['title'] ?? '') ?>"
                       class="form-input"
                       required>
            </div>

            <!-- Type de document -->
            <div>
                <label for="document_type_id" class="form-label">Type de document</label>
                <select id="document_type_id"
                        name="document_type_id"
                        class="form-select">
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
                <label for="correspondent_id" class="form-label">Correspondant</label>
                <select id="correspondent_id"
                        name="correspondent_id"
                        class="form-select">
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
                <label for="document_date" class="form-label">Date du document</label>
                <input type="date"
                       id="document_date"
                       name="document_date"
                       value="<?= htmlspecialchars($document['document_date'] ?? ($document['doc_date'] ?? '')) ?>"
                       class="form-input">
            </div>

            <!-- Montant -->
            <div>
                <label for="amount" class="form-label">Montant</label>
                <div class="flex">
                    <input type="number"
                           id="amount"
                           name="amount"
                           step="0.01"
                           value="<?= htmlspecialchars($document['amount'] ?? '') ?>"
                           class="form-input flex-1"
                           style="border-top-right-radius:0; border-bottom-right-radius:0">
                    <select name="currency"
                            class="form-select"
                            style="width:auto; border-left:0; border-top-left-radius:0; border-bottom-left-radius:0">
                        <option value="CHF" <?= ($document['currency'] ?? 'CHF') === 'CHF' ? 'selected' : '' ?>>CHF</option>
                        <option value="EUR" <?= ($document['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR</option>
                        <option value="USD" <?= ($document['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Étiquettes -->
        <?php if (!empty($allTags)): ?>
        <div class="tags-container">
            <label class="form-label">Étiquettes</label>
            <div class="flex flex-wrap gap-2">
                <?php
                $documentTagIds = array_column($tags ?? [], 'id');
                foreach ($allTags as $tag):
                    $tagSelected = in_array($tag['id'], $documentTagIds);
                ?>
                <label class="flex items-center px-3 py-2 border rounded-lg cursor-pointer transition-colors ds-row-hover"
                       style="border-color:<?= $tagSelected ? 'var(--accent)' : 'var(--border)' ?>;<?= $tagSelected ? ' background:var(--accent-soft);' : '' ?>">
                    <input type="checkbox"
                           name="tags[]"
                           value="<?= $tag['id'] ?>"
                           <?= $tagSelected ? 'checked' : '' ?>
                           class="mr-2"
                           onchange="this.parentElement.style.background = this.checked ? 'var(--accent-soft)' : ''; this.parentElement.style.borderColor = this.checked ? 'var(--accent)' : 'var(--border)';">
                    <span class="inline-block px-2 py-1 rounded-full text-xs font-medium"
                          style="--_tc:<?= htmlspecialchars($tag['color'] ?? '') ?>; background-color:color-mix(in srgb,var(--_tc,var(--dim)) 20%,transparent); color:var(--_tc,var(--dim))">
                        <?= htmlspecialchars($tag['name']) ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Custom Fields (Phase 2.1) -->
        <?php if (!empty($customFields)): ?>
        <div class="border-t pt-6" style="border-color:var(--border)">
            <h3 class="text-lg font-semibold mb-4" style="color:var(--ink)">Champs personnalisés</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($customFields as $field):
                    $value = $customFieldValues[$field['id']] ?? '';
                ?>
                <div>
                    <label for="custom_field_<?= $field['id'] ?>" class="form-label">
                        <?= htmlspecialchars($field['name']) ?>
                        <?php if ($field['required']): ?><span style="color:var(--red)">*</span><?php endif; ?>
                    </label>
                    <?php if ($field['field_type'] === 'text' || $field['field_type'] === 'url' || $field['field_type'] === 'email'): ?>
                    <input type="<?= $field['field_type'] === 'email' ? 'email' : ($field['field_type'] === 'url' ? 'url' : 'text') ?>"
                           id="custom_field_<?= $field['id'] ?>"
                           name="custom_fields[<?= $field['id'] ?>]"
                           value="<?= htmlspecialchars($value) ?>"
                           class="form-input"
                           <?= $field['required'] ? 'required' : '' ?>>
                    <?php elseif ($field['field_type'] === 'number'): ?>
                    <input type="number"
                           id="custom_field_<?= $field['id'] ?>"
                           name="custom_fields[<?= $field['id'] ?>]"
                           value="<?= htmlspecialchars($value) ?>"
                           step="any"
                           class="form-input"
                           <?= $field['required'] ? 'required' : '' ?>>
                    <?php elseif ($field['field_type'] === 'date'): ?>
                    <input type="date"
                           id="custom_field_<?= $field['id'] ?>"
                           name="custom_fields[<?= $field['id'] ?>]"
                           value="<?= htmlspecialchars($value) ?>"
                           class="form-input"
                           <?= $field['required'] ? 'required' : '' ?>>
                    <?php elseif ($field['field_type'] === 'boolean'): ?>
                    <label class="flex items-center">
                        <input type="checkbox"
                               id="custom_field_<?= $field['id'] ?>"
                               name="custom_fields[<?= $field['id'] ?>]"
                               value="1"
                               <?= $value ? 'checked' : '' ?>
                               class="mr-2">
                        <span class="text-sm" style="color:var(--ink-soft)">Oui</span>
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
                            class="form-select"
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
        <div class="flex items-center justify-between pt-6 border-t" style="border-color:var(--border)">
            <a href="<?= url('/documents/' . $document['id']) ?>" class="btn btn-secondary">
                Annuler
            </a>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">
                    Enregistrer
                </button>
            </div>
        </div>
    </form>
</div>

<script src="<?= url('/js/document-edit.js') ?>"></script>
