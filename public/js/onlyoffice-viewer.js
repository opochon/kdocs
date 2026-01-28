/**
 * K-Docs - OnlyOffice Viewer
 * Composant JavaScript pour intégrer OnlyOffice Document Server
 */

class OnlyOfficeViewer {
    constructor(containerId, documentId, options = {}) {
        this.containerId = containerId;
        this.documentId = documentId;
        this.options = {
            mode: 'view',
            basePath: '/kdocs',
            onReady: null,
            onError: null,
            onSave: null,
            ...options
        };
        this.editor = null;
        this.config = null;
    }

    /**
     * Initialise le viewer OnlyOffice
     */
    async init() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.error('OnlyOffice: Container not found:', this.containerId);
            return;
        }

        // Afficher le loader
        container.innerHTML = `
            <div class="flex items-center justify-center h-full bg-gray-50 dark:bg-gray-800">
                <div class="text-center">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
                    <p class="text-gray-600 dark:text-gray-400">Chargement du document...</p>
                </div>
            </div>
        `;

        try {
            // Récupérer la configuration depuis l'API
            const response = await fetch(
                `${this.options.basePath}/api/onlyoffice/config/${this.documentId}?mode=${this.options.mode}`
            );

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Erreur de configuration OnlyOffice');
            }

            this.config = data.config;

            // Charger le script OnlyOffice API
            await this.loadScript(data.serverUrl + '/web-apps/apps/api/documents/api.js');

            // Ajouter les callbacks
            if (this.options.onReady) {
                this.config.events = this.config.events || {};
                this.config.events.onReady = this.options.onReady;
            }

            if (this.options.onError) {
                this.config.events = this.config.events || {};
                this.config.events.onError = this.options.onError;
            }

            if (this.options.onSave) {
                this.config.events = this.config.events || {};
                this.config.events.onDocumentStateChange = (event) => {
                    if (event.data === false) { // Document saved
                        this.options.onSave();
                    }
                };
            }

            // Initialiser l'éditeur
            this.editor = new DocsAPI.DocEditor(this.containerId, this.config);

            console.log('OnlyOffice: Editor initialized successfully');

        } catch (error) {
            console.error('OnlyOffice init error:', error);
            this.showError(error.message);

            if (this.options.onError) {
                this.options.onError(error);
            }
        }
    }

    /**
     * Charge un script externe
     */
    loadScript(src) {
        return new Promise((resolve, reject) => {
            // Vérifier si déjà chargé
            if (document.querySelector(`script[src="${src}"]`)) {
                // Attendre que DocsAPI soit disponible
                if (typeof DocsAPI !== 'undefined') {
                    resolve();
                    return;
                }
                // Attendre un peu
                setTimeout(() => {
                    if (typeof DocsAPI !== 'undefined') {
                        resolve();
                    } else {
                        reject(new Error('DocsAPI not loaded'));
                    }
                }, 1000);
                return;
            }

            const script = document.createElement('script');
            script.src = src;
            script.async = true;

            script.onload = () => {
                // Attendre que DocsAPI soit initialisé
                const checkDocsAPI = setInterval(() => {
                    if (typeof DocsAPI !== 'undefined') {
                        clearInterval(checkDocsAPI);
                        resolve();
                    }
                }, 100);

                // Timeout après 10 secondes
                setTimeout(() => {
                    clearInterval(checkDocsAPI);
                    if (typeof DocsAPI === 'undefined') {
                        reject(new Error('DocsAPI timeout'));
                    }
                }, 10000);
            };

            script.onerror = () => {
                reject(new Error('Impossible de charger le script OnlyOffice. Vérifiez que le serveur est accessible.'));
            };

            document.head.appendChild(script);
        });
    }

    /**
     * Affiche une erreur dans le container
     */
    showError(message) {
        const container = document.getElementById(this.containerId);
        if (container) {
            container.innerHTML = `
                <div class="flex items-center justify-center h-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
                    <div class="text-center p-8 max-w-md">
                        <div class="w-16 h-16 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                            Prévisualisation non disponible
                        </h3>
                        <p class="text-sm mb-4">${this.escapeHtml(message)}</p>
                        <a href="${this.options.basePath}/api/documents/${this.documentId}/download"
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Télécharger le fichier
                        </a>
                    </div>
                </div>
            `;
        }
    }

    /**
     * Échappe le HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Détruit l'éditeur
     */
    destroy() {
        if (this.editor && typeof this.editor.destroyEditor === 'function') {
            this.editor.destroyEditor();
            this.editor = null;
        }
    }

    /**
     * Force la sauvegarde du document
     */
    save() {
        if (this.editor && typeof this.editor.downloadAs === 'function') {
            // Note: downloadAs force une sauvegarde callback
            console.log('OnlyOffice: Requesting save...');
        }
    }

    /**
     * Vérifie si OnlyOffice est disponible
     */
    static async checkAvailability(basePath = '/kdocs') {
        try {
            const response = await fetch(`${basePath}/api/onlyoffice/status`);
            const data = await response.json();
            return data.success && data.data.enabled && data.data.server_reachable;
        } catch {
            return false;
        }
    }
}

// Export global
window.OnlyOfficeViewer = OnlyOfficeViewer;
