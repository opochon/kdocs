<?php
// $documents, $workflowTypes, $users, $error, $success sont passés depuis le contrôleur
?>

<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">Créer une tâche</h1>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/tasks/create') ?>" class="bg-white rounded-lg shadow p-6 space-y-6">
        <div>
            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                Titre <span class="text-red-500">*</span>
            </label>
            <input 
                type="text" 
                id="title" 
                name="title" 
                required
                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                placeholder="Titre de la tâche"
            >
        </div>

        <div>
            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                Description
            </label>
            <textarea 
                id="description" 
                name="description" 
                rows="4"
                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                placeholder="Description de la tâche (optionnel)"
            ></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="document_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Document associé
                </label>
                <select 
                    id="document_id" 
                    name="document_id"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
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
                <label for="workflow_type_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Type de workflow
                </label>
                <select 
                    id="workflow_type_id" 
                    name="workflow_type_id"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
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
                <label for="assigned_to" class="block text-sm font-medium text-gray-700 mb-2">
                    Assigné à
                </label>
                <select 
                    id="assigned_to" 
                    name="assigned_to"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">-- Non assigné --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="priority" class="block text-sm font-medium text-gray-700 mb-2">
                    Priorité
                </label>
                <select 
                    id="priority" 
                    name="priority"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="low">Basse</option>
                    <option value="medium" selected>Moyenne</option>
                    <option value="high">Haute</option>
                    <option value="urgent">Urgente</option>
                </select>
            </div>
        </div>

        <div>
            <label for="due_date" class="block text-sm font-medium text-gray-700 mb-2">
                Date d'échéance
            </label>
            <input 
                type="date" 
                id="due_date" 
                name="due_date"
                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
            >
        </div>

        <div class="flex items-center justify-end space-x-4">
            <a href="<?= url('/tasks') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Annuler
            </a>
            <button 
                type="submit"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
                Créer la tâche
            </button>
        </div>
    </form>
</div>
