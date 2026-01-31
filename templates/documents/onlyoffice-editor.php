<?php
/**
 * K-Docs - OnlyOffice Editor (Full Page)
 * Page dédiée pour éditer un document avec OnlyOffice dans un nouvel onglet
 */
$basePath = \KDocs\Core\Config::basePath();
$documentTitle = $document['title'] ?? $document['filename'] ?? 'Document';
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

    <!-- OnlyOffice Viewer Script -->
    <script>
    const BASE_PATH = '<?= $basePath ?>';
    const DOCUMENT_ID = <?= $document['id'] ?>;
    const EDIT_MODE = true;

    async function initOnlyOffice() {
        try {
            // Récupérer la configuration
            const response = await fetch(`${BASE_PATH}/api/onlyoffice/config/${DOCUMENT_ID}?mode=edit`);
            const data = await response.json();

            if (!data.success) {
                showError(data.error || 'Erreur de configuration OnlyOffice');
                return;
            }

            // Charger le script OnlyOffice API
            await loadScript(data.serverUrl + '/web-apps/apps/api/documents/api.js');

            // Configurer les callbacks
            data.config.events = {
                onReady: function() {
                    console.log('OnlyOffice ready');
                    document.getElementById('onlyoffice-loading')?.remove();
                },
                onError: function(event) {
                    console.error('OnlyOffice error:', event);
                    showError('Erreur OnlyOffice: ' + (event.data?.errorDescription || 'Erreur inconnue'));
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

            // Initialiser l'éditeur
            new DocsAPI.DocEditor('onlyoffice-editor', data.config);
            console.log('OnlyOffice editor initialized');

        } catch (error) {
            console.error('Init error:', error);
            showError(error.message);
        }
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            if (document.querySelector(`script[src="${src}"]`)) {
                if (typeof DocsAPI !== 'undefined') {
                    resolve();
                    return;
                }
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;

            script.onload = () => {
                const check = setInterval(() => {
                    if (typeof DocsAPI !== 'undefined') {
                        clearInterval(check);
                        resolve();
                    }
                }, 100);

                setTimeout(() => {
                    clearInterval(check);
                    if (typeof DocsAPI === 'undefined') {
                        reject(new Error('Timeout: DocsAPI not loaded'));
                    }
                }, 10000);
            };

            script.onerror = () => reject(new Error('Failed to load OnlyOffice script'));
            document.head.appendChild(script);
        });
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
                        <p class="text-sm text-gray-500">
                            Vérifiez que le serveur OnlyOffice est démarré sur le port 8080.
                        </p>
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

    // Initialiser au chargement
    document.addEventListener('DOMContentLoaded', initOnlyOffice);
    </script>
</body>
</html>
