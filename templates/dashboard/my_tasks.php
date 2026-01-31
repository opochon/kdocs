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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Mes Tâches') ?> - K-Docs</title>
    <link rel="stylesheet" href="/kdocs/public/css/tailwind.css">
</head>
<body class="bg-gray-50">
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
                        <h1 class="text-2xl font-semibold text-gray-900">Mes Tâches</h1>
                        <p class="text-sm text-gray-500 mt-1">
                            <?= $counts['total'] ?> tâche(s) en attente
                            <?php if ($counts['urgent'] > 0): ?>
                            <span class="text-red-600 font-medium">(<?= $counts['urgent'] ?> urgente(s))</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Tabs -->
                    <div class="border-b border-gray-200 mb-6">
                        <nav class="flex gap-4" aria-label="Tabs">
                            <a href="<?= url('/mes-taches') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2 <?= $activeTab === 'all' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                                Toutes
                                <?php if ($counts['total'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'all' ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' ?>">
                                    <?= $counts['total'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=validation') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2 <?= $activeTab === 'validation' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                                A valider
                                <?php if ($counts['validation'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'validation' ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' ?>">
                                    <?= $counts['validation'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=consume') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2 <?= $activeTab === 'consume' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                                A classer
                                <?php if ($counts['consume'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'consume' ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' ?>">
                                    <?= $counts['consume'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=workflow') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2 <?= $activeTab === 'workflow' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                                Workflows
                                <?php if ($counts['workflow'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'workflow' ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' ?>">
                                    <?= $counts['workflow'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                            <a href="<?= url('/mes-taches?tab=note') ?>"
                               class="px-3 py-2 text-sm font-medium border-b-2 <?= $activeTab === 'note' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">
                                Notes
                                <?php if ($counts['notes'] > 0): ?>
                                <span class="ml-1 px-2 py-0.5 text-xs rounded-full <?= $activeTab === 'note' ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' ?>">
                                    <?= $counts['notes'] ?>
                                </span>
                                <?php endif; ?>
                            </a>
                        </nav>
                    </div>

                    <!-- Tasks list -->
                    <?php if (empty($tasks)): ?>
                    <div class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">Aucune tâche en attente</h3>
                        <p class="mt-1 text-sm text-gray-500">Bravo, vous êtes à jour !</p>
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
                    <input type="hidden" id="noteDocumentId" name="document_id" value="">

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
        const bgColor = type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500';
        toast.className = `fixed bottom-4 right-4 ${bgColor} text-white px-4 py-2 rounded-lg shadow-lg z-50 transition-opacity duration-300`;
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
