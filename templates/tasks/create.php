<?php
// $documents, $workflowTypes, $users, $error, $success sont passés depuis le contrôleur
?>

<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-bold" style="color:var(--ink)">Créer une tâche</h1>

    <?php if ($error): ?>
        <div class="ds-chip--red px-4 py-3 rounded">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="ds-chip--green px-4 py-3 rounded">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/tasks/create') ?>" class="ds-card shadow p-6 space-y-6">
        <div>
            <label for="title" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                Titre <span style="color:var(--red)">*</span>
            </label>
            <input 
                type="text" 
                id="title" 
                name="title" 
                required
                class="block w-full px-3 py-2 rounded-md shadow-sm"
                placeholder="Titre de la tâche"
            >
        </div>

        <div>
            <label for="description" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                Description
            </label>
            <textarea 
                id="description" 
                name="description" 
                rows="4"
                class="block w-full px-3 py-2 rounded-md shadow-sm"
                placeholder="Description de la tâche (optionnel)"
            ></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="document_id" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                    Document associé
                </label>
                <select 
                    id="document_id" 
                    name="document_id"
                    class="block w-full px-3 py-2 rounded-md shadow-sm"
                >
                    <option value="">-- Aucun document --</option>
                    <?php foreach ($documents as $doc): ?>
                        <option value="<?= $doc['id'] ?>">
                            <?= htmlspecialchars($doc['title'] ?: $doc['original_filename']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="workflow_type_id" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                    Type de workflow
                </label>
                <select 
                    id="workflow_type_id" 
                    name="workflow_type_id"
                    class="block w-full px-3 py-2 rounded-md shadow-sm"
                >
                    <option value="">-- Sélectionner --</option>
                    <?php foreach ($workflowTypes as $type): ?>
                        <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="assigned_to" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                    Assigné à
                </label>
                <select 
                    id="assigned_to" 
                    name="assigned_to"
                    class="block w-full px-3 py-2 rounded-md shadow-sm"
                >
                    <option value="">-- Non assigné --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="priority" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                    Priorité
                </label>
                <select 
                    id="priority" 
                    name="priority"
                    class="block w-full px-3 py-2 rounded-md shadow-sm"
                >
                    <option value="low">Basse</option>
                    <option value="medium" selected>Moyenne</option>
                    <option value="high">Haute</option>
                    <option value="urgent">Urgente</option>
                </select>
            </div>
        </div>

        <div>
            <label for="due_date" class="block text-sm font-medium mb-2" style="color:var(--ink-soft)">
                Date d'échéance
            </label>
            <input 
                type="date" 
                id="due_date" 
                name="due_date"
                class="block w-full px-3 py-2 rounded-md shadow-sm"
            >
        </div>

        <div class="flex items-center justify-end space-x-4">
            <a href="<?= url('/tasks') ?>" class="px-4 py-2 border rounded-lg btn-secondary">
                Annuler
            </a>
            <button
                type="submit"
                class="px-6 py-2 rounded-lg btn-primary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
                Créer la tâche
            </button>
        </div>
    </form>
</div>
