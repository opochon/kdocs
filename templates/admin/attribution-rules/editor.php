<?php
/**
 * Éditeur de règle d'attribution
 */
$isEdit = !empty($rule);
?>

<div class="h-full flex flex-col" id="rule-editor-app">
    <!-- Header -->
    <div class="bg-white border-b px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="<?= url('/admin/attribution-rules') ?>" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <input type="text" id="rule-name" value="<?= htmlspecialchars($rule['name'] ?? '') ?>"
                       placeholder="Nom de la règle"
                       class="text-xl font-bold text-gray-800 border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 p-0">
            </div>
        </div>
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" id="rule-active" <?= ($rule['is_active'] ?? false) ? 'checked' : '' ?>
                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-sm text-gray-600">Active</span>
            </label>
            <button onclick="testRule()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                <i class="fas fa-play mr-2"></i>Tester
            </button>
            <button onclick="saveRule()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-save mr-2"></i>Enregistrer
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 overflow-hidden flex">
        <!-- Editor Panel -->
        <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- Properties -->
            <div class="bg-white rounded-lg shadow p-4">
                <h3 class="font-medium text-gray-800 mb-4">Propriétés</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea id="rule-description" rows="2"
                                  class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500"
                                  placeholder="Description optionnelle..."><?= htmlspecialchars($rule['description'] ?? '') ?></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Priorité</label>
                            <input type="number" id="rule-priority" value="<?= $rule['priority'] ?? 100 ?>"
                                   class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Plus élevé = évalué en premier</p>
                        </div>
                        <div>
                            <label class="flex items-center gap-2 mt-6">
                                <input type="checkbox" id="rule-stop-on-match" <?= ($rule['stop_on_match'] ?? true) ? 'checked' : '' ?>
                                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm text-gray-600">Arrêter si match</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Conditions -->
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-medium text-gray-800">
                        <i class="fas fa-filter text-blue-500 mr-2"></i>SI (Conditions)
                    </h3>
                    <button onclick="addCondition()" class="text-sm text-blue-600 hover:text-blue-800">
                        <i class="fas fa-plus mr-1"></i>Ajouter condition
                    </button>
                </div>
                <div id="conditions-container" class="space-y-3">
                    <!-- Conditions will be rendered here -->
                </div>
                <div id="no-conditions" class="text-center py-6 text-gray-400 <?= !empty($rule['conditions']) ? 'hidden' : '' ?>">
                    <i class="fas fa-info-circle text-2xl mb-2"></i>
                    <p>Aucune condition - la règle s'appliquera à tous les documents</p>
                </div>
            </div>

            <!-- Actions -->
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-medium text-gray-800">
                        <i class="fas fa-bolt text-yellow-500 mr-2"></i>ALORS (Actions)
                    </h3>
                    <button onclick="addAction()" class="text-sm text-blue-600 hover:text-blue-800">
                        <i class="fas fa-plus mr-1"></i>Ajouter action
                    </button>
                </div>
                <div id="actions-container" class="space-y-3">
                    <!-- Actions will be rendered here -->
                </div>
                <div id="no-actions" class="text-center py-6 text-gray-400 <?= !empty($rule['actions']) ? 'hidden' : '' ?>">
                    <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                    <p>Ajoutez au moins une action</p>
                </div>
            </div>
        </div>

        <!-- Test Panel (collapsible) -->
        <div id="test-panel" class="w-96 border-l bg-gray-50 overflow-y-auto hidden">
            <div class="p-4 border-b bg-white">
                <div class="flex items-center justify-between">
                    <h3 class="font-medium text-gray-800">Résultats du test</h3>
                    <button onclick="closeTestPanel()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
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
    <div class="condition-row flex items-start gap-2 p-3 bg-gray-50 rounded-lg" data-index="${index}">
        <div class="flex-1 grid grid-cols-3 gap-2">
            <select class="condition-field-type rounded-lg border-gray-300 text-sm">
                <option value="">-- Champ --</option>
                <?php foreach ($fieldTypes as $type => $config): ?>
                    <option value="<?= $type ?>"><?= htmlspecialchars($config['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="condition-operator rounded-lg border-gray-300 text-sm">
                <option value="">-- Opérateur --</option>
            </select>
            <div class="condition-value-container">
                <input type="text" class="condition-value w-full rounded-lg border-gray-300 text-sm" placeholder="Valeur">
            </div>
        </div>
        <button onclick="removeCondition(this)" class="text-red-400 hover:text-red-600 p-2">
            <i class="fas fa-trash"></i>
        </button>
    </div>
</template>

<template id="action-template">
    <div class="action-row flex items-start gap-2 p-3 bg-gray-50 rounded-lg" data-index="${index}">
        <div class="flex-1 grid grid-cols-2 gap-2">
            <select class="action-type rounded-lg border-gray-300 text-sm">
                <option value="">-- Action --</option>
                <?php foreach ($actionTypes as $type => $config): ?>
                    <option value="<?= $type ?>"><?= htmlspecialchars($config['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="action-value-container">
                <input type="text" class="action-value w-full rounded-lg border-gray-300 text-sm" placeholder="Valeur">
            </div>
        </div>
        <button onclick="removeAction(this)" class="text-red-400 hover:text-red-600 p-2">
            <i class="fas fa-trash"></i>
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
    row.className = 'condition-row flex items-start gap-2 p-3 bg-gray-50 rounded-lg';
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
            <select class="condition-field-type rounded-lg border-gray-300 text-sm" onchange="onFieldTypeChange(this)">
                <option value="">-- Champ --</option>
                ${Object.entries(fieldTypes).map(([type, config]) =>
                    `<option value="${type}" ${type === fieldType ? 'selected' : ''}>${config.label}</option>`
                ).join('')}
            </select>
            <select class="condition-operator rounded-lg border-gray-300 text-sm">
                <option value="">-- Opérateur --</option>
                ${fieldType && fieldTypes[fieldType] ? Object.entries(fieldTypes[fieldType].operators).map(([op, label]) =>
                    `<option value="${op}" ${op === operator ? 'selected' : ''}>${label}</option>`
                ).join('') : ''}
            </select>
            <div class="condition-value-container">
                ${createValueInput(fieldType, value)}
            </div>
        </div>
        <button onclick="removeCondition(this)" class="text-red-400 hover:text-red-600 p-2">
            <i class="fas fa-trash"></i>
        </button>
    `;

    return row;
}

function createActionRow(action, index) {
    const row = document.createElement('div');
    row.className = 'action-row flex items-start gap-2 p-3 bg-gray-50 rounded-lg';
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
            <select class="action-type rounded-lg border-gray-300 text-sm" onchange="onActionTypeChange(this)">
                <option value="">-- Action --</option>
                ${Object.entries(actionTypes).map(([type, config]) =>
                    `<option value="${type}" ${type === actionType ? 'selected' : ''}>${config.label}</option>`
                ).join('')}
            </select>
            <div class="action-value-container">
                ${createActionValueInput(actionType, fieldName, value)}
            </div>
        </div>
        <button onclick="removeAction(this)" class="text-red-400 hover:text-red-600 p-2">
            <i class="fas fa-trash"></i>
        </button>
    `;

    return row;
}

function createValueInput(fieldType, value) {
    switch (fieldType) {
        case 'correspondent':
            return `<select class="condition-value w-full rounded-lg border-gray-300 text-sm">
                <option value="">-- Correspondant --</option>
                ${correspondents.map(c => `<option value="${c.id}" ${c.id == value ? 'selected' : ''}>${c.name}</option>`).join('')}
            </select>`;
        case 'document_type':
            return `<select class="condition-value w-full rounded-lg border-gray-300 text-sm">
                <option value="">-- Type --</option>
                ${documentTypes.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.label}</option>`).join('')}
            </select>`;
        case 'tag':
            return `<select class="condition-value w-full rounded-lg border-gray-300 text-sm">
                <option value="">-- Tag --</option>
                ${tags.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.name}</option>`).join('')}
            </select>`;
        case 'amount':
            return `<input type="number" step="0.01" class="condition-value w-full rounded-lg border-gray-300 text-sm"
                           placeholder="Montant" value="${value}">`;
        default:
            return `<input type="text" class="condition-value w-full rounded-lg border-gray-300 text-sm"
                           placeholder="Valeur" value="${typeof value === 'string' ? value : JSON.stringify(value)}">`;
    }
}

function createActionValueInput(actionType, fieldName, value) {
    switch (actionType) {
        case 'set_field':
            const fields = ['compte_comptable', 'centre_cout', 'projet'];
            let fieldSelect = `<select class="action-field-name w-1/2 rounded-lg border-gray-300 text-sm mr-2">
                <option value="">-- Champ --</option>
                ${fields.map(f => `<option value="${f}" ${f === fieldName ? 'selected' : ''}>${f}</option>`).join('')}
            </select>`;

            let valueSelect = '';
            if (fieldName && fieldOptions[fieldName]) {
                valueSelect = `<select class="action-value w-1/2 rounded-lg border-gray-300 text-sm">
                    <option value="">-- Valeur --</option>
                    ${fieldOptions[fieldName].map(o =>
                        `<option value="${o.option_value}" ${o.option_value == value ? 'selected' : ''}>${o.option_label}</option>`
                    ).join('')}
                </select>`;
            } else {
                valueSelect = `<input type="text" class="action-value w-1/2 rounded-lg border-gray-300 text-sm"
                                      placeholder="Valeur" value="${value}">`;
            }
            return `<div class="flex">${fieldSelect}${valueSelect}</div>`;

        case 'add_tag':
        case 'remove_tag':
            return `<select class="action-value w-full rounded-lg border-gray-300 text-sm">
                <option value="">-- Tag --</option>
                ${tags.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.name}</option>`).join('')}
            </select>`;

        case 'move_to_folder':
            return `<select class="action-value w-full rounded-lg border-gray-300 text-sm">
                <option value="">-- Dossier --</option>
                ${folders.map(f => `<option value="${f.id}" ${f.id == value ? 'selected' : ''}>${f.path || f.name}</option>`).join('')}
            </select>`;

        case 'set_correspondent':
            return `<select class="action-value w-full rounded-lg border-gray-300 text-sm">
                <option value="">-- Correspondant --</option>
                ${correspondents.map(c => `<option value="${c.id}" ${c.id == value ? 'selected' : ''}>${c.name}</option>`).join('')}
            </select>`;

        case 'set_document_type':
            return `<select class="action-value w-full rounded-lg border-gray-300 text-sm">
                <option value="">-- Type --</option>
                ${documentTypes.map(t => `<option value="${t.id}" ${t.id == value ? 'selected' : ''}>${t.label}</option>`).join('')}
            </select>`;

        default:
            return `<input type="text" class="action-value w-full rounded-lg border-gray-300 text-sm" placeholder="Valeur" value="${value}">`;
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
    document.getElementById('test-results').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-blue-500"></i><p class="mt-2 text-gray-500">Test en cours...</p></div>';

    try {
        let testUrl;
        if (ruleId) {
            testUrl = `<?= url('/api/attribution-rules') ?>/${ruleId}/test`;
        } else {
            // For new rules, we need to save first or test with a temporary structure
            document.getElementById('test-results').innerHTML = '<div class="text-center py-8 text-yellow-600"><i class="fas fa-exclamation-triangle text-2xl"></i><p class="mt-2">Enregistrez la règle d\'abord pour la tester</p></div>';
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
        document.getElementById('test-results').innerHTML = `<div class="text-center py-8 text-red-600"><i class="fas fa-times-circle text-2xl"></i><p class="mt-2">${e.message}</p></div>`;
    }
}

function renderTestResults(data) {
    const container = document.getElementById('test-results');

    if (!data.results || data.results.length === 0) {
        container.innerHTML = '<div class="text-center py-8 text-gray-500">Aucun document récent à tester</div>';
        return;
    }

    let html = `
        <div class="mb-4 p-3 bg-white rounded-lg">
            <div class="grid grid-cols-3 gap-2 text-center">
                <div>
                    <div class="text-2xl font-bold text-gray-800">${data.summary.total}</div>
                    <div class="text-xs text-gray-500">Testés</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-green-600">${data.summary.matched}</div>
                    <div class="text-xs text-gray-500">Match</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-400">${data.summary.not_matched}</div>
                    <div class="text-xs text-gray-500">Non match</div>
                </div>
            </div>
        </div>
        <div class="space-y-2">
    `;

    data.results.forEach(r => {
        const statusClass = r.matched ? 'bg-green-100 border-green-200' : 'bg-gray-100 border-gray-200';
        const icon = r.matched ? 'fa-check-circle text-green-600' : 'fa-times-circle text-gray-400';

        html += `
            <div class="p-3 rounded-lg border ${statusClass}">
                <div class="flex items-center gap-2">
                    <i class="fas ${icon}"></i>
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
