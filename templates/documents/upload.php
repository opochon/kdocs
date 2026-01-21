<?php
// $documentTypes, $correspondents, $error, $success sont passés depuis le contrôleur
?>

<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">Uploader un document</h1>

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

    <form method="POST" action="<?= url('/documents/upload') ?>" enctype="multipart/form-data" class="bg-white rounded-lg shadow p-6 space-y-6">
        <div>
            <label for="file" class="block text-sm font-medium text-gray-700 mb-2">
                Fichier <span class="text-red-500">*</span>
            </label>
            <input 
                type="file" 
                id="file" 
                name="file" 
                required
                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt"
                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
            >
            <p class="mt-1 text-sm text-gray-500">Formats acceptés : PDF, DOC, DOCX, JPG, PNG, TXT</p>
        </div>

        <div>
            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                Titre
            </label>
            <input 
                type="text" 
                id="title" 
                name="title" 
                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                placeholder="Titre du document (optionnel)"
            >
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="document_type_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Type de document
                </label>
                <select 
                    id="document_type_id" 
                    name="document_type_id"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">-- Sélectionner --</option>
                    <?php foreach ($documentTypes as $type): ?>
                        <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="correspondent_id" class="block text-sm font-medium text-gray-700 mb-2">
                    Correspondant
                </label>
                <select 
                    id="correspondent_id" 
                    name="correspondent_id"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">-- Sélectionner --</option>
                    <?php foreach ($correspondents as $corr): ?>
                        <option value="<?= $corr['id'] ?>"><?= htmlspecialchars($corr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <label for="doc_date" class="block text-sm font-medium text-gray-700 mb-2">
                    Date du document
                </label>
                <input 
                    type="date" 
                    id="doc_date" 
                    name="doc_date"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
            </div>

            <div>
                <label for="amount" class="block text-sm font-medium text-gray-700 mb-2">
                    Montant
                </label>
                <input 
                    type="number" 
                    step="0.01"
                    id="amount" 
                    name="amount"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    placeholder="0.00"
                >
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-gray-700 mb-2">
                    Devise
                </label>
                <select 
                    id="currency" 
                    name="currency"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="CHF" selected>CHF</option>
                    <option value="EUR">EUR</option>
                    <option value="USD">USD</option>
                </select>
            </div>
        </div>

        <div class="flex items-center justify-end space-x-4">
            <a href="<?= url('/documents') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Annuler
            </a>
            <button 
                type="submit"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
                Uploader
            </button>
        </div>
    </form>
</div>
