/**
 * K-Docs - User Notes Module
 * Gestion des notes inter-utilisateurs
 */

const KDocsNotes = (function() {
    'use strict';

    let config = {
        basePath: ''
    };

    let state = {
        recipients: [],
        currentDocumentId: null
    };

    /**
     * Initialize the notes module
     */
    function init(options = {}) {
        config = { ...config, ...options };
        setupEventListeners();
        console.log('[UserNotes] Initialized');
    }

    /**
     * Setup event listeners
     */
    function setupEventListeners() {
        // Action required checkbox toggle
        const actionCheckbox = document.getElementById('noteActionRequired');
        const actionTypeContainer = document.getElementById('actionTypeContainer');

        if (actionCheckbox && actionTypeContainer) {
            actionCheckbox.addEventListener('change', function() {
                actionTypeContainer.classList.toggle('hidden', !this.checked);
            });
        }

        // Modal close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeNoteModal();
            }
        });
    }

    /**
     * Load available recipients
     */
    async function loadRecipients() {
        try {
            const response = await fetch(`${config.basePath}/api/notes/recipients`);
            const data = await response.json();

            if (data.success) {
                state.recipients = data.recipients || [];
                populateRecipientSelect();
            }
        } catch (error) {
            console.error('[UserNotes] Load recipients error:', error);
        }
    }

    /**
     * Populate recipient select dropdown
     */
    function populateRecipientSelect() {
        const select = document.getElementById('noteRecipient');
        if (!select) return;

        // Keep first option
        const firstOption = select.querySelector('option');
        select.innerHTML = '';
        if (firstOption) select.appendChild(firstOption);

        state.recipients.forEach(recipient => {
            const option = document.createElement('option');
            option.value = recipient.id;
            option.textContent = `${recipient.fullname || recipient.username} (${recipient.email})`;
            select.appendChild(option);
        });
    }

    /**
     * Open note modal
     */
    function openNoteModal(documentId = null) {
        state.currentDocumentId = documentId;

        const modal = document.getElementById('noteModal');
        const docIdInput = document.getElementById('noteDocumentId');

        if (docIdInput) {
            docIdInput.value = documentId || '';
        }

        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            // Focus on recipient select
            setTimeout(() => {
                const recipientSelect = document.getElementById('noteRecipient');
                if (recipientSelect) recipientSelect.focus();
            }, 100);
        }

        // Load recipients if not already loaded
        if (state.recipients.length === 0) {
            loadRecipients();
        }
    }

    /**
     * Close note modal
     */
    function closeNoteModal() {
        const modal = document.getElementById('noteModal');
        const form = document.getElementById('noteForm');
        const actionTypeContainer = document.getElementById('actionTypeContainer');

        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        if (form) {
            form.reset();
        }

        if (actionTypeContainer) {
            actionTypeContainer.classList.add('hidden');
        }

        state.currentDocumentId = null;
    }

    /**
     * Send a note
     */
    async function sendNote(formData) {
        const data = {
            to_user_id: parseInt(formData.get('to_user_id')),
            message: formData.get('message'),
            subject: formData.get('subject') || null,
            document_id: formData.get('document_id') ? parseInt(formData.get('document_id')) : null,
            action_required: formData.get('action_required') === '1',
            action_type: formData.get('action_type') || null
        };

        try {
            const response = await fetch(`${config.basePath}/api/notes`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                closeNoteModal();
                showToast('Note envoyee avec succes', 'success');
                return { success: true, noteId: result.note_id };
            } else {
                showToast(result.error || 'Erreur lors de l\'envoi', 'error');
                return { success: false, error: result.error };
            }
        } catch (error) {
            console.error('[UserNotes] Send error:', error);
            showToast('Erreur de connexion', 'error');
            return { success: false, error: 'Erreur de connexion' };
        }
    }

    /**
     * Reply to a note
     */
    async function replyToNote(noteId, message) {
        try {
            const response = await fetch(`${config.basePath}/api/notes/${noteId}/reply`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });

            const result = await response.json();

            if (result.success) {
                showToast('Reponse envoyee', 'success');
                return { success: true, noteId: result.note_id };
            } else {
                showToast(result.error || 'Erreur', 'error');
                return { success: false, error: result.error };
            }
        } catch (error) {
            console.error('[UserNotes] Reply error:', error);
            showToast('Erreur de connexion', 'error');
            return { success: false, error: 'Erreur de connexion' };
        }
    }

    /**
     * Mark note action as complete
     */
    async function markActionComplete(noteId) {
        try {
            const response = await fetch(`${config.basePath}/api/notes/${noteId}/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });

            const result = await response.json();

            if (result.success) {
                showToast('Action marquee comme terminee', 'success');
                return true;
            } else {
                showToast(result.error || 'Erreur', 'error');
                return false;
            }
        } catch (error) {
            console.error('[UserNotes] Complete action error:', error);
            showToast('Erreur de connexion', 'error');
            return false;
        }
    }

    /**
     * Get notes for a document
     */
    async function getNotesForDocument(documentId) {
        try {
            const response = await fetch(`${config.basePath}/api/notes/document/${documentId}`);
            const data = await response.json();

            if (data.success) {
                return data.notes || [];
            }
            return [];
        } catch (error) {
            console.error('[UserNotes] Get notes error:', error);
            return [];
        }
    }

    /**
     * Get note thread
     */
    async function getNoteThread(noteId) {
        try {
            const response = await fetch(`${config.basePath}/api/notes/${noteId}/thread`);
            const data = await response.json();

            if (data.success) {
                return data.thread || [];
            }
            return [];
        } catch (error) {
            console.error('[UserNotes] Get thread error:', error);
            return [];
        }
    }

    /**
     * Render note thread in a container
     */
    function renderNoteThread(container, thread) {
        if (!container || !thread.length) return;

        container.innerHTML = thread.map(note => `
            <div class="note-message p-3 rounded-lg mb-2 ${note.from_user_id === window.currentUserId ? 'bg-blue-50 ml-4' : 'bg-gray-50 mr-4'}">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm font-medium text-gray-900">${escapeHtml(note.from_fullname || note.from_username)}</span>
                    <span class="text-xs text-gray-500">${formatDate(note.created_at)}</span>
                </div>
                <p class="text-sm text-gray-700">${escapeHtml(note.message)}</p>
                ${note.action_required && !note.action_completed_at ? `
                    <div class="mt-2 flex items-center gap-2">
                        <span class="text-xs text-orange-600">Action requise: ${note.action_type || 'A definir'}</span>
                        ${note.to_user_id === window.currentUserId ? `
                            <button onclick="KDocsNotes.markActionComplete(${note.id})" class="text-xs text-green-600 hover:underline">
                                Marquer terminee
                            </button>
                        ` : ''}
                    </div>
                ` : ''}
                ${note.action_completed_at ? `
                    <div class="mt-2">
                        <span class="text-xs text-green-600">Action terminee le ${formatDate(note.action_completed_at)}</span>
                    </div>
                ` : ''}
            </div>
        `).join('');
    }

    /**
     * Helper: Show toast notification
     */
    function showToast(message, type = 'info') {
        if (typeof KDocsNotifications !== 'undefined' && KDocsNotifications.showToast) {
            KDocsNotifications.showToast(message, type);
        } else {
            // Fallback
            alert(message);
        }
    }

    /**
     * Helper: Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    /**
     * Helper: Format date
     */
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }

    // Public API
    return {
        init,
        openNoteModal,
        closeNoteModal,
        sendNote,
        replyToNote,
        markActionComplete,
        getNotesForDocument,
        getNoteThread,
        renderNoteThread,
        loadRecipients
    };
})();

// Global functions for inline handlers
window.openNoteModal = function(documentId) {
    KDocsNotes.openNoteModal(documentId);
};

window.closeNoteModal = function() {
    KDocsNotes.closeNoteModal();
};

window.sendNote = async function(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    await KDocsNotes.sendNote(formData);
};

// Auto-initialize
document.addEventListener('DOMContentLoaded', function() {
    const basePath = document.body.dataset.basePath || '';
    KDocsNotes.init({ basePath });
});
