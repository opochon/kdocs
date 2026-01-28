<?php
/**
 * K-Docs - OnlyOffice Preview Component
 * Partial template pour intégrer OnlyOffice dans la vue document
 *
 * Variables attendues:
 * - $document: array - Le document à prévisualiser
 * - $editMode: bool - Mode édition (optionnel, défaut: false)
 */

$editMode = $editMode ?? false;
$documentId = $document['id'] ?? 0;
$basePath = \KDocs\Core\Config::basePath();

// Vérifier si OnlyOffice est activé ET accessible
$onlyOfficeService = new \KDocs\Services\OnlyOfficeService();
$onlyOfficeAvailable = $onlyOfficeService->isAvailable();

// Extensions supportées
$filename = $document['filename'] ?? $document['original_filename'] ?? '';
$isSupported = $onlyOfficeService->isSupported($filename);
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
?>

<?php if ($onlyOfficeAvailable && $isSupported): ?>
<!-- OnlyOffice Preview Container -->
<div id="onlyoffice-preview-container" class="w-full bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden" style="height: 600px;">
    <!-- Loading state -->
    <div id="onlyoffice-loading" class="flex items-center justify-center h-full">
        <div class="text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
            <p class="text-gray-600 dark:text-gray-400">Chargement de la prévisualisation...</p>
        </div>
    </div>
</div>

<!-- OnlyOffice Viewer Script -->
<script src="<?= $basePath ?>/public/js/onlyoffice-viewer.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const viewer = new OnlyOfficeViewer('onlyoffice-preview-container', <?= $documentId ?>, {
        mode: '<?= $editMode ? 'edit' : 'view' ?>',
        basePath: '<?= $basePath ?>',
        onReady: function() {
            console.log('OnlyOffice document loaded');
            document.getElementById('onlyoffice-loading')?.remove();
        },
        onError: function(error) {
            console.error('OnlyOffice error:', error);
        },
        onSave: function() {
            console.log('Document saved');
            // Rafraîchir les métadonnées si nécessaire
        }
    });
    viewer.init();

    // Nettoyer quand on quitte la page
    window.addEventListener('beforeunload', function() {
        viewer.destroy();
    });
});
</script>

<?php elseif (!$onlyOfficeAvailable): ?>
<!-- OnlyOffice non disponible (non configuré ou serveur inaccessible) -->
<?php
    $isEnabled = $onlyOfficeService->isEnabled();
    $message = $isEnabled
        ? 'Le serveur OnlyOffice est actuellement inaccessible.'
        : 'OnlyOffice n\'est pas configuré sur ce serveur.';
?>
<div class="w-full bg-gray-100 dark:bg-gray-800 rounded-lg p-8 text-center">
    <div class="w-16 h-16 bg-gray-200 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
    </div>
    <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Prévisualisation Office non disponible</h3>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        <?= htmlspecialchars($message) ?>
    </p>
    <a href="<?= $basePath ?>/api/documents/<?= $documentId ?>/download"
       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Télécharger le fichier
    </a>
</div>

<?php else: ?>
<!-- Format non supporté -->
<div class="w-full bg-gray-100 dark:bg-gray-800 rounded-lg p-8 text-center">
    <div class="w-16 h-16 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
    </div>
    <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">Format non supporté</h3>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Le format <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-700 rounded">.<?= htmlspecialchars($extension) ?></code>
        n'est pas supporté pour la prévisualisation.
    </p>
    <p class="text-xs text-gray-400 mb-4">
        Formats supportés: <?= implode(', ', $onlyOfficeService->getSupportedFormats()) ?>
    </p>
    <a href="<?= $basePath ?>/api/documents/<?= $documentId ?>/download"
       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Télécharger le fichier
    </a>
</div>
<?php endif; ?>
