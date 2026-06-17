<?php
/**
 * K-Docs - OnlyOffice Preview Component
 * Partial template pour intégrer OnlyOffice dans la vue document
 *
 * Variables attendues:
 * - $document: array - Le document à prévisualiser
 * - $editMode: bool - Mode édition (optionnel, défaut: false)
 * - $user: array - Utilisateur courant (optionnel)
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

// Générer la config directement (plus fiable que l'appel AJAX)
$editorConfig = null;
$serverUrl = '';
if ($onlyOfficeAvailable && $isSupported) {
    $userId = $user['id'] ?? 1;
    $userName = $user['username'] ?? $user['first_name'] ?? 'Utilisateur';
    $editorConfig = $onlyOfficeService->generateConfig($document, $userId, $userName, $editMode);
    $serverUrl = $onlyOfficeService->getServerUrl();
}
?>

<?php if ($onlyOfficeAvailable && $isSupported && $editorConfig): ?>
<!-- OnlyOffice Preview Container -->
<div id="onlyoffice-editor" class="w-full h-full"></div>

<!-- Charger l'API OnlyOffice -->
<script src="<?= htmlspecialchars($serverUrl) ?>/web-apps/apps/api/documents/api.js"></script>
<script>
(function() {
    // Configuration générée côté serveur
    const config = <?= json_encode($editorConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    // Ajouter les événements
    config.events = {
        onAppReady: function() {
            console.log('OnlyOffice: App ready');
        },
        onReady: function() {
            console.log('OnlyOffice: Document ready');
        },
        onDocumentReady: function() {
            console.log('OnlyOffice: Document loaded');
        },
        onError: function(event) {
            console.error('OnlyOffice error:', event.data);
        },
        onWarning: function(event) {
            console.warn('OnlyOffice warning:', event.data);
        },
        onDocumentStateChange: function(event) {
            if (event.data === false) {
                console.log('OnlyOffice: Document saved');
            }
        }
    };

    // Initialiser l'éditeur
    function initEditor() {
        if (typeof DocsAPI === 'undefined') {
            console.error('OnlyOffice: DocsAPI not loaded');
            document.getElementById('onlyoffice-editor').innerHTML =
                '<div class="flex items-center justify-center h-full text-red-500">' +
                '<p>Erreur: Impossible de charger OnlyOffice</p></div>';
            return;
        }

        try {
            const editor = new DocsAPI.DocEditor('onlyoffice-editor', config);
            console.log('OnlyOffice: Editor initialized');

            // Nettoyer à la fermeture
            window.addEventListener('beforeunload', function() {
                if (editor && typeof editor.destroyEditor === 'function') {
                    editor.destroyEditor();
                }
            });
        } catch (e) {
            console.error('OnlyOffice init error:', e);
        }
    }

    // Attendre que DocsAPI soit chargé
    if (typeof DocsAPI !== 'undefined') {
        initEditor();
    } else {
        // Attendre le chargement du script
        const checkInterval = setInterval(function() {
            if (typeof DocsAPI !== 'undefined') {
                clearInterval(checkInterval);
                initEditor();
            }
        }, 100);

        // Timeout après 10 secondes
        setTimeout(function() {
            clearInterval(checkInterval);
            if (typeof DocsAPI === 'undefined') {
                console.error('OnlyOffice: Timeout loading DocsAPI');
            }
        }, 10000);
    }
})();
</script>

<?php elseif (!$onlyOfficeAvailable): ?>
<!-- OnlyOffice non disponible -->
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
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4"><?= htmlspecialchars($message) ?></p>
    <a href="<?= $basePath ?>/documents/<?= $documentId ?>/download"
       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Télécharger
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
        n'est pas supporté.
    </p>
    <a href="<?= $basePath ?>/documents/<?= $documentId ?>/download"
       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Télécharger
    </a>
</div>
<?php endif; ?>
