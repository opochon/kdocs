<?php
/**
 * Composant: Formulaire d'envoi de note
 *
 * Variables attendues:
 * - $document_id: (optionnel) ID du document lié
 * - $recipients: liste des destinataires possibles
 * - $modal_id: ID du modal (défaut: noteModal)
 */

$documentId = $document_id ?? null;
$recipients = $recipients ?? [];
$modalId = $modal_id ?? 'noteModal';
?>

<!-- Modal envoi de note -->
<div id="<?= $modalId ?>" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Envoyer une note</h3>
                <button onclick="closeNoteModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form id="noteForm" onsubmit="sendNote(event)">
                <input type="hidden" id="noteDocumentId" name="document_id" value="<?= $documentId ?>">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Destinataire</label>
                    <select id="noteRecipient" name="to_user_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner...</option>
                        <?php foreach ($recipients as $recipient): ?>
                        <option value="<?= $recipient['id'] ?>">
                            <?= htmlspecialchars($recipient['fullname'] ?: $recipient['username']) ?>
                            (<?= htmlspecialchars($recipient['email']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sujet (optionnel)</label>
                    <input type="text" id="noteSubject" name="subject"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Sujet de la note">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                    <textarea id="noteMessage" name="message" rows="4" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Votre message..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" id="noteActionRequired" name="action_required" value="1"
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <span class="text-sm text-gray-700">Action requise du destinataire</span>
                    </label>
                </div>

                <div id="actionTypeContainer" class="mb-4 hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type d'action</label>
                    <select id="noteActionType" name="action_type"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner...</option>
                        <option value="contact">Contacter</option>
                        <option value="review">Relire</option>
                        <option value="approve">Approuver</option>
                        <option value="follow_up">Suivi</option>
                        <option value="other">Autre</option>
                    </select>
                </div>

                <div class="flex gap-3">
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        Envoyer
                    </button>
                    <button type="button" onclick="closeNoteModal()"
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                        Annuler
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle action type visibility
document.getElementById('noteActionRequired')?.addEventListener('change', function() {
    const container = document.getElementById('actionTypeContainer');
    if (container) {
        container.classList.toggle('hidden', !this.checked);
    }
});

// Open note modal
function openNoteModal(documentId = null) {
    const docIdInput = document.getElementById('noteDocumentId');
    if (docIdInput) docIdInput.value = documentId || '';

    const modal = document.getElementById('<?= $modalId ?>');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

// Close note modal
function closeNoteModal() {
    const modal = document.getElementById('<?= $modalId ?>');
    const form = document.getElementById('noteForm');
    const actionTypeContainer = document.getElementById('actionTypeContainer');

    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
    if (form) form.reset();
    if (actionTypeContainer) actionTypeContainer.classList.add('hidden');
}

// Send note
async function sendNote(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    const data = {
        to_user_id: parseInt(formData.get('to_user_id')),
        message: formData.get('message'),
        subject: formData.get('subject') || null,
        document_id: formData.get('document_id') ? parseInt(formData.get('document_id')) : null,
        action_required: formData.get('action_required') === '1',
        action_type: formData.get('action_type') || null
    };

    try {
        const response = await fetch('<?= url('/api/notes') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            closeNoteModal();
            if (typeof showToast === 'function') {
                showToast('Note envoyée avec succès', 'success');
            } else {
                alert('Note envoyée avec succès');
            }
        } else {
            if (typeof showToast === 'function') {
                showToast(result.message || 'Erreur lors de l\'envoi', 'error');
            } else {
                alert(result.message || 'Erreur lors de l\'envoi');
            }
        }
    } catch (error) {
        console.error('Error sending note:', error);
        if (typeof showToast === 'function') {
            showToast('Erreur de connexion', 'error');
        } else {
            alert('Erreur de connexion');
        }
    }
}

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeNoteModal();
});
</script>
