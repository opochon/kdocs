<?php
/**
 * Composant: Boutons d'action de validation
 *
 * Variables attendues:
 * - $document_id: ID du document
 * - $validation_status: statut actuel ('approved', 'rejected', 'pending', 'na', null)
 * - $can_validate: boolean (l'utilisateur peut-il valider)
 * - $requires_approval: boolean (le document nécessite-t-il une approbation)
 * - $show_submit: boolean (afficher le bouton soumettre)
 * - $compact: boolean (mode compact pour liste)
 */

$documentId = $document_id ?? 0;
$status = $validation_status ?? null;
$canValidate = $can_validate ?? false;
$requiresApproval = $requires_approval ?? false;
$showSubmit = $show_submit ?? true;
$compact = $compact ?? false;

$btnClass = $compact
    ? 'inline-flex items-center gap-1 px-2 py-1 text-xs rounded transition-colors'
    : 'inline-flex items-center gap-2 px-4 py-2 rounded-lg transition-colors';
?>

<div class="validation-actions flex flex-wrap gap-2" data-document-id="<?= $documentId ?>">
    <?php if ($canValidate): ?>
        <!-- Sélecteur de statut de validation -->
        <div class="flex items-center gap-1">
            <button type="button"
                    onclick="setValidationStatus(<?= $documentId ?>, 'approved')"
                    class="<?= $btnClass ?> <?= $status === 'approved' ? 'bg-green-600 text-white' : 'bg-green-100 text-green-700 hover:bg-green-200' ?>"
                    title="Marquer comme Validé">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <?php if (!$compact): ?>Validé<?php endif; ?>
            </button>
            <button type="button"
                    onclick="setValidationStatus(<?= $documentId ?>, 'rejected')"
                    class="<?= $btnClass ?> <?= $status === 'rejected' ? 'bg-red-600 text-white' : 'bg-red-100 text-red-700 hover:bg-red-200' ?>"
                    title="Marquer comme Non validé">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <?php if (!$compact): ?>Non validé<?php endif; ?>
            </button>
            <button type="button"
                    onclick="setValidationStatus(<?= $documentId ?>, 'na')"
                    class="<?= $btnClass ?> <?= ($status === 'na' || $status === null) ? 'bg-gray-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>"
                    title="Marquer comme N/A (non applicable)">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
                <?php if (!$compact): ?>N/A<?php endif; ?>
            </button>
        </div>
    <?php else: ?>
        <!-- Affichage en lecture seule -->
        <?php if ($status === 'approved'): ?>
            <span class="<?= $btnClass ?> bg-green-100 text-green-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Validé
            </span>
        <?php elseif ($status === 'rejected'): ?>
            <span class="<?= $btnClass ?> bg-red-100 text-red-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Non validé
            </span>
        <?php elseif ($status === 'pending'): ?>
            <span class="<?= $btnClass ?> bg-yellow-100 text-yellow-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                En attente
            </span>
        <?php else: ?>
            <span class="<?= $btnClass ?> bg-gray-100 text-gray-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                </svg>
                N/A
            </span>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function setValidationStatus(documentId, status) {
    const statusLabels = {
        'approved': 'Validé',
        'rejected': 'Non validé',
        'na': 'N/A'
    };

    // Demander commentaire seulement pour approved/rejected
    let comment = null;
    if (status !== 'na') {
        comment = prompt(`Commentaire pour "${statusLabels[status]}" (optionnel):`);
        if (comment === null) return; // Annulé
    }

    fetch(`<?= $basePath ?? '' ?>/api/validation/${documentId}/status`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status, comment })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (typeof window.showToast === 'function') {
                window.showToast(`Statut mis à jour: ${statusLabels[status]}`, 'success');
            }
            location.reload();
        } else {
            const errorMsg = data.error || 'Erreur lors de la mise à jour';
            if (typeof window.showToast === 'function') {
                window.showToast(errorMsg, 'error');
            } else {
                alert(errorMsg);
            }
        }
    })
    .catch(e => {
        console.error('Error:', e);
        if (typeof window.showToast === 'function') {
            window.showToast('Erreur réseau', 'error');
        } else {
            alert('Erreur réseau');
        }
    });
}
</script>
