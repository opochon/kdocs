<?php
// Modals pour les actions groupées - Style Paperless-ngx
// $tags, $documentTypes, $correspondents sont passés depuis le contrôleur
?>

<!-- Modal Sélection Étiquette -->
<div id="bulk-tag-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="rounded-lg max-w-md w-full" style="background:var(--surface); box-shadow:var(--shadow-pop)">
        <div class="px-6 py-4 flex items-center justify-between rounded-t-lg border-b" style="border-color:var(--border)">
            <h3 class="text-lg font-semibold" style="color:var(--ink)">Ajouter une étiquette</h3>
            <button onclick="closeBulkTagModal()" style="color:var(--dim)">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-sm mb-4" style="color:var(--ink-soft)">Sélectionnez l'étiquette à ajouter aux documents sélectionnés :</p>
            <div id="bulk-tag-list" class="space-y-2 max-h-64 overflow-y-auto">
                <?php foreach ($tags ?? [] as $tag): ?>
                <label class="flex items-center p-3 border rounded-lg cursor-pointer ds-row-hover" style="border-color:var(--border)">
                    <input type="radio" name="bulk_tag" value="<?= $tag['id'] ?>" class="mr-3">
                    <span class="inline-block px-3 py-1 rounded-full text-sm"
                          style="--_tc:<?= htmlspecialchars($tag['color'] ?? '') ?>; background-color:color-mix(in srgb,var(--_tc,var(--dim)) 20%,transparent); color:var(--_tc,var(--dim))">
                        <?= htmlspecialchars($tag['name']) ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <div id="bulk-tag-preview" class="mt-4 p-3 border rounded-lg hidden" style="background:var(--accent-soft); border-color:color-mix(in srgb, var(--accent) 30%, transparent)">
                <p class="text-sm font-medium" style="color:var(--accent)">Documents affectés : <span id="bulk-tag-count">0</span></p>
            </div>
        </div>
        <div class="px-6 py-4 flex justify-end gap-2 rounded-b-lg" style="background:var(--app-bg)">
            <button onclick="closeBulkTagModal()" class="btn btn-secondary">
                Annuler
            </button>
            <button onclick="confirmBulkTag()" class="btn btn-primary">
                Appliquer
            </button>
        </div>
    </div>
</div>

<!-- Modal Sélection Type -->
<div id="bulk-type-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="rounded-lg max-w-md w-full" style="background:var(--surface); box-shadow:var(--shadow-pop)">
        <div class="px-6 py-4 flex items-center justify-between rounded-t-lg border-b" style="border-color:var(--border)">
            <h3 class="text-lg font-semibold" style="color:var(--ink)">Définir le type</h3>
            <button onclick="closeBulkTypeModal()" style="color:var(--dim)">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-sm mb-4" style="color:var(--ink-soft)">Sélectionnez le type à assigner aux documents sélectionnés :</p>
            <select id="bulk-type-select" class="form-select">
                <option value="">-- Aucun type --</option>
                <?php foreach ($documentTypes ?? [] as $type): ?>
                <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="bulk-type-preview" class="mt-4 p-3 border rounded-lg hidden" style="background:var(--accent-soft); border-color:color-mix(in srgb, var(--accent) 30%, transparent)">
                <p class="text-sm font-medium" style="color:var(--accent)">Documents affectés : <span id="bulk-type-count">0</span></p>
            </div>
        </div>
        <div class="px-6 py-4 flex justify-end gap-2 rounded-b-lg" style="background:var(--app-bg)">
            <button onclick="closeBulkTypeModal()" class="btn btn-secondary">
                Annuler
            </button>
            <button onclick="confirmBulkType()" class="btn btn-primary">
                Appliquer
            </button>
        </div>
    </div>
</div>

<!-- Modal Sélection Correspondant -->
<div id="bulk-correspondent-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="rounded-lg max-w-md w-full" style="background:var(--surface); box-shadow:var(--shadow-pop)">
        <div class="px-6 py-4 flex items-center justify-between rounded-t-lg border-b" style="border-color:var(--border)">
            <h3 class="text-lg font-semibold" style="color:var(--ink)">Définir le correspondant</h3>
            <button onclick="closeBulkCorrespondentModal()" style="color:var(--dim)">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-sm mb-4" style="color:var(--ink-soft)">Sélectionnez le correspondant à assigner aux documents sélectionnés :</p>
            <select id="bulk-correspondent-select" class="form-select">
                <option value="">-- Aucun correspondant --</option>
                <?php foreach ($correspondents ?? [] as $corr): ?>
                <option value="<?= $corr['id'] ?>"><?= htmlspecialchars($corr['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="bulk-correspondent-preview" class="mt-4 p-3 border rounded-lg hidden" style="background:var(--accent-soft); border-color:color-mix(in srgb, var(--accent) 30%, transparent)">
                <p class="text-sm font-medium" style="color:var(--accent)">Documents affectés : <span id="bulk-correspondent-count">0</span></p>
            </div>
        </div>
        <div class="px-6 py-4 flex justify-end gap-2 rounded-b-lg" style="background:var(--app-bg)">
            <button onclick="closeBulkCorrespondentModal()" class="btn btn-secondary">
                Annuler
            </button>
            <button onclick="confirmBulkCorrespondent()" class="btn btn-primary">
                Appliquer
            </button>
        </div>
    </div>
</div>

<script>
// Fonctions pour les modals d'actions groupées
function openBulkTagModal() {
    const selected = getSelectedDocuments();
    if (selected.length === 0) {
        if (typeof showToast !== 'undefined') {
            showToast('Aucun document sélectionné', 'warning');
        } else {
            alert('Aucun document sélectionné');
        }
        return;
    }

    document.getElementById('bulk-tag-count').textContent = selected.length;
    document.getElementById('bulk-tag-preview').classList.remove('hidden');
    document.getElementById('bulk-tag-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeBulkTagModal() {
    document.getElementById('bulk-tag-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

function confirmBulkTag() {
    const selected = getSelectedDocuments();
    const tagId = document.querySelector('input[name="bulk_tag"]:checked')?.value;

    if (!tagId) {
        if (typeof showToast !== 'undefined') {
            showToast('Veuillez sélectionner un tag', 'warning');
        } else {
            alert('Veuillez sélectionner un tag');
        }
        return;
    }

    performBulkAction('add_tag', { tag_id: parseInt(tagId) });
    closeBulkTagModal();
}

function openBulkTypeModal() {
    const selected = getSelectedDocuments();
    if (selected.length === 0) {
        if (typeof showToast !== 'undefined') {
            showToast('Aucun document sélectionné', 'warning');
        } else {
            alert('Aucun document sélectionné');
        }
        return;
    }

    document.getElementById('bulk-type-count').textContent = selected.length;
    document.getElementById('bulk-type-preview').classList.remove('hidden');
    document.getElementById('bulk-type-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeBulkTypeModal() {
    document.getElementById('bulk-type-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

function confirmBulkType() {
    const selected = getSelectedDocuments();
    const typeId = document.getElementById('bulk-type-select').value;

    if (!typeId) {
        if (typeof showToast !== 'undefined') {
            showToast('Veuillez sélectionner un type', 'warning');
        } else {
            alert('Veuillez sélectionner un type');
        }
        return;
    }

    performBulkAction('set_type', { document_type_id: parseInt(typeId) });
    closeBulkTypeModal();
}

function openBulkCorrespondentModal() {
    const selected = getSelectedDocuments();
    if (selected.length === 0) {
        if (typeof showToast !== 'undefined') {
            showToast('Aucun document sélectionné', 'warning');
        } else {
            alert('Aucun document sélectionné');
        }
        return;
    }

    document.getElementById('bulk-correspondent-count').textContent = selected.length;
    document.getElementById('bulk-correspondent-preview').classList.remove('hidden');
    document.getElementById('bulk-correspondent-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeBulkCorrespondentModal() {
    document.getElementById('bulk-correspondent-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

function confirmBulkCorrespondent() {
    const selected = getSelectedDocuments();
    const correspondentId = document.getElementById('bulk-correspondent-select').value;

    if (!correspondentId) {
        if (typeof showToast !== 'undefined') {
            showToast('Veuillez sélectionner un correspondant', 'warning');
        } else {
            alert('Veuillez sélectionner un correspondant');
        }
        return;
    }

    performBulkAction('set_correspondent', { correspondent_id: parseInt(correspondentId) });
    closeBulkCorrespondentModal();
}

// Fonction helper pour obtenir les documents sélectionnés
function getSelectedDocuments() {
    const checkboxes = document.querySelectorAll('.document-checkbox:checked');
    return Array.from(checkboxes).map(cb => parseInt(cb.value));
}
</script>
