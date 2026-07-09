<?php
// Formulaire de création/édition de workflow (Phase 3.3)
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">
            <?= $workflow ? 'Modifier le workflow' : 'Créer un workflow' ?>
        </h1>
        <a href="<?= url('/admin/workflows') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
            ← Retour
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb,var(--red) 12%,transparent);border-color:color-mix(in srgb,var(--red) 45%,var(--border));color:var(--red)">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url($workflow ? '/admin/workflows/' . $workflow['id'] . '/save' : '/admin/workflows/save') ?>" 
          class="rounded-lg shadow p-6 space-y-6" style="background:var(--surface)">
        
        <?php if ($workflow): ?>
        <input type="hidden" name="id" value="<?= $workflow['id'] ?>">
        <?php endif; ?>

        <div class="grid grid-cols-3 gap-4">
            <div class="col-span-2">
                <label for="name" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Nom du workflow *</label>
                <input type="text" 
                       id="name" 
                       name="name" 
                       value="<?= htmlspecialchars($workflow['name'] ?? '') ?>"
                       class="form-input"
                       required>
            </div>
            <div>
                <label for="order_index" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Ordre de tri</label>
                <input type="number" 
                       id="order_index" 
                       name="order_index" 
                       value="<?= htmlspecialchars($workflow['order_index'] ?? 0) ?>"
                       class="form-input">
            </div>
        </div>

        <div>
            <label class="flex items-center">
                <input type="checkbox" 
                       name="enabled" 
                       value="1"
                       <?= ($workflow['enabled'] ?? true) ? 'checked' : '' ?>
                       class="mr-2">
                <span class="text-sm" style="color:var(--ink-soft)">Activé</span>
            </label>
        </div>

        <div class="pt-6 border-t" style="border-color:var(--border)">
            <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">Triggers</h2>
            <div id="triggers-container" class="space-y-4">
                <?php if (!empty($workflow['triggers'])): ?>
                    <?php foreach ($workflow['triggers'] as $index => $trigger): ?>
                    <div class="trigger-item p-4 border rounded-lg" style="border-color:var(--border)">
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Type de trigger</label>
                                <select name="triggers[<?= $index ?>][trigger_type]" 
                                        class="form-select trigger-type-select"
                                        onchange="updateTriggerConfig(this)">
                                    <option value="consumption" <?= ($trigger['trigger_type'] ?? '') === 'consumption' ? 'selected' : '' ?>>Consumption Started</option>
                                    <option value="document_added" <?= ($trigger['trigger_type'] ?? '') === 'document_added' ? 'selected' : '' ?>>Document Added</option>
                                    <option value="document_updated" <?= ($trigger['trigger_type'] ?? '') === 'document_updated' ? 'selected' : '' ?>>Document Updated</option>
                                    <option value="scheduled" <?= ($trigger['trigger_type'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                </select>
                            </div>
                            <div class="trigger-config-fields" data-trigger-type="<?= htmlspecialchars($trigger['trigger_type'] ?? 'consumption') ?>">
                                <!-- Les champs de configuration seront générés dynamiquement selon le type de trigger -->
                                <div class="space-y-3">
                                    <!-- Filtres communs -->
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Filtre nom de fichier</label>
                                            <input type="text" 
                                                   name="triggers[<?= $index ?>][filter_filename]" 
                                                   value="<?= htmlspecialchars($trigger['filter_filename'] ?? '') ?>"
                                                   placeholder="*.pdf"
                                                   class="form-input">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Filtre chemin</label>
                                            <input type="text" 
                                                   name="triggers[<?= $index ?>][filter_path]" 
                                                   value="<?= htmlspecialchars($trigger['filter_path'] ?? '') ?>"
                                                   placeholder="/path/to/folder"
                                                   class="form-input">
                                        </div>
                                    </div>
                                    
                                    <!-- Filtres spécifiques Scheduled -->
                                    <div class="scheduled-fields" style="display: <?= ($trigger['trigger_type'] ?? '') === 'scheduled' ? 'block' : 'none' ?>;">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Décalage (jours)</label>
                                                <input type="number" 
                                                       name="triggers[<?= $index ?>][schedule_offset_days]" 
                                                       value="<?= htmlspecialchars($trigger['schedule_offset_days'] ?? '0') ?>"
                                                       class="form-input">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Récurrence</label>
                                                <label class="flex items-center">
                                                    <input type="checkbox" 
                                                           name="triggers[<?= $index ?>][schedule_is_recurring]" 
                                                           value="1"
                                                           <?= ($trigger['schedule_is_recurring'] ?? false) ? 'checked' : '' ?>
                                                           class="mr-2">
                                                    <span class="text-sm">Récurrent</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="trigger-item p-4 border rounded-lg" style="border-color:var(--border)">
                        <div class="grid grid-cols-1 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Type de trigger</label>
                                <select name="triggers[0][trigger_type]" 
                                        class="form-select trigger-type-select"
                                        onchange="updateTriggerConfig(this)">
                                    <option value="consumption">Consumption Started</option>
                                    <option value="document_added">Document Added</option>
                                    <option value="document_updated">Document Updated</option>
                                    <option value="scheduled">Scheduled</option>
                                </select>
                            </div>
                            <div class="trigger-config-fields" data-trigger-type="consumption">
                                <div class="space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Filtre nom de fichier</label>
                                            <input type="text" 
                                                   name="triggers[0][filter_filename]" 
                                                   placeholder="*.pdf"
                                                   class="form-input">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Filtre chemin</label>
                                            <input type="text" 
                                                   name="triggers[0][filter_path]" 
                                                   placeholder="/path/to/folder"
                                                   class="form-input">
                                        </div>
                                    </div>
                                    <div class="scheduled-fields" style="display: none;">
                                        <div class="grid grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Décalage (jours)</label>
                                                <input type="number" 
                                                       name="triggers[0][schedule_offset_days]" 
                                                       value="0"
                                                       class="form-input">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Récurrence</label>
                                                <label class="flex items-center">
                                                    <input type="checkbox" 
                                                           name="triggers[0][schedule_is_recurring]" 
                                                           value="1"
                                                           class="mr-2">
                                                    <span class="text-sm">Récurrent</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" onclick="addTrigger()" class="btn-secondary border mt-2 px-4 py-2 text-sm rounded-lg">
                + Ajouter un trigger
            </button>
        </div>

        <div class="pt-6 border-t" style="border-color:var(--border)">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold" style="color:var(--ink)">Actions</h2>
                <button type="button" onclick="addAction()" class="btn-secondary border px-4 py-2 text-sm rounded-lg">
                    <?= icon('plus', ['class' => 'mr-1']) ?> Ajouter une action
                </button>
            </div>
            <div id="actions-container" class="space-y-4">
                <?php if (!empty($workflow['actions'])): ?>
                    <?php foreach ($workflow['actions'] as $index => $action): ?>
                    <?php 
                    // S'assurer que toutes les variables sont disponibles pour le template partiel
                    $action = $action;
                    $index = $index;
                    include __DIR__ . '/workflow_action_form.php'; 
                    ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center justify-between pt-6 border-t" style="border-color:var(--border)">
            <a href="<?= url('/admin/workflows') ?>" class="btn-secondary border px-4 py-2 rounded-lg">
                Annuler
            </a>
            <button type="submit" class="btn-primary px-6 py-2 rounded-lg">
                Enregistrer
            </button>
        </div>
    </form>
</div>

<script>
let triggerIndex = <?= !empty($workflow['triggers']) ? count($workflow['triggers']) : 1 ?>;
let actionIndex = <?= !empty($workflow['actions']) ? count($workflow['actions']) : 1 ?>;

function updateTriggerConfig(select) {
    const triggerItem = select.closest('.trigger-item');
    const configFields = triggerItem.querySelector('.trigger-config-fields');
    const scheduledFields = triggerItem.querySelector('.scheduled-fields');
    const triggerType = select.value;
    
    if (configFields) {
        configFields.setAttribute('data-trigger-type', triggerType);
    }
    
    if (scheduledFields) {
        scheduledFields.style.display = triggerType === 'scheduled' ? 'block' : 'none';
    }
}

function addTrigger() {
    const container = document.getElementById('triggers-container');
    const html = `
        <div class="trigger-item p-4 border rounded-lg" style="border-color:var(--border)">
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Type de trigger</label>
                    <select name="triggers[${triggerIndex}][trigger_type]" 
                            class="form-select trigger-type-select"
                            onchange="updateTriggerConfig(this)">
                        <option value="consumption">Consumption Started</option>
                        <option value="document_added">Document Added</option>
                        <option value="document_updated">Document Updated</option>
                        <option value="scheduled">Scheduled</option>
                    </select>
                </div>
                <div class="trigger-config-fields" data-trigger-type="consumption">
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Filtre nom de fichier</label>
                                <input type="text" 
                                       name="triggers[${triggerIndex}][filter_filename]" 
                                       placeholder="*.pdf"
                                       class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Filtre chemin</label>
                                <input type="text" 
                                       name="triggers[${triggerIndex}][filter_path]" 
                                       placeholder="/path/to/folder"
                                       class="form-input">
                            </div>
                        </div>
                        <div class="scheduled-fields" style="display: none;">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Décalage (jours)</label>
                                    <input type="number" 
                                           name="triggers[${triggerIndex}][schedule_offset_days]" 
                                           value="0"
                                           class="form-input">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Récurrence</label>
                                    <label class="flex items-center">
                                        <input type="checkbox" 
                                               name="triggers[${triggerIndex}][schedule_is_recurring]" 
                                               value="1"
                                               class="mr-2">
                                        <span class="text-sm">Récurrent</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    triggerIndex++;
}

function updateActionConfig(select) {
    const actionItem = select.closest('.action-item');
    const configFields = actionItem.querySelector('.action-config-fields');
    const actionType = parseInt(select.value);
    
    if (configFields) {
        configFields.setAttribute('data-action-type', actionType);
    }
    
    // Afficher/masquer les sections selon le type
    const assignmentFields = actionItem.querySelector('.assignment-fields');
    const removalFields = actionItem.querySelector('.removal-fields');
    const emailFields = actionItem.querySelector('.email-fields');
    const webhookFields = actionItem.querySelector('.webhook-fields');
    
    if (assignmentFields) assignmentFields.style.display = actionType === 1 ? 'block' : 'none';
    if (removalFields) removalFields.style.display = actionType === 2 ? 'block' : 'none';
    if (emailFields) emailFields.style.display = actionType === 3 ? 'block' : 'none';
    if (webhookFields) webhookFields.style.display = actionType === 4 ? 'block' : 'none';
}

function removeAction(button) {
    if (confirm('Êtes-vous sûr de vouloir supprimer cette action ?')) {
        button.closest('.action-item').remove();
    }
}

function addAction() {
    const container = document.getElementById('actions-container');
    
    // Faire un appel AJAX pour charger le template partiel
    fetch('<?= url("/admin/workflows/action-form-template") ?>?index=' + actionIndex)
        .then(response => response.text())
        .then(html => {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const actionHtml = tempDiv.firstElementChild.outerHTML;
            container.insertAdjacentHTML('beforeend', actionHtml);
            actionIndex++;
        })
        .catch(error => {
            console.error('Erreur lors du chargement du template:', error);
            alert('Erreur lors de l\'ajout de l\'action. Veuillez recharger la page.');
        });
}
</script>
