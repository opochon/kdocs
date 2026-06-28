<?php
/**
 * Template: Mes Tâches
 * Page centralisée pour toutes les tâches utilisateur
 */

use KDocs\Core\Config;
$base = Config::basePath();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php $headFull = true; $headTitle = ($pageTitle ?? 'À traiter') . ' - K-Docs'; include __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="ds-shell">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <?php include __DIR__ . '/../partials/sidebar.php'; ?>

        <!-- Main content -->
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <?php include __DIR__ . '/../partials/header.php'; ?>

            <!-- Page content -->
            <main class="flex-1 p-6">
                <div class="max-w-7xl mx-auto">
                    <!-- Page header -->
                    <div class="mb-6">
                        <h1 class="text-2xl font-semibold" style="color:var(--ink)">À traiter</h1>
                        <p class="text-sm mt-1" style="color:var(--dim)">
                            Validation, classement et workflows — <?= $counts['total'] ?> élément(s)
                            <?php if ($counts['urgent'] > 0): ?>
                            <span class="font-medium" style="color:var(--red)">(<?= $counts['urgent'] ?> urgente(s))</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Tabs -->
                    <div class="border-b mb-6" style="border-color:var(--border)">
                        <nav class="flex gap-4" aria-label="Tabs">
                            <a href="<?= url('/mes-taches') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2"
                               style="<?= $activeTab === 'all' ? 'color:var(--accent);border-color:var(--accent)' : 'color:var(--dim);border-color:transparent' ?>">
                                Toutes
                                <?php if ($counts['total'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'all' ? 'ds-chip--accent' : 'ds-chip--neutral' ?>">
                                    <?= $counts['total'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=validation') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2"
                               style="<?= $activeTab === 'validation' ? 'color:var(--accent);border-color:var(--accent)' : 'color:var(--dim);border-color:transparent' ?>">
                                A valider
                                <?php if ($counts['validation'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'validation' ? 'ds-chip--accent' : 'ds-chip--neutral' ?>">
                                    <?= $counts['validation'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=consume') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2"
                               style="<?= $activeTab === 'consume' ? 'color:var(--accent);border-color:var(--accent)' : 'color:var(--dim);border-color:transparent' ?>">
                                A classer
                                <?php if ($counts['consume'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'consume' ? 'ds-chip--accent' : 'ds-chip--neutral' ?>">
                                    <?= $counts['consume'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=workflow') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2"
                               style="<?= $activeTab === 'workflow' ? 'color:var(--accent);border-color:var(--accent)' : 'color:var(--dim);border-color:transparent' ?>">
                                Workflows
                                <?php if ($counts['workflow'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'workflow' ? 'ds-chip--accent' : 'ds-chip--neutral' ?>">
                                    <?= $counts['workflow'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=note') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2"
                               style="<?= $activeTab === 'note' ? 'color:var(--accent);border-color:var(--accent)' : 'color:var(--dim);border-color:transparent' ?>">
                                Notes
                                <?php if ($counts['notes'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'note' ? 'ds-chip--accent' : 'ds-chip--neutral' ?>">
                                    <?= $counts['notes'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                        </nav>
                    </div>

                    <!-- Tasks list -->
                    <?php if (empty($tasks)): ?>
                    <div class="text-center py-12">
                        <svg class="mx-auto h-12 w-12" style="color:var(--dim)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium" style="color:var(--ink)">Aucune tâche en attente</h3>
                        <p class="mt-1 text-sm" style="color:var(--dim)">Bravo, vous êtes à jour !</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($tasks as $task): ?>
                        <?php include __DIR__ . '/../components/task_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal envoi de note -->
    <div id="noteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="rounded-xl shadow-xl max-w-lg w-full mx-4" style="background:var(--surface)">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold" style="color:var(--ink)">Envoyer une note</h3>
                    <button onclick="closeNoteModal()" style="color:var(--dim)">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form id="noteForm" onsubmit="sendNote(event)">
                    <input type="hidden" id="noteDocumentId" name="document_id" value="">

                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Destinataire</label>
                        <select id="noteRecipient" name="to_user_id" required
                                class="w-full px-3 py-2 rounded-lg">
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
                        <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Sujet (optionnel)</label>
                        <input type="text" id="noteSubject" name="subject"
                               class="w-full px-3 py-2 rounded-lg"
                               placeholder="Sujet de la note">
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Message</label>
                        <textarea id="noteMessage" name="message" rows="4" required
                                  class="w-full px-3 py-2 rounded-lg"
                                  placeholder="Votre message..."></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" id="noteActionRequired" name="action_required" value="1"
                                   class="w-4 h-4 rounded focus:ring-blue-500" style="accent-color:var(--accent)">
                            <span class="text-sm" style="color:var(--ink-soft)">Action requise du destinataire</span>
                        </label>
                    </div>

                    <div id="actionTypeContainer" class="mb-4 hidden">
                        <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Type d'action</label>
                        <select id="noteActionType" name="action_type"
                                class="w-full px-3 py-2 rounded-lg">
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
                                class="flex-1 px-4 py-2 rounded-lg btn-primary transition-colors">
                            Envoyer
                        </button>
                        <button type="button" onclick="closeNoteModal()"
                                class="px-4 py-2 border rounded-lg btn-secondary transition-colors">
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
        container.classList.toggle('hidden', !this.checked);
    });

    // Open note modal
    function openNoteModal(documentId = null) {
        document.getElementById('noteDocumentId').value = documentId || '';
        document.getElementById('noteModal').classList.remove('hidden');
        document.getElementById('noteModal').classList.add('flex');
    }

    // Close note modal
    function closeNoteModal() {
        document.getElementById('noteModal').classList.add('hidden');
        document.getElementById('noteModal').classList.remove('flex');
        document.getElementById('noteForm').reset();
        document.getElementById('actionTypeContainer').classList.add('hidden');
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
                showToast('Note envoyée avec succès', 'success');
            } else {
                showToast(result.message || 'Erreur lors de l\'envoi', 'error');
            }
        } catch (error) {
            showToast('Erreur de connexion', 'error');
        }
    }

    // Approve document
    async function approveDocument(documentId) {
        const comment = prompt('Commentaire (optionnel):');

        try {
            const response = await fetch(`<?= url('/api/validation/') ?>${documentId}/approve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ comment })
            });

            const result = await response.json();

            if (result.success) {
                showToast('Document approuvé', 'success');
                location.reload();
            } else {
                showToast(result.message || 'Erreur', 'error');
            }
        } catch (error) {
            showToast('Erreur de connexion', 'error');
        }
    }

    // Reject document
    async function rejectDocument(documentId) {
        const comment = prompt('Raison du rejet:');
        if (!comment) return;

        try {
            const response = await fetch(`<?= url('/api/validation/') ?>${documentId}/reject`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ comment })
            });

            const result = await response.json();

            if (result.success) {
                showToast('Document rejeté', 'success');
                location.reload();
            } else {
                showToast(result.message || 'Erreur', 'error');
            }
        } catch (error) {
            showToast('Erreur de connexion', 'error');
        }
    }

    // Mark action complete
    async function markActionComplete(noteId) {
        try {
            const response = await fetch(`<?= url('/api/notes/') ?>${noteId}/complete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });

            const result = await response.json();

            if (result.success) {
                showToast('Action marquée comme terminée', 'success');
                location.reload();
            } else {
                showToast(result.message || 'Erreur', 'error');
            }
        } catch (error) {
            showToast('Erreur de connexion', 'error');
        }
    }

    // Toast notification
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'var(--green)' : type === 'error' ? 'var(--red)' : 'var(--accent)';
        toast.className = `fixed bottom-4 right-4 text-white px-4 py-2 rounded-lg shadow-lg z-50 transition-opacity duration-300`;
        toast.style.background = bgColor;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Close modal on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeNoteModal();
    });
    </script>
</body>
</html>
