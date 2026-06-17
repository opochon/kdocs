<?php
/**
 * K-Docs - OnlyOffice Editor (Full Page)
 * Page dédiée pour éditer un document avec OnlyOffice dans un nouvel onglet
 */
$basePath = \KDocs\Core\Config::basePath();
$documentTitle = $document['title'] ?? $document['filename'] ?? 'Document';

// Générer la config OnlyOffice directement (évite l'appel AJAX qui nécessite auth)
$onlyOfficeService = new \KDocs\Services\OnlyOfficeService();
$onlyOfficeAvailable = $onlyOfficeService->isAvailable();
$serverUrl = $onlyOfficeService->getServerUrl();

$editorConfig = null;
if ($onlyOfficeAvailable) {
    $userId = $user['id'] ?? 1;
    $userName = $user['username'] ?? $user['first_name'] ?? 'Utilisateur';
    $editorConfig = $onlyOfficeService->generateConfig($document, $userId, $userName, true); // true = edit mode
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($documentTitle) ?> - Éditeur OnlyOffice</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        #onlyoffice-editor {
            height: calc(100vh - 48px);
            width: 100%;
        }
        .header-bar {
            height: 48px;
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Header minimal -->
    <div class="header-bar flex items-center justify-between px-4 text-white shadow-md">
        <div class="flex items-center gap-3">
            <a href="<?= $basePath ?>/documents" class="hover:text-blue-200 transition" title="Retour aux documents">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <span class="font-medium truncate max-w-md"><?= htmlspecialchars($documentTitle) ?></span>
        </div>
        <div class="flex items-center gap-2">
            <span id="save-status" class="text-xs text-blue-200"></span>
            <a href="<?= $basePath ?>/documents/<?= $document['id'] ?>/download"
               class="px-3 py-1 text-xs bg-white/10 hover:bg-white/20 rounded transition"
               title="Télécharger">
                Télécharger
            </a>
            <button onclick="window.close()"
                    class="px-3 py-1 text-xs bg-white/10 hover:bg-white/20 rounded transition">
                Fermer
            </button>
        </div>
    </div>

    <!-- Container OnlyOffice -->
    <div id="onlyoffice-editor">
        <div id="onlyoffice-loading" class="flex items-center justify-center h-full bg-gray-50">
            <div class="text-center">
                <div class="animate-spin rounded-full h-16 w-16 border-b-4 border-blue-600 mx-auto mb-4"></div>
                <p class="text-gray-600 text-lg">Chargement de l'éditeur OnlyOffice...</p>
                <p class="text-gray-400 text-sm mt-2">Veuillez patienter</p>
            </div>
        </div>
    </div>

<?php if ($onlyOfficeAvailable && $editorConfig): ?>
    <!-- Charger l'API OnlyOffice -->
    <script src="<?= htmlspecialchars($serverUrl) ?>/web-apps/apps/api/documents/api.js"></script>
    <script>
    (function() {
        const BASE_PATH = '<?= $basePath ?>';
        const DOCUMENT_ID = <?= $document['id'] ?>;

        // Configuration générée côté serveur
        const config = <?= json_encode($editorConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        // Ajouter les événements
        config.events = {
            onAppReady: function() {
                console.log('OnlyOffice: App ready');
            },
            onReady: function() {
                console.log('OnlyOffice: Editor ready');
                document.getElementById('onlyoffice-loading')?.remove();
            },
            onDocumentReady: function() {
                console.log('OnlyOffice: Document loaded');
            },
            onError: function(event) {
                console.error('OnlyOffice error:', event.data);
                showError('Erreur OnlyOffice: ' + (event.data?.errorDescription || JSON.stringify(event.data)));
            },
            onWarning: function(event) {
                console.warn('OnlyOffice warning:', event.data);
            },
            onDocumentStateChange: function(event) {
                const statusEl = document.getElementById('save-status');
                if (event.data) {
                    statusEl.textContent = 'Modifications non sauvegardées';
                    statusEl.classList.add('text-yellow-300');
                } else {
                    statusEl.textContent = 'Sauvegardé';
                    statusEl.classList.remove('text-yellow-300');
                    setTimeout(() => { statusEl.textContent = ''; }, 2000);
                }
            }
        };

        function initEditor() {
            if (typeof DocsAPI === 'undefined') {
                console.error('OnlyOffice: DocsAPI not loaded');
                showError('Impossible de charger OnlyOffice API');
                return;
            }

            try {
                const editor = new DocsAPI.DocEditor('onlyoffice-editor', config);
                console.log('OnlyOffice: Editor initialized');

                window.addEventListener('beforeunload', function() {
                    if (editor && typeof editor.destroyEditor === 'function') {
                        editor.destroyEditor();
                    }
                });
            } catch (e) {
                console.error('OnlyOffice init error:', e);
                showError(e.message);
            }
        }

        function showError(message) {
            document.getElementById('onlyoffice-editor').innerHTML = `
                <div class="flex items-center justify-center h-full bg-gray-100">
                    <div class="text-center p-8 max-w-lg">
                        <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-10 h-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">Éditeur non disponible</h3>
                        <p class="text-gray-600 mb-4">${message}</p>
                        <div class="space-y-2">
                            <a href="${BASE_PATH}/documents/${DOCUMENT_ID}/download"
                               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 mr-2">
                                Télécharger le fichier
                            </a>
                            <button onclick="location.reload()"
                                    class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                                Réessayer
                            </button>
                        </div>
                    </div>
                </div>
            `;
        }

        // Attendre que DocsAPI soit chargé
        if (typeof DocsAPI !== 'undefined') {
            initEditor();
        } else {
            const checkInterval = setInterval(function() {
                if (typeof DocsAPI !== 'undefined') {
                    clearInterval(checkInterval);
                    initEditor();
                }
            }, 100);

            setTimeout(function() {
                clearInterval(checkInterval);
                if (typeof DocsAPI === 'undefined') {
                    showError('Timeout: OnlyOffice API non chargée');
                }
            }, 10000);
        }
    })();
    </script>
<?php else: ?>
    <script>
        document.getElementById('onlyoffice-editor').innerHTML = `
            <div class="flex items-center justify-center h-full bg-gray-100">
                <div class="text-center p-8">
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">OnlyOffice non disponible</h3>
                    <p class="text-gray-600 mb-4">Le serveur OnlyOffice n'est pas accessible.</p>
                    <a href="<?= $basePath ?>/documents/<?= $document['id'] ?>/download"
                       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Télécharger le fichier
                    </a>
                </div>
            </div>
        `;
    </script>
<?php endif; ?>
</body>
</html>
