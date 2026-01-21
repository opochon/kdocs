<?php
// Formulaire de création/édition de workflow (Phase 3.3)
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">
            <?= $workflow ? 'Modifier le workflow' : 'Créer un workflow' ?>
        </h1>
        <a href="<?= url('/admin/workflows') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
            ← Retour
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="bg-red-50 dark:bg-red-900 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-200 px-4 py-3 rounded">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= url($workflow ? '/admin/workflows/' . $workflow['id'] . '/save' : '/admin/workflows/save') ?>" 
          class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-6">
        
        <?php if ($workflow): ?>
        <input type="hidden" name="id" value="<?= $workflow['id'] ?>">
        <?php endif; ?>

        <div>
            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom du workflow *</label>
            <input type="text" 
                   id="name" 
                   name="name" 
                   value="<?= htmlspecialchars($workflow['name'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"
                   required>
        </div>

        <div>
            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
            <textarea id="description" 
                      name="description"
                      rows="3"
                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100"><?= htmlspecialchars($workflow['description'] ?? '') ?></textarea>
        </div>

        <div>
            <label class="flex items-center">
                <input type="checkbox" 
                       name="enabled" 
                       value="1"
                       <?= ($workflow['enabled'] ?? true) ? 'checked' : '' ?>
                       class="mr-2">
                <span class="text-sm text-gray-700 dark:text-gray-300">Workflow actif</span>
            </label>
        </div>

        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Triggers</h2>
            <div id="triggers-container" class="space-y-4">
                <?php if (!empty($workflow['triggers'])): ?>
                    <?php foreach ($workflow['triggers'] as $index => $trigger): ?>
                    <div class="trigger-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type de trigger</label>
                                <select name="triggers[<?= $index ?>][trigger_type]" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <option value="document_created" <?= ($trigger['trigger_type'] ?? '') === 'document_created' ? 'selected' : '' ?>>Document créé</option>
                                    <option value="document_updated" <?= ($trigger['trigger_type'] ?? '') === 'document_updated' ? 'selected' : '' ?>>Document modifié</option>
                                    <option value="tag_added" <?= ($trigger['trigger_type'] ?? '') === 'tag_added' ? 'selected' : '' ?>>Tag ajouté</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Configuration (JSON)</label>
                                <input type="text" 
                                       name="triggers[<?= $index ?>][trigger_config]" 
                                       value="<?= htmlspecialchars($trigger['trigger_config'] ?? '{}') ?>"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="trigger-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type de trigger</label>
                                <select name="triggers[0][trigger_type]" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <option value="document_created">Document créé</option>
                                    <option value="document_updated">Document modifié</option>
                                    <option value="tag_added">Tag ajouté</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Configuration (JSON)</label>
                                <input type="text" 
                                       name="triggers[0][trigger_config]" 
                                       value="{}"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" onclick="addTrigger()" class="mt-2 px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600">
                + Ajouter un trigger
            </button>
        </div>

        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Actions</h2>
            <div id="actions-container" class="space-y-4">
                <?php if (!empty($workflow['actions'])): ?>
                    <?php foreach ($workflow['actions'] as $index => $action): ?>
                    <div class="action-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type d'action</label>
                                <select name="actions[<?= $index ?>][action_type]" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <option value="assign_tag" <?= ($action['action_type'] ?? '') === 'assign_tag' ? 'selected' : '' ?>>Assigner un tag</option>
                                    <option value="assign_type" <?= ($action['action_type'] ?? '') === 'assign_type' ? 'selected' : '' ?>>Assigner un type</option>
                                    <option value="assign_correspondent" <?= ($action['action_type'] ?? '') === 'assign_correspondent' ? 'selected' : '' ?>>Assigner un correspondant</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Configuration (JSON)</label>
                                <input type="text" 
                                       name="actions[<?= $index ?>][action_config]" 
                                       value="<?= htmlspecialchars($action['action_config'] ?? '{}') ?>"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="action-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type d'action</label>
                                <select name="actions[0][action_type]" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                                    <option value="assign_tag">Assigner un tag</option>
                                    <option value="assign_type">Assigner un type</option>
                                    <option value="assign_correspondent">Assigner un correspondant</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Configuration (JSON)</label>
                                <input type="text" 
                                       name="actions[0][action_config]" 
                                       value="{}"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <button type="button" onclick="addAction()" class="mt-2 px-4 py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600">
                + Ajouter une action
            </button>
        </div>

        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
            <a href="<?= url('/admin/workflows') ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Enregistrer
            </button>
        </div>
    </form>
</div>

<script>
let triggerIndex = <?= !empty($workflow['triggers']) ? count($workflow['triggers']) : 1 ?>;
let actionIndex = <?= !empty($workflow['actions']) ? count($workflow['actions']) : 1 ?>;

function addTrigger() {
    const container = document.getElementById('triggers-container');
    const html = `
        <div class="trigger-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type de trigger</label>
                    <select name="triggers[${triggerIndex}][trigger_type]" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                        <option value="document_created">Document créé</option>
                        <option value="document_updated">Document modifié</option>
                        <option value="tag_added">Tag ajouté</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Configuration (JSON)</label>
                    <input type="text" 
                           name="triggers[${triggerIndex}][trigger_config]" 
                           value="{}"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    triggerIndex++;
}

function addAction() {
    const container = document.getElementById('actions-container');
    const html = `
        <div class="action-item p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type d'action</label>
                    <select name="actions[${actionIndex}][action_type]" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                        <option value="assign_tag">Assigner un tag</option>
                        <option value="assign_type">Assigner un type</option>
                        <option value="assign_correspondent">Assigner un correspondant</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Configuration (JSON)</label>
                    <input type="text" 
                           name="actions[${actionIndex}][action_config]" 
                           value="{}"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-gray-100">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    actionIndex++;
}
</script>
