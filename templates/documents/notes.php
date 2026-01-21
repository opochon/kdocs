<?php
// Section Notes sur document (Phase 2.4)
// $documentId et $notes sont passés
?>

<div class="border-t pt-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-800">📝 Notes</h3>
        <button onclick="openAddNoteModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
            + Ajouter une note
        </button>
    </div>
    
    <?php if (empty($notes)): ?>
    <p class="text-gray-500 text-sm">Aucune note pour ce document.</p>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($notes as $note): ?>
        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
            <div class="flex items-start justify-between mb-2">
                <div class="flex-1">
                    <p class="text-sm text-gray-700 whitespace-pre-wrap"><?= htmlspecialchars($note['note']) ?></p>
                </div>
                <button onclick="deleteNote(<?= $note['id'] ?>)" class="ml-2 text-red-600 hover:text-red-800 text-sm">
                    ✕
                </button>
            </div>
            <div class="flex items-center justify-between text-xs text-gray-500 mt-2">
                <span>
                    <?php if ($note['user_name']): ?>
                        Par <?= htmlspecialchars($note['user_name']) ?>
                    <?php else: ?>
                        Par utilisateur inconnu
                    <?php endif; ?>
                </span>
                <span><?= date('d/m/Y à H:i', strtotime($note['created_at'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Ajout Note -->
<div id="add-note-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full">
        <div class="bg-gray-800 text-white px-6 py-4 flex items-center justify-between rounded-t-lg">
            <h3 class="text-lg font-semibold">Ajouter une note</h3>
            <button onclick="closeAddNoteModal()" class="text-white hover:text-gray-300">&times;</button>
        </div>
        <form id="add-note-form" onsubmit="saveNote(event)" class="p-6">
            <div>
                <label for="note-text" class="block text-sm font-medium text-gray-700 mb-2">Note</label>
                <textarea id="note-text" 
                          name="note"
                          rows="5"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                          required></textarea>
            </div>
            <div class="flex justify-end gap-2 mt-4">
                <button type="button" onclick="closeAddNoteModal()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddNoteModal() {
    document.getElementById('add-note-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeAddNoteModal() {
    document.getElementById('add-note-modal').classList.add('hidden');
    document.body.style.overflow = '';
    document.getElementById('add-note-form').reset();
}

function saveNote(event) {
    event.preventDefault();
    const noteText = document.getElementById('note-text').value;
    const documentId = <?= $documentId ?? 0 ?>;
    
    if (!noteText.trim()) {
        if (typeof showToast !== 'undefined') {
            showToast('Veuillez saisir une note', 'warning');
        }
        return;
    }
    
    fetch('<?= url('/api/documents/' . ($documentId ?? 0) . '/notes') ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            note: noteText
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof showToast !== 'undefined') {
                showToast('Note ajoutée', 'success');
            }
            closeAddNoteModal();
            setTimeout(() => window.location.reload(), 500);
        } else {
            if (typeof showToast !== 'undefined') {
                showToast('Erreur : ' + (data.error || 'Impossible d\'ajouter la note'), 'error');
            }
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        if (typeof showToast !== 'undefined') {
            showToast('Erreur lors de l\'ajout de la note', 'error');
        }
    });
}

function deleteNote(noteId) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer cette note ?')) {
        return;
    }
    
    const documentId = <?= $documentId ?? 0 ?>;
    
    fetch('<?= url('/api/documents/' . ($documentId ?? 0) . '/notes') ?>/' + noteId, {
        method: 'DELETE'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (typeof showToast !== 'undefined') {
                showToast('Note supprimée', 'success');
            }
            setTimeout(() => window.location.reload(), 500);
        } else {
            if (typeof showToast !== 'undefined') {
                showToast('Erreur : ' + (data.error || 'Impossible de supprimer la note'), 'error');
            }
        }
    })
    .catch(error => {
        console.error('Erreur:', error);
        if (typeof showToast !== 'undefined') {
            showToast('Erreur lors de la suppression', 'error');
        }
    });
}
</script>
