// === RECHERCHE RAPIDE ===

let searchTimeout = null;
const searchInput = document.getElementById('search-input');
const searchDropdown = document.getElementById('search-dropdown');
const searchResults = document.getElementById('search-results');

if (searchInput) {
    // Recherche avec debounce
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            searchDropdown.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(() => {
            quickSearch(query);
        }, 300);
    });
    
    // Raccourci Ctrl+K
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
        }
        // Echap pour fermer
        if (e.key === 'Escape') {
            searchDropdown.classList.add('hidden');
            searchInput.blur();
        }
    });
    
    // Fermer dropdown si clic ailleurs
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#global-search')) {
            searchDropdown.classList.add('hidden');
        }
    });
}

async function quickSearch(query) {
    try {
        const response = await fetch(`/kdocs/api/search/quick?q=${encodeURIComponent(query)}`);
        const data = await response.json();
        
        if (data.results && data.results.length > 0) {
            renderSearchResults(data.results);
            searchDropdown.classList.remove('hidden');
        } else {
            searchResults.innerHTML = '<div class="p-4 text-gray-500">Aucun résultat</div>';
            searchDropdown.classList.remove('hidden');
        }
    } catch (error) {
        console.error('Erreur recherche:', error);
        searchResults.innerHTML = '<div class="p-4 text-red-500">Erreur de recherche</div>';
        searchDropdown.classList.remove('hidden');
    }
}

function renderSearchResults(results) {
    searchResults.innerHTML = results.map(doc => `
        <a href="/kdocs/documents/${doc.id}" class="block p-3 hover:bg-gray-50 border-b last:border-0">
            <div class="flex justify-between items-start">
                <div>
                    <div class="font-medium text-gray-900">${escapeHtml(doc.title || doc.original_filename)}</div>
                    <div class="text-sm text-gray-500">
                        ${escapeHtml(doc.correspondent_name || '')} 
                        ${doc.document_date ? '• ' + doc.document_date : ''}
                    </div>
                </div>
                ${doc.amount ? `<span class="text-green-600 font-medium">${parseFloat(doc.amount).toFixed(2)} CHF</span>` : ''}
            </div>
        </a>
    `).join('');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// === CHAT IA ===

const chatPanel = document.getElementById('ai-chat-panel');
const chatMessages = document.getElementById('chat-messages');
const chatForm = document.getElementById('chat-form');
const chatInput = document.getElementById('chat-input');

function toggleChatPanel() {
    if (chatPanel) {
        chatPanel.classList.toggle('hidden');
        if (!chatPanel.classList.contains('hidden')) {
            chatInput?.focus();
        }
    }
}

// Questions exemples
document.querySelectorAll('.example-question').forEach(btn => {
    btn.addEventListener('click', function() {
        if (chatInput) {
            chatInput.value = this.textContent.replace(/"/g, '');
            chatForm?.dispatchEvent(new Event('submit'));
        }
    });
});

if (chatForm) {
    chatForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const question = chatInput?.value.trim();
        
        if (!question) return;
        
        // Afficher la question de l'utilisateur
        addChatMessage(question, 'user');
        if (chatInput) chatInput.value = '';
        
        // Afficher indicateur de chargement
        const loadingId = addChatMessage('Recherche en cours...', 'assistant', true);
        
        try {
            const response = await fetch('/kdocs/api/search/ask', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ question })
            });
            
            const data = await response.json();
            
            // Supprimer le message de chargement
            const loadingEl = document.getElementById(loadingId);
            if (loadingEl) loadingEl.remove();
            
            if (data.error) {
                addChatMessage('Erreur: ' + data.error, 'error');
            } else {
                // Afficher la réponse
                addChatMessage(data.answer, 'assistant');
                
                // Afficher les documents trouvés
                if (data.documents && data.documents.length > 0) {
                    addDocumentsList(data.documents);
                }
            }
        } catch (error) {
            const loadingEl = document.getElementById(loadingId);
            if (loadingEl) loadingEl.remove();
            addChatMessage('Erreur de connexion', 'error');
            console.error('Chat error:', error);
        }
    });
}

function addChatMessage(content, type, isLoading = false) {
    if (!chatMessages) return null;
    
    const id = 'msg-' + Date.now();
    const msgDiv = document.createElement('div');
    msgDiv.id = id;
    msgDiv.className = type === 'user' 
        ? 'flex justify-end' 
        : 'flex justify-start';
    
    const bubble = document.createElement('div');
    bubble.className = type === 'user'
        ? 'bg-purple-600 text-white rounded-lg px-4 py-2 max-w-[80%]'
        : type === 'error'
        ? 'bg-red-100 text-red-700 rounded-lg px-4 py-2 max-w-[80%]'
        : 'bg-gray-100 text-gray-800 rounded-lg px-4 py-2 max-w-[80%]';
    
    if (isLoading) {
        bubble.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + escapeHtml(content);
    } else {
        bubble.textContent = content;
    }
    
    msgDiv.appendChild(bubble);
    chatMessages.appendChild(msgDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    return id;
}

function addDocumentsList(documents) {
    if (!chatMessages) return;
    
    const container = document.createElement('div');
    container.className = 'bg-white border rounded-lg p-3 mt-2';
    container.innerHTML = `
        <div class="text-sm font-medium text-gray-700 mb-2">
            <i class="fas fa-file-alt mr-1"></i> ${documents.length} document(s) trouvé(s)
        </div>
        <div class="space-y-2 max-h-48 overflow-y-auto">
            ${documents.slice(0, 5).map(doc => `
                <a href="/kdocs/documents/${doc.id}" class="block p-2 hover:bg-gray-50 rounded border text-sm">
                    <div class="font-medium">${escapeHtml(doc.title || doc.original_filename)}</div>
                    <div class="text-gray-500 text-xs">
                        ${escapeHtml(doc.correspondent_name || '')} 
                        ${doc.document_date ? '• ' + doc.document_date : ''}
                        ${doc.amount ? '• ' + parseFloat(doc.amount).toFixed(2) + ' CHF' : ''}
                    </div>
                </a>
            `).join('')}
        </div>
        ${documents.length > 5 ? `<div class="text-center text-sm text-purple-600 mt-2">+ ${documents.length - 5} autres documents</div>` : ''}
    `;
    
    chatMessages.appendChild(container);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}
