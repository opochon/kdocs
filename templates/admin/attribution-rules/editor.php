<?php
/**
 * Éditeur de règle d'attribution
 */
$isEdit = !empty($rule);
?>

<div class="h-full flex flex-col" id="rule-editor-app">
    <!-- Header -->
    <div class="border-b px-6 py-4 flex items-center justify-between" style="background:var(--surface);border-color:var(--border)">
        <div class="flex items-center gap-4">
            <a href="<?= url('/admin/attribution-rules') ?>" style="color:var(--ink-soft)">
                <?= icon('arrow-left') ?>
            </a>
            <div>
                <input type="text" id="rule-name" value="<?= htmlspecialchars($rule['name'] ?? '') ?>"
                       placeholder="Nom de la règle"
                       class="text-xl font-bold border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 p-0" style="color:var(--ink);background:transparent">
            </div>
        </div>
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" id="rule-active" <?= ($rule['is_active'] ?? false) ? 'checked' : '' ?>
                       class="rounded" style="accent-color:var(--accent)">
                <span class="text-sm" style="color:var(--ink-soft)">Active</span>
            </label>
            <button onclick="testRule()" class="btn-secondary border px-4 py-2 rounded-lg">
                <?= icon('play', ['class' => 'mr-2']) ?>Tester
            </button>
            <button onclick="saveRule()" class="btn-primary px-4 py-2 rounded-lg">
                <?= icon('save', ['class' => 'mr-2']) ?>Enregistrer
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 overflow-hidden flex">
        <!-- Editor Panel -->
        <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- Properties -->
            <div class="rounded-lg shadow p-4" style="background:var(--surface)">
                <h3 class="font-medium mb-4" style="color:var(--ink)">Propriétés</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Description</label>
                        <textarea id="rule-description" rows="2"
                                  class="form-textarea"
                                  placeholder="Description optionnelle..."><?= htmlspecialchars($rule['description'] ?? '') ?></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Priorité</label>
                            <input type="number" id="rule-priority" value="<?= $rule['priority'] ?? 100 ?>"
                                   class="form-input">
                            <p class="text-xs mt-1" style="color:var(--dim)">Plus élevé = évalué en premier</p>
                        </div>
                        <div>
                            <label class="flex items-center gap-2 mt-6">
                                <input type="checkbox" id="rule-stop-on-match" <?= ($rule['stop_on_match'] ?? true) ? 'checked' : '' ?>
                                       class="rounded" style="accent-color:var(--accent)">
                                <span class="text-sm" style="color:var(--ink-soft)">Arrêter si match</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Conditions -->
            <div class="rounded-lg shadow p-4" style="background:var(--surface)">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-medium" style="color:var(--ink)">
                        <?= icon('filter', ['class' => 'mr-2', 'style' => 'color:var(--accent)']) ?>SI (Conditions)
                    </h3>
                    <button onclick="addCondition()" class="text-sm" style="color:var(--accent)">
                        <?= icon('plus', ['class' => 'mr-1']) ?>Ajouter condition
                    </button>
                </div>
                <div id="conditions-container" class="space-y-3">
                    <!-- Conditions will be rendered here -->
                </div>
                <div id="no-conditions" class="text-center py-6 <?= !empty($rule['conditions']) ? 'hidden' : '' ?>" style="color:var(--dim)">
                    <?= icon('info-circle', ['class' => 'text-2xl mb-2']) ?>
                    <p>Aucune condition - la règle s'appliquera à tous les documents</p>
                </div>
            </div>

            <!-- Actions -->
            <div class="rounded-lg shadow p-4" style="background:var(--surface)">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-medium" style="color:var(--ink)">
                        <?= icon('bolt', ['class' => 'mr-2', 'style' => 'color:var(--amber)']) ?>ALORS (Actions)
                    </h3>
                    <button onclick="addAction()" class="text-sm" style="color:var(--accent)">
                        <?= icon('plus', ['class' => 'mr-1']) ?>Ajouter action
                    </button>
                </div>
                <div id="actions-container" class="space-y-3">
                    <!-- Actions will be rendered here -->
                </div>
                <div id="no-actions" class="text-center py-6 <?= !empty($rule['actions']) ? 'hidden' : '' ?>" style="color:var(--dim)">
                    <?= icon('exclamation-triangle', ['class' => 'text-2xl mb-2']) ?>
                    <p>Ajoutez au moins une action</p>
                </div>
            </div>
        </div>

        <!-- Test Panel (collapsible) -->
        <div id="test-panel" class="w-96 border-l overflow-y-auto hidden" style="background:var(--app-bg);border-color:var(--border)">
            <div class="p-4 border-b" style="background:var(--surface);border-color:var(--border)">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium" style="color:var(--ink)">Résultats du test</h3>
                    <button onclick="closeTestPanel()" style="color:var(--dim)">
                        <?= icon('times') ?>
                    </button>
                </div>
            </div>
            <div id="test-results" class="p-4">
                <!-- Test results will be rendered here -->
            </div>
        </div>
    </div>
</div>

<!-- Templates -->
<template id="condition-template">
    <div class="condition-row flex items-start gap-2 p-3 rounded-lg" style="background:var(--app-bg)" data-index="${index}">
        <div class="flex-1 grid grid-cols-3 gap-2">
            <select class="condition-field-type rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Champ --</option>
                <?php foreach ($fieldTypes as $type => $config): ?>
                    <option value="<?= $type ?>"><?= htmlspecialchars($config['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="condition-operator rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Opérateur --</option>
            </select>
            <div class="condition-value-container">
                <input type="text" class="condition-value w-full rounded-lg text-sm" style="border-color:var(--border)" placeholder="Valeur">
            </div>
        </div>
        <button onclick="removeCondition(this)" class="p-2" style="color:var(--red)">
            <?= icon('trash') ?>
        </button>
    </div>
</template>

<template id="action-template">
    <div class="action-row flex items-start gap-2 p-3 rounded-lg" style="background:var(--app-bg)" data-index="${index}">
        <div class="flex-1 grid grid-cols-2 gap-2">
            <select class="action-type rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Action --</option>
                <?php foreach ($actionTypes as $type => $config): ?>
                    <option value="<?= $type ?>"><?= htmlspecialchars($config['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="action-value-container">
                <input type="text" class="action-value w-full rounded-lg text-sm" style="border-color:var(--border)" placeholder="Valeur">
            </div>
        </div>
        <button onclick="removeAction(this)" class="p-2" style="color:var(--red)">
            <?= icon('trash') ?>
        </button>
    </div>
</template>

<script>
// Data
const ruleId = <?= $rule['id'] ?? 'null' ?>;
const fieldTypes = <?= json_encode($fieldTypes) ?>;
const actionTypes = <?= json_encode($actionTypes) ?>;
const correspondents = <?= json_encode($correspondents) ?>;
const documentTypes = <?= json_encode($documentTypes) ?>;
const tags = <?= json_encode($tags) ?>;
const fieldOptions = <?= json_encode($fieldOptions) ?>;
const folders = <?= json_encode($folders) ?>;

// Initial conditions and actions
let conditions = <?= json_encode($rule['conditions'] ?? []) ?>;
let actions = <?= json_encode($rule['actions'] ?? []) ?>;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    renderConditions();
    renderActions();
});

function renderConditions() {
    const container = document.getElementById('conditions-container');
    container.innerHTML = '';

    conditions.forEach((condition, index) => {
        container.appendChild(createConditionRow(condition, index));
    });

    document.getElementById('no-conditions').classList.toggle('hidden', conditions.length > 0);
}

function renderActions() {
    const container = document.getElementById('actions-container');
    container.innerHTML = '';

    actions.forEach((action, index) => {
        container.appendChild(createActionRow(action, index));
    });

    document.getElementById('no-actions').classList.toggle('hidden', actions.length > 0);
}

function createConditionRow(condition, index) {
    const row = document.createElement('div');
    row.className = 'condition-row flex items-start gap-2 p-3 rounded-lg';
    row.style.background = 'var(--app-bg)';
    row.dataset.index = index;

    const fieldType = condition.field_type || '';
    const operator = condition.operator || '';
    let value = condition.value || '';

    // Parse JSON value if needed
    try {
        const parsed = JSON.parse(value);
        if (typeof parsed !== 'string') value = parsed;
    } catch (e) {}

    row.innerHTML = `
        <div class="flex-1 grid grid-cols-3 gap-2">
            <select class="condition-field-type rounded-lg text-sm" style="border-color:var(--border)" onchange="onFieldTypeChange(this)">
                <option value="">-- Champ --</option>
                ${Object.entries(fieldTypes).map(([type, config]) =>
                    `<option value="${type}" ${type === fieldType ? 'selected' : ''}>${config.label}</option>`
                ).join('')}
            </select>
            <select class="condition-operator rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Opérateur --</option>
                ${fieldType && fieldTypes[fieldType] ? Object.entries(fieldTypes[fieldType].operators).map(([op, label]) =>
                    `<option value="${op}" ${op === operator ? 'selected' : ''}>${label}</option>`
                ).join('') : ''}
            </select>
            <div class="condition-value-container">
                ${createValueInput(fieldType, value)}
            </div>
        </div>
        <button onclick="removeCondition(this)" class="p-2" style="color:var(--red)">
            <?= icon('trash') ?>
        </button>
    `;

    return row;
}

function createActionRow(action, index) {
    const row = document.createElement('div');
    row.className = 'action-row flex items-start gap-2 p-3 rounded-lg';
    row.style.background = 'var(--app-bg)';
    row.dataset.index = index;

    const actionType = action.action_type || '';
    const fieldName = action.field_name || '';
    let value = action.value || '';

    try {
        const parsed = JSON.parse(value);
        if (typeof parsed !== 'string') value = parsed;
    } catch (e) {}

    row.innerHTML = `
        <div class="flex-1 grid grid-cols-2 gap-2">
            <select class="action-type rounded-lg text-sm" style="border-color:var(--border)" onchange="onActionTypeChange(this)">
                <option value="">-- Action --</option>
                ${Object.entries(actionTypes).map(([type, config]) =>
                    `<option value="${type}" ${type === actionType ? 'selected' : ''}>${config.label}</option>`
                ).join('')}
            </select>
            <div class="action-value-container">
                ${createActionValueInput(actionType, fieldName, value)}
            </div>
        </div>
        <button onclick="removeAction(this)" class="p-2" style="color:var(--red)">
            <?= icon('trash') ?>
        </button>
    `;

    return row;
}

function createValueInput(fieldType, value) {
    switch (fieldType) {
        case 'correspondent':
            return `<select class="condition-value w-full rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Correspondant --</option>
                ${correspondents.map(c => `<option value="${c.id}" ${c.id == value ? 'selected' : ''}>${c.name}</option>`).join('')}
            </select>`;
        case 'document_type':
            return `<select class="condition-value w-full rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Type --</option>
                ${documentTypes.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.label}</option>`).join('')}
            </select>`;
        case 'tag':
            return `<select class="condition-value w-full rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Tag --</option>
                ${tags.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.name}</option>`).join('')}
            </select>`;
        case 'amount':
            return `<input type="number" step="0.01" class="condition-value w-full rounded-lg text-sm" style="border-color:var(--border)"
                           placeholder="Montant" value="${value}">`;
        default:
            return `<input type="text" class="condition-value w-full rounded-lg text-sm" style="border-color:var(--border)"
                           placeholder="Valeur" value="${typeof value === 'string' ? value : JSON.stringify(value)}">`;
    }
}

function createActionValueInput(actionType, fieldName, value) {
    switch (actionType) {
        case 'set_field':
            const fields = ['compte_comptable', 'centre_cout', 'projet'];
            let fieldSelect = `<select class="action-field-name w-1/2 rounded-lg text-sm mr-2" style="border-color:var(--border)">
                <option value="">-- Champ --</option>
                ${fields.map(f => `<option value="${f}" ${f === fieldName ? 'selected' : ''}>${f}</option>`).join('')}
            </select>`;

            let valueSelect = '';
            if (fieldName && fieldOptions[fieldName]) {
                valueSelect = `<select class="action-value w-1/2 rounded-lg text-sm" style="border-color:var(--border)">
                    <option value="">-- Valeur --</option>
                    ${fieldOptions[fieldName].map(o =>
                        `<option value="${o.option_value}" ${o.option_value == value ? 'selected' : ''}>${o.option_label}</option>`
                    ).join('')}
                </select>`;
            } else {
                valueSelect = `<input type="text" class="action-value w-1/2 rounded-lg text-sm" style="border-color:var(--border)"
                                      placeholder="Valeur" value="${value}">`;
            }
            return `<div class="flex">${fieldSelect}${valueSelect}</div>`;

        case 'add_tag':
        case 'remove_tag':
            return `<select class="action-value w-full rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Tag --</option>
                ${tags.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.name}</option>`).join('')}
            </select>`;

        case 'move_to_folder':
            return `<select class="action-value w-full rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Dossier --</option>
                ${folders.map(f => `<option value="${f.id}" ${f.id == value ? 'selected' : ''}>${f.path || f.name}</option>`).join('')}
            </select>`;

        case 'set_correspondent':
            return `<select class="action-value w-full rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Correspondant --</option>
                ${correspondents.map(c => `<option value="${c.id}" ${c.id == value ? 'selected' : ''}>${c.name}</option>`).join('')}
            </select>`;

        case 'set_document_type':
            return `<select class="action-value w-full rounded-lg text-sm" style="border-color:var(--border)">
                <option value="">-- Type --</option>
                ${documentTypes.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.label}</option>`).join('')}
            </select>`;

        default:
            return `<input type="text" class="action-value w-full rounded-lg text-sm" style="border-color:var(--border)" placeholder="Valeur" value="${value}">`;
    }
}

function onFieldTypeChange(select) {
    const row = select.closest('.condition-row');
    const fieldType = select.value;

    // Update operators
    const operatorSelect = row.querySelector('.condition-operator');
    operatorSelect.innerHTML = '<option value="">-- Opérateur --</option>';
    if (fieldType && fieldTypes[fieldType]) {
        Object.entries(fieldTypes[fieldType].operators).forEach(([op, label]) => {
            operatorSelect.innerHTML += `<option value="${op}">${label}</option>`;
        });
    }

    // Update value input
    const valueContainer = row.querySelector('.condition-value-container');
    valueContainer.innerHTML = createValueInput(fieldType, '');
}

function onActionTypeChange(select) {
    const row = select.closest('.action-row');
    const actionType = select.value;

    const valueContainer = row.querySelector('.action-value-container');
    valueContainer.innerHTML = createActionValueInput(actionType, '', '');
}

function addCondition() {
    const index = conditions.length;
    conditions.push({ field_type: '', operator: '', value: '' });

    const container = document.getElementById('conditions-container');
    container.appendChild(createConditionRow({}, index));

    document.getElementById('no-conditions').classList.add('hidden');
}

function removeCondition(button) {
    const row = button.closest('.condition-row');
    const index = parseInt(row.dataset.index);
    conditions.splice(index, 1);
    renderConditions();
}

function addAction() {
    const index = actions.length;
    actions.push({ action_type: '', field_name: '', value: '' });

    const container = document.getElementById('actions-container');
    container.appendChild(createActionRow({}, index));

    document.getElementById('no-actions').classList.add('hidden');
}

function removeAction(button) {
    const row = button.closest('.action-row');
    const index = parseInt(row.dataset.index);
    actions.splice(index, 1);
    renderActions();
}

function collectConditions() {
    const rows = document.querySelectorAll('.condition-row');
    return Array.from(rows).map(row => ({
        field_type: row.querySelector('.condition-field-type')?.value || '',
        operator: row.querySelector('.condition-operator')?.value || '',
        value: row.querySelector('.condition-value')?.value || '',
        condition_group: 0
    })).filter(c => c.field_type && c.operator);
}

function collectActions() {
    const rows = document.querySelectorAll('.action-row');
    return Array.from(rows).map(row => ({
        action_type: row.querySelector('.action-type')?.value || '',
        field_name: row.querySelector('.action-field-name')?.value || '',
        value: row.querySelector('.action-value')?.value || ''
    })).filter(a => a.action_type);
}

async function saveRule() {
    const name = document.getElementById('rule-name').value.trim();
    if (!name) {
        alert('Le nom de la règle est requis');
        return;
    }

    const collectedActions = collectActions();
    if (collectedActions.length === 0) {
        alert('Ajoutez au moins une action');
        return;
    }

    const data = {
        name: name,
        description: document.getElementById('rule-description').value,
        priority: parseInt(document.getElementById('rule-priority').value) || 100,
        is_active: document.getElementById('rule-active').checked,
        stop_on_match: document.getElementById('rule-stop-on-match').checked,
        conditions: collectConditions(),
        actions: collectedActions
    };

    try {
        const url = ruleId
            ? `<?= url('/api/attribution-rules') ?>/${ruleId}`
            : '<?= url('/api/attribution-rules') ?>';
        const method = ruleId ? 'PUT' : 'POST';

        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();
        if (result.success) {
            window.location.href = '<?= url('/admin/attribution-rules') ?>';
        } else {
            alert(result.message || 'Erreur lors de l\'enregistrement');
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}

async function testRule() {
    const data = {
        conditions: collectConditions(),
        actions: collectActions()
    };

    // Show test panel
    document.getElementById('test-panel').classList.remove('hidden');
    document.getElementById('test-results').innerHTML = '<div class="text-center py-8">' + window.lucideIcon('spinner', {spin: true, cls: 'text-2xl', style: 'color:var(--accent)'}) + '<p class="mt-2" style="color:var(--dim)">Test en cours...</p></div>';

    try {
        let testUrl;
        if (ruleId) {
            testUrl = `<?= url('/api/attribution-rules') ?>/${ruleId}/test`;
        } else {
            // For new rules, we need to save first or test with a temporary structure
            document.getElementById('test-results').innerHTML = '<div class="text-center py-8" style="color:var(--amber)">' + window.lucideIcon('exclamation-triangle', {cls: 'text-2xl'}) + '<p class="mt-2">Enregistrez la règle d\'abord pour la tester</p></div>';
            return;
        }

        const response = await fetch(testUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({ document_ids: [] })
        });

        const result = await response.json();
        renderTestResults(result.data || result);
    } catch (e) {
        document.getElementById('test-results').innerHTML = `<div class="text-center py-8" style="color:var(--red)">${window.lucideIcon('times-circle', {cls: 'text-2xl'})}<p class="mt-2">${e.message}</p></div>`;
    }
}

function renderTestResults(data) {
    const container = document.getElementById('test-results');

    if (!data.results || data.results.length === 0) {
        container.innerHTML = '<div class="text-center py-8" style="color:var(--dim)">Aucun document récent à tester</div>';
        return;
    }

    let html = `
        <div class="mb-4 p-3 rounded-lg" style="background:var(--surface)">
            <div class="grid grid-cols-3 gap-2 text-center">
                <div>
                    <div class="text-2xl font-bold" style="color:var(--ink)">${data.summary.total}</div>
                    <div class="text-xs" style="color:var(--dim)">Testés</div>
                </div>
                <div>
                    <div class="text-2xl font-bold" style="color:var(--green)">${data.summary.matched}</div>
                    <div class="text-xs" style="color:var(--dim)">Match</div>
                </div>
                <div>
                    <div class="text-2xl font-bold" style="color:var(--dim)">${data.summary.not_matched}</div>
                    <div class="text-xs" style="color:var(--dim)">Non match</div>
                </div>
            </div>
        </div>
        <div class="space-y-2">
    `;

    data.results.forEach(r => {
        const statusStyle = r.matched ? 'background:color-mix(in srgb,var(--green) 14%,transparent);border-color:var(--green)' : 'background:var(--rail);border-color:var(--border)';
        const iconName = r.matched ? 'check-circle' : 'times-circle';
        const iconColor = r.matched ? 'var(--green)' : 'var(--dim)';

        html += `
            <div class="p-3 rounded-lg border" style="${statusStyle}">
                <div class="flex items-center gap-2">
                    ${window.lucideIcon(iconName, {style: 'color:' + iconColor})}
                    <span class="font-medium text-sm truncate">${r.document_title || 'Document #' + r.document_id}</span>
                </div>
            </div>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

function closeTestPanel() {
    document.getElementById('test-panel').classList.add('hidden');
}
</script>
