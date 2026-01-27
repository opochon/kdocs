<?php
/**
 * Composant: Affichage d'un thread de notes
 *
 * Variables attendues:
 * - $notes: array des notes du thread
 * - $current_user_id: ID de l'utilisateur actuel
 * - $document_id: (optionnel) ID du document
 */

$notes = $notes ?? [];
$currentUserId = $current_user_id ?? 0;
$documentId = $document_id ?? null;
?>

<div class="note-thread space-y-3">
    <?php if (empty($notes)): ?>
    <p class="text-gray-500 text-sm text-center py-4">Aucune note pour le moment.</p>
    <?php else: ?>
    <?php foreach ($notes as $note):
        $isFromMe = ($note['from_user_id'] == $currentUserId);
        $senderName = $note['from_fullname'] ?? $note['from_username'] ?? 'Utilisateur';
    ?>
    <div class="note-message p-3 rounded-lg <?= $isFromMe ? 'bg-blue-50 ml-4' : 'bg-gray-50 mr-4' ?>">
        <!-- Header -->
        <div class="flex items-center justify-between mb-1">
            <span class="text-sm font-medium text-gray-900">
                <?= $isFromMe ? 'Vous' : htmlspecialchars($senderName) ?>
            </span>
            <span class="text-xs text-gray-500">
                <?= date('d/m/Y H:i', strtotime($note['created_at'])) ?>
            </span>
        </div>

        <!-- Subject -->
        <?php if (!empty($note['subject'])): ?>
        <div class="text-xs text-gray-600 mb-1 font-medium">
            <?= htmlspecialchars($note['subject']) ?>
        </div>
        <?php endif; ?>

        <!-- Message -->
        <p class="text-sm text-gray-700 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($note['message'])) ?></p>

        <!-- Action required -->
        <?php if (!empty($note['action_required']) && empty($note['action_completed_at'])): ?>
        <div class="mt-2 flex items-center gap-2">
            <span class="inline-flex items-center gap-1 text-xs text-orange-600">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                Action requise<?= $note['action_type'] ? ': ' . htmlspecialchars($note['action_type']) : '' ?>
            </span>
            <?php if ($note['to_user_id'] == $currentUserId): ?>
            <button onclick="markNoteActionComplete(<?= $note['id'] ?>)"
                    class="text-xs text-green-600 hover:text-green-800 hover:underline">
                Marquer comme terminée
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Action completed -->
        <?php if (!empty($note['action_completed_at'])): ?>
        <div class="mt-2">
            <span class="inline-flex items-center gap-1 text-xs text-green-600">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Action terminée le <?= date('d/m/Y', strtotime($note['action_completed_at'])) ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- Read status for sent notes -->
        <?php if ($isFromMe && isset($note['is_read'])): ?>
        <div class="mt-1 text-right">
            <span class="text-xs <?= $note['is_read'] ? 'text-blue-500' : 'text-gray-400' ?>">
                <?= $note['is_read'] ? 'Lu' : 'Non lu' ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($notes) || $documentId): ?>
<!-- Reply form -->
<div class="mt-4 pt-4 border-t border-gray-200">
    <form onsubmit="replyToThread(event)" class="space-y-3">
        <input type="hidden" id="replyParentId" value="<?= !empty($notes) ? $notes[0]['id'] : '' ?>">
        <textarea id="replyMessage" rows="2" required
                  class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Répondre..."></textarea>
        <button type="submit"
                class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            Répondre
        </button>
    </form>
</div>

<script>
async function replyToThread(event) {
    event.preventDefault();
    const parentId = document.getElementById('replyParentId').value;
    const message = document.getElementById('replyMessage').value;

    if (!parentId || !message.trim()) return;

    try {
        const response = await fetch(`<?= url('/api/notes/') ?>${parentId}/reply`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message })
        });

        const result = await response.json();

        if (result.success) {
            document.getElementById('replyMessage').value = '';
            location.reload(); // Refresh to show new reply
        } else {
            alert(result.error || 'Erreur lors de l\'envoi');
        }
    } catch (error) {
        console.error('Error replying:', error);
        alert('Erreur de connexion');
    }
}

async function markNoteActionComplete(noteId) {
    try {
        const response = await fetch(`<?= url('/api/notes/') ?>${noteId}/complete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });

        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            alert(result.error || 'Erreur');
        }
    } catch (error) {
        console.error('Error completing action:', error);
        alert('Erreur de connexion');
    }
}
</script>
<?php endif; ?>
