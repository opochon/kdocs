<?php
// Page dédiée à la Recherche avancée avec historique des conversations
use KDocs\Core\Config;
use KDocs\Services\ChatHistoryService;

$base = Config::basePath();
$userId = $user['id'] ?? 0;

// Charger les conversations récentes
$chatService = new ChatHistoryService();
$conversations = $userId ? $chatService->getRecentConversations($userId) : [];

// Vérifier si Claude est configuré
$claudeService = new \KDocs\Services\ClaudeService();
$isConfigured = $claudeService->isConfigured();
?>

<div class="flex h-full -m-6">
    <!-- Sidebar des conversations -->
    <div id="chat-sidebar" class="w-64 bg-gray-50 border-r border-gray-200 flex flex-col h-full">
        <!-- Header sidebar -->
        <div class="p-3 border-b border-gray-200">
            <button id="new-chat-btn" class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Nouvelle conversation
            </button>
        </div>

        <!-- Liste des conversations -->
        <div id="conversations-list" class="flex-1 overflow-y-auto">
            <?php if (empty($conversations)): ?>
            <div class="p-4 text-center text-gray-500 text-sm">
                Aucune conversation
            </div>
            <?php else: ?>
            <?php foreach ($conversations as $conv): ?>
            <div class="conversation-item px-3 py-2 mx-2 my-1 rounded-lg cursor-pointer hover:bg-gray-100 transition-colors group"
                 data-id="<?= $conv['id'] ?>">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($conv['title']) ?></div>
                        <div class="text-xs text-gray-500 mt-0.5">
                            <?= $conv['message_count'] ?> message<?= $conv['message_count'] > 1 ? 's' : '' ?>
                            · <?= date('d/m', strtotime($conv['updated_at'])) ?>
                        </div>
                    </div>
                    <button class="delete-conv-btn opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-red-500 transition-all"
                            data-id="<?= $conv['id'] ?>" title="Supprimer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Zone de chat principale -->
    <div class="flex-1 flex flex-col h-full bg-white">
        <?php if (!$isConfigured): ?>
        <!-- API non configurée -->
        <div class="flex-1 flex items-center justify-center p-6">
            <div class="text-center max-w-md">
                <svg class="w-16 h-16 text-yellow-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">API Claude non configurée</h3>
                <p class="text-sm text-gray-500 mb-4">Configurez votre clé API Claude pour utiliser la recherche intelligente.</p>
                <a href="<?= url('/admin/settings#ai') ?>" class="inline-block px-4 py-2 bg-gray-900 text-white text-sm rounded-lg hover:bg-gray-800">
                    Configurer
                </a>
            </div>
        </div>
        <?php else: ?>
        <!-- Header conversation -->
        <div id="chat-header" class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h2 id="chat-title" class="text-sm font-medium text-gray-700">Nouvelle conversation</h2>
        </div>

        <!-- Messages -->
        <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-4">
            <!-- Welcome message -->
            <div id="welcome-message" class="text-center py-8">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 mb-4">
                    <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Recherche intelligente</h3>
                <p class="text-sm text-gray-500 mb-4">Posez vos questions en langage naturel</p>
                <div class="flex flex-wrap justify-center gap-2 max-w-lg mx-auto">
                    <button onclick="askQuestion('Combien de documents ?')" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-full text-gray-700 transition-colors">
                        Combien de documents ?
                    </button>
                    <button onclick="askQuestion('Documents de cette semaine')" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-full text-gray-700 transition-colors">
                        Documents de cette semaine
                    </button>
                    <button onclick="askQuestion('Combien de fois le mot contrat apparait ?')" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-full text-gray-700 transition-colors">
                        Combien de fois "contrat" ?
                    </button>
                </div>
            </div>
        </div>

        <!-- Input -->
        <div class="border-t border-gray-100 p-4 bg-gray-50">
            <form id="chat-form" class="flex gap-2">
                <input type="text" id="chat-input"
                       class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-gray-400 text-sm"
                       placeholder="Posez votre question..." autocomplete="off">
                <button type="submit" id="send-btn"
                        class="px-4 py-2.5 bg-gray-900 text-white rounded-lg hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    'use strict';

    const BASE_URL = '<?= $base ?>';
    let currentConversationId = null;

    // Elements
    const sidebar = document.getElementById('chat-sidebar');
    const conversationsList = document.getElementById('conversations-list');
    const messagesContainer = document.getElementById('chat-messages');
    const welcomeMessage = document.getElementById('welcome-message');
    const chatTitle = document.getElementById('chat-title');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const newChatBtn = document.getElementById('new-chat-btn');

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // Format markdown to HTML
    function formatMarkdown(text) {
        let html = escapeHtml(text);
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\n/g, '<br>');
        return html;
    }

    // Add message to UI
    function addMessageToUI(content, role, metadata = null) {
        if (welcomeMessage) welcomeMessage.style.display = 'none';

        const messageDiv = document.createElement('div');
        messageDiv.className = `flex ${role === 'user' ? 'justify-end' : 'justify-start'} mb-4`;

        const bubble = document.createElement('div');
        bubble.className = `max-w-2xl px-4 py-3 rounded-lg ${
            role === 'user'
                ? 'bg-gray-900 text-white'
                : 'bg-gray-100 text-gray-900'
        }`;

        if (role === 'assistant') {
            bubble.innerHTML = `<div class="text-sm leading-relaxed">${formatMarkdown(content)}</div>`;

            // Add documents if available
            if (metadata && metadata.documents && metadata.documents.length > 0) {
                const docsDiv = document.createElement('div');
                docsDiv.className = 'mt-3 pt-3 border-t border-gray-200 space-y-2';

                metadata.documents.slice(0, 5).forEach(doc => {
                    const docItem = document.createElement('a');
                    docItem.href = `${BASE_URL}/documents/${doc.id}`;
                    docItem.target = '_blank';
                    docItem.className = 'flex items-center gap-2 p-2 bg-white rounded border border-gray-200 hover:border-gray-300 transition-colors';

                    let scoreHtml = '';
                    if (doc.relevance_score !== undefined) {
                        const score = doc.relevance_score;
                        let color = 'text-gray-500';
                        if (score >= 70) color = 'text-green-600';
                        else if (score >= 40) color = 'text-yellow-600';
                        scoreHtml = `<span class="text-xs font-medium ${color}">${score}%</span>`;
                    }

                    docItem.innerHTML = `
                        <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span class="flex-1 text-xs text-gray-700 truncate">${escapeHtml(doc.title)}</span>
                        ${scoreHtml}
                        <svg class="w-3 h-3 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    `;

                    docsDiv.appendChild(docItem);
                });

                if (metadata.total > 5) {
                    const moreDiv = document.createElement('div');
                    moreDiv.className = 'text-xs text-gray-500 text-center pt-1';
                    moreDiv.textContent = `+ ${metadata.total - 5} autres documents`;
                    docsDiv.appendChild(moreDiv);
                }

                bubble.appendChild(docsDiv);
            }
        } else {
            bubble.textContent = content;
        }

        messageDiv.appendChild(bubble);
        messagesContainer.appendChild(messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Add loading indicator
    function addLoadingIndicator() {
        const loadingDiv = document.createElement('div');
        loadingDiv.id = 'loading-indicator';
        loadingDiv.className = 'flex justify-start mb-4';
        loadingDiv.innerHTML = `
            <div class="bg-gray-100 px-4 py-3 rounded-lg">
                <div class="flex items-center gap-1">
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                </div>
            </div>
        `;
        messagesContainer.appendChild(loadingDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function removeLoadingIndicator() {
        const loading = document.getElementById('loading-indicator');
        if (loading) loading.remove();
    }

    // Create new conversation
    async function createNewConversation() {
        try {
            const response = await fetch(`${BASE_URL}/api/chat/conversations`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            const data = await response.json();

            if (data.success) {
                currentConversationId = data.conversation.id;
                chatTitle.textContent = 'Nouvelle conversation';

                // Clear messages
                messagesContainer.innerHTML = '';
                if (welcomeMessage) {
                    messagesContainer.appendChild(welcomeMessage);
                    welcomeMessage.style.display = 'block';
                }

                // Add to sidebar
                addConversationToSidebar(data.conversation);
                highlightActiveConversation();
            }
        } catch (error) {
            console.error('Error creating conversation:', error);
        }
    }

    // Add conversation to sidebar
    function addConversationToSidebar(conv) {
        const emptyMsg = conversationsList.querySelector('.text-center');
        if (emptyMsg) emptyMsg.remove();

        const existingItem = conversationsList.querySelector(`[data-id="${conv.id}"]`);
        if (existingItem) return;

        const item = document.createElement('div');
        item.className = 'conversation-item px-3 py-2 mx-2 my-1 rounded-lg cursor-pointer hover:bg-gray-100 transition-colors group';
        item.dataset.id = conv.id;
        item.innerHTML = `
            <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900 truncate">${escapeHtml(conv.title)}</div>
                    <div class="text-xs text-gray-500 mt-0.5">0 messages · maintenant</div>
                </div>
                <button class="delete-conv-btn opacity-0 group-hover:opacity-100 p-1 text-gray-400 hover:text-red-500 transition-all"
                        data-id="${conv.id}" title="Supprimer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </div>
        `;

        conversationsList.insertBefore(item, conversationsList.firstChild);
        bindConversationEvents(item);
    }

    // Load conversation
    async function loadConversation(id) {
        try {
            const response = await fetch(`${BASE_URL}/api/chat/conversations/${id}`);
            const data = await response.json();

            if (data.success) {
                currentConversationId = data.conversation.id;
                chatTitle.textContent = data.conversation.title;

                // Clear and load messages
                messagesContainer.innerHTML = '';

                if (data.conversation.messages && data.conversation.messages.length > 0) {
                    data.conversation.messages.forEach(msg => {
                        const metadata = msg.metadata ? JSON.parse(msg.metadata) : null;
                        addMessageToUI(msg.content, msg.role, metadata);
                    });
                } else {
                    if (welcomeMessage) {
                        messagesContainer.appendChild(welcomeMessage);
                        welcomeMessage.style.display = 'block';
                    }
                }

                highlightActiveConversation();
            }
        } catch (error) {
            console.error('Error loading conversation:', error);
        }
    }

    // Highlight active conversation
    function highlightActiveConversation() {
        document.querySelectorAll('.conversation-item').forEach(item => {
            if (parseInt(item.dataset.id) === currentConversationId) {
                item.classList.add('bg-gray-200');
            } else {
                item.classList.remove('bg-gray-200');
            }
        });
    }

    // Delete conversation
    async function deleteConversation(id) {
        if (!confirm('Supprimer cette conversation ?')) return;

        try {
            await fetch(`${BASE_URL}/api/chat/conversations/${id}`, { method: 'DELETE' });

            const item = conversationsList.querySelector(`[data-id="${id}"]`);
            if (item) item.remove();

            if (currentConversationId === id) {
                // Load first available or create new
                const firstItem = conversationsList.querySelector('.conversation-item');
                if (firstItem) {
                    loadConversation(parseInt(firstItem.dataset.id));
                } else {
                    createNewConversation();
                }
            }
        } catch (error) {
            console.error('Error deleting conversation:', error);
        }
    }

    // Send message
    async function sendMessage(message) {
        if (!message.trim()) return;

        // Create conversation if needed
        if (!currentConversationId) {
            await createNewConversation();
        }

        // Add user message to UI
        addMessageToUI(message, 'user');
        chatInput.value = '';

        // Show loading
        addLoadingIndicator();

        try {
            const response = await fetch(`${BASE_URL}/api/chat/conversations/${currentConversationId}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });

            const data = await response.json();
            removeLoadingIndicator();

            // Add assistant response
            const metadata = {
                documents: data.documents?.map(d => ({
                    id: d.id,
                    title: d.title || d.filename,
                    relevance_score: d.relevance_score,
                    excerpts: d.excerpts
                })) || [],
                total: data.total || 0
            };

            addMessageToUI(data.answer || 'Réponse reçue', 'assistant', metadata);

            // Update sidebar title
            updateConversationInSidebar(currentConversationId, message);

        } catch (error) {
            removeLoadingIndicator();
            addMessageToUI('Erreur de connexion: ' + error.message, 'assistant');
        }
    }

    // Update conversation in sidebar
    function updateConversationInSidebar(id, firstMessage) {
        const item = conversationsList.querySelector(`[data-id="${id}"]`);
        if (item) {
            const titleEl = item.querySelector('.font-medium');
            if (titleEl && titleEl.textContent === 'Nouvelle conversation') {
                const title = firstMessage.substring(0, 40) + (firstMessage.length > 40 ? '...' : '');
                titleEl.textContent = title;
                chatTitle.textContent = title;
            }

            // Move to top
            conversationsList.insertBefore(item, conversationsList.firstChild);
        }
    }

    // Bind events to conversation item
    function bindConversationEvents(item) {
        item.addEventListener('click', (e) => {
            if (!e.target.closest('.delete-conv-btn')) {
                loadConversation(parseInt(item.dataset.id));
            }
        });

        const deleteBtn = item.querySelector('.delete-conv-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                deleteConversation(parseInt(deleteBtn.dataset.id));
            });
        }
    }

    // Global function for quick questions
    window.askQuestion = function(question) {
        chatInput.value = question;
        sendMessage(question);
    };

    // Initialize
    function init() {
        // Bind events to existing conversations
        document.querySelectorAll('.conversation-item').forEach(bindConversationEvents);

        // New chat button
        if (newChatBtn) {
            newChatBtn.addEventListener('click', createNewConversation);
        }

        // Form submit
        if (chatForm) {
            chatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                sendMessage(chatInput.value);
            });
        }

        // Load last conversation or create new
        const firstConv = document.querySelector('.conversation-item');
        if (firstConv) {
            loadConversation(parseInt(firstConv.dataset.id));
        }

        // Focus input
        if (chatInput) chatInput.focus();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
</script>

<style>
@keyframes bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}
.animate-bounce {
    animation: bounce 0.6s infinite;
}
mark {
    background-color: #fef08a;
    padding: 0 2px;
    border-radius: 2px;
}
</style>
