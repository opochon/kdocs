<?php
// Page dédiée à la Recherche avancée
use KDocs\Core\Config;
use KDocs\Models\Setting;
$base = Config::basePath();

// Vérifier si Claude est configuré (utiliser le même service que partout)
$claudeService = new \KDocs\Services\ClaudeService();
$isConfigured = $claudeService->isConfigured();
?>

<div class="flex flex-col h-full max-w-4xl mx-auto">
    <div class="bg-white border-b border-gray-100 px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-medium text-gray-900">Recherche avancée</h1>
                <p class="text-sm text-gray-500 mt-1">Recherchez dans vos documents en langage naturel</p>
            </div>
            <?php if (!$isConfigured): ?>
            <a href="<?= url('/admin/settings#ai') ?>" 
               class="px-3 py-1.5 bg-yellow-100 text-yellow-800 text-sm rounded hover:bg-yellow-200">
                ⚠️ Configurer Claude API
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="flex-1 overflow-hidden flex flex-col bg-white">
        <!-- Messages -->
        <div id="chat-messages" class="flex-1 overflow-y-auto p-6 space-y-4">
            <?php if (!$isConfigured): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">
                <svg class="w-12 h-12 text-yellow-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <p class="text-sm text-yellow-800 mb-2">L'API Claude n'est pas configurée</p>
                <p class="text-xs text-yellow-600 mb-4">Configurez votre clé API Claude dans les paramètres pour utiliser le chat IA.</p>
                <a href="<?= url('/admin/settings#ai') ?>" 
                   class="inline-block px-4 py-2 bg-yellow-600 text-white text-sm rounded hover:bg-yellow-700">
                    Aller aux paramètres
                </a>
            </div>
            <?php else: ?>
            <div class="text-center text-gray-500 py-12">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
                <p class="text-sm font-medium text-gray-700 mb-2">Recherchez dans vos documents</p>
                <p class="text-xs text-gray-500 mb-4">Exemples de recherches :</p>
                <div class="space-y-2 max-w-md mx-auto">
                    <button onclick="window.askQuestion('Où est la référence ABC123 ?')" 
                            class="block w-full px-4 py-2 text-sm text-left bg-gray-50 hover:bg-gray-100 rounded border border-gray-200">
                        Où est la référence ABC123 ?
                    </button>
                    <button onclick="window.askQuestion('Total factures Swisscom 2024')" 
                            class="block w-full px-4 py-2 text-sm text-left bg-gray-50 hover:bg-gray-100 rounded border border-gray-200">
                        Total factures Swisscom 2024
                    </button>
                    <button onclick="window.askQuestion('Résume le dernier document')" 
                            class="block w-full px-4 py-2 text-sm text-left bg-gray-50 hover:bg-gray-100 rounded border border-gray-200">
                        Résume le dernier document
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Formulaire -->
        <div class="border-t border-gray-100 p-4 bg-gray-50">
            <form id="chat-form" class="flex gap-2" onsubmit="return false;">
                <input 
                    type="text" 
                    id="chat-input"
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-gray-400"
                    placeholder="<?= $isConfigured ? 'Posez votre question...' : 'Configurez Claude API d\'abord' ?>"
                    <?= !$isConfigured ? 'disabled' : '' ?>
                >
                <button type="submit" 
                        class="px-4 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                        <?= !$isConfigured ? 'disabled' : '' ?>>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    function getChatElements() {
        return {
            messages: document.getElementById('chat-messages'),
            form: document.getElementById('chat-form'),
            input: document.getElementById('chat-input')
        };
    }
    
    // Exposer askQuestion globalement
    window.askQuestion = function(question) {
        const elements = getChatElements();
        if (!elements.input || !elements.form) {
            // Si les éléments n'existent pas encore, attendre un peu
            setTimeout(() => {
                const els = getChatElements();
                if (els.input && els.form) {
                    els.input.value = question;
                    els.form.dispatchEvent(new Event('submit'));
                } else {
                    console.error('Chat form elements not found');
                }
            }, 100);
            return;
        }
        elements.input.value = question;
        elements.form.dispatchEvent(new Event('submit'));
    };

    function addMessage(content, type = 'user') {
        const elements = getChatElements();
        if (!elements.messages) return;
        
        const messageDiv = document.createElement('div');
        messageDiv.className = `flex ${type === 'user' ? 'justify-end' : 'justify-start'} mb-4`;
        
        const bubble = document.createElement('div');
        bubble.className = `max-w-3xl px-4 py-2 rounded-lg ${
            type === 'user' 
                ? 'bg-gray-900 text-white' 
                : 'bg-gray-100 text-gray-900'
        }`;
        bubble.textContent = content;
        
        messageDiv.appendChild(bubble);
        elements.messages.appendChild(messageDiv);
        elements.messages.scrollTop = elements.messages.scrollHeight;
    }

    function addLoadingMessage() {
        const elements = getChatElements();
        if (!elements.messages) return;
        
        const loadingDiv = document.createElement('div');
        loadingDiv.id = 'loading-message';
        loadingDiv.className = 'flex justify-start mb-4';
        loadingDiv.innerHTML = `
            <div class="bg-gray-100 px-4 py-2 rounded-lg">
                <div class="flex items-center gap-2">
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
                </div>
            </div>
        `;
        elements.messages.appendChild(loadingDiv);
        elements.messages.scrollTop = elements.messages.scrollHeight;
    }

    function removeLoadingMessage() {
        const loading = document.getElementById('loading-message');
        if (loading) loading.remove();
    }

    // Attendre que le DOM soit chargé
    document.addEventListener('DOMContentLoaded', function() {
        const elements = getChatElements();
        
        if (elements.form && elements.input) {
            elements.form.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const question = elements.input.value.trim();
                if (!question) return;
                
                // Ajouter la question de l'utilisateur
                addMessage(question, 'user');
                elements.input.value = '';
                
                // Afficher le loading
                addLoadingMessage();
                
                try {
                    const response = await fetch('<?= url('/api/search/ask') ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ question: question })
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const data = await response.json();
                    removeLoadingMessage();
                    
                    if (data.error) {
                        addMessage('Erreur: ' + data.error, 'assistant');
                    } else {
                        addMessage(data.answer || data.response || 'Réponse reçue', 'assistant');
                        
                        // Afficher les documents trouvés si disponibles
                        if (data.documents && data.documents.length > 0) {
                            const elements = getChatElements();
                            if (elements.messages) {
                                const docsDiv = document.createElement('div');
                                docsDiv.className = 'mt-2 space-y-2';
                                data.documents.slice(0, 5).forEach(doc => {
                                    const docLink = document.createElement('a');
                                    docLink.href = '<?= url('/documents') ?>/' + doc.id;
                                    docLink.className = 'block p-2 bg-white border border-gray-200 rounded hover:bg-gray-50 text-sm';
                                    docLink.innerHTML = `
                                        <div class="font-medium">${doc.title || doc.original_filename || 'Sans titre'}</div>
                                        <div class="text-gray-500 text-xs mt-1">
                                            ${doc.correspondent_name || ''} 
                                            ${doc.document_date ? '• ' + doc.document_date : ''}
                                        </div>
                                    `;
                                    docsDiv.appendChild(docLink);
                                });
                                elements.messages.appendChild(docsDiv);
                            }
                        }
                    }
                } catch (error) {
                    removeLoadingMessage();
                    addMessage('Erreur de connexion: ' + error.message, 'assistant');
                }
                
                const elementsAfter = getChatElements();
                if (elementsAfter.messages) {
                    elementsAfter.messages.scrollTop = elementsAfter.messages.scrollHeight;
                }
            });
            
            // Focus sur l'input au chargement
            elements.input.focus();
        }
    });
})();
</script>

<style>
@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
.animate-bounce {
    animation: bounce 1s infinite;
}
</style>
