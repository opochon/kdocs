<?php
// Page Export/Import
$success = $_GET['success'] ?? null;
$error = $_GET['error'] ?? null;
$imported = $_GET['imported'] ?? 0;
$skipped = $_GET['skipped'] ?? 0;
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Export/Import</h1>
    </div>

    <?php if ($success): ?>
    <div class="bg-green-50 dark:bg-green-900 border border-green-200 dark:border-green-700 rounded-lg p-4">
        <p class="text-green-800 dark:text-green-200">
            ✅ Import réussi ! <?= $imported ?> document(s) importé(s), <?= $skipped ?> ignoré(s).
        </p>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-50 dark:bg-red-900 border border-red-200 dark:border-red-700 rounded-lg p-4">
        <p class="text-red-800 dark:text-red-200">
            ❌ Erreur : 
            <?php
            $errors = [
                'upload_failed' => 'Échec du téléchargement du fichier.',
                'invalid_format' => 'Format de fichier invalide.',
                'import_failed' => 'Échec de l\'importation.',
            ];
            echo $errors[$error] ?? 'Erreur inconnue.';
            ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Export -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Export</h2>
        
        <div class="space-y-4">
            <div>
                <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Export des documents</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Exporte tous les documents avec leurs métadonnées (tags, correspondants, types, etc.) au format JSON.
                    Les fichiers physiques ne sont pas inclus dans l'export.
                </p>
                <a href="<?= url('/admin/export-import/export-documents') ?>" 
                   class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Exporter les documents
                </a>
            </div>
            
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Export des métadonnées</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Exporte uniquement les métadonnées (tags, correspondants, types de documents, champs personnalisés, etc.) sans les documents.
                </p>
                <a href="<?= url('/admin/export-import/export-metadata') ?>" 
                   class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Exporter les métadonnées
                </a>
            </div>
        </div>
    </div>

    <!-- Import -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Import</h2>
        
        <form method="POST" action="<?= url('/admin/export-import/import') ?>" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label for="file" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Fichier JSON d'export
                </label>
                <input type="file" id="file" name="file" accept=".json" required
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Sélectionnez un fichier JSON exporté depuis K-Docs. Les documents déjà présents seront ignorés.
                </p>
            </div>
            
            <div class="bg-yellow-50 dark:bg-yellow-900 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
                <p class="text-sm text-yellow-800 dark:text-yellow-200">
                    ⚠️ <strong>Attention :</strong> L'import crée uniquement les métadonnées des documents. 
                    Les fichiers physiques doivent être copiés manuellement dans le dossier de stockage configuré.
                </p>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Importer
                </button>
            </div>
        </form>
    </div>

    <!-- Informations -->
    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Format d'export</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Les fichiers d'export sont au format JSON et contiennent :
        </p>
        <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-400 space-y-1">
            <li>Toutes les métadonnées des documents (titre, date, montant, etc.)</li>
            <li>Les associations avec les tags, correspondants et types de documents</li>
            <li>Les valeurs des champs personnalisés</li>
            <li>Les notes associées aux documents</li>
            <li>Les métadonnées système (dates de création, modification, etc.)</li>
        </ul>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">
            <strong>Note :</strong> Les fichiers physiques ne sont pas inclus dans l'export pour des raisons de sécurité et de taille.
            Pour une migration complète, copiez également les fichiers depuis le dossier de stockage.
        </p>
    </div>
</div>
