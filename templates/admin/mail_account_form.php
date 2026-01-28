<?php
// Formulaire de création/édition de compte email
$isEdit = !empty($account);

// Get folders and correspondents for defaults
$db = \KDocs\Core\Database::getInstance();
$folders = $db->query("SELECT id, name, path FROM document_folders ORDER BY path")->fetchAll(\PDO::FETCH_ASSOC);
$correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
        <?= $isEdit ? 'Modifier le compte email' : 'Nouveau compte email' ?>
    </h1>

    <form method="POST" action="<?= url($isEdit ? '/admin/mail-accounts/' . $account['id'] . '/save' : '/admin/mail-accounts/save') ?>" class="space-y-6">
        <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $account['id'] ?>">
        <?php endif; ?>

        <!-- Connexion IMAP -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
                <span class="inline-flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    Connexion IMAP
                </span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom du compte *</label>
                    <input type="text" id="name" name="name" required
                           value="<?= htmlspecialchars($account['name'] ?? '') ?>"
                           placeholder="Ex: Gmail Personnel"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="imap_server" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Serveur IMAP *</label>
                    <input type="text" id="imap_server" name="imap_server" required
                           value="<?= htmlspecialchars($account['imap_server'] ?? '') ?>"
                           placeholder="imap.gmail.com"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="imap_port" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Port *</label>
                    <input type="number" id="imap_port" name="imap_port" required
                           value="<?= $account['imap_port'] ?? 993 ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="imap_security" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sécurité *</label>
                    <select id="imap_security" name="imap_security" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="ssl" <?= ($account['imap_security'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (Recommandé)</option>
                        <option value="tls" <?= ($account['imap_security'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                        <option value="none" <?= ($account['imap_security'] ?? '') === 'none' ? 'selected' : '' ?>>Aucune (non sécurisé)</option>
                    </select>
                </div>

                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Adresse email *</label>
                    <input type="text" id="username" name="username" required
                           value="<?= htmlspecialchars($account['username'] ?? '') ?>"
                           placeholder="vous@exemple.com"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Mot de passe <?= $isEdit ? '(laisser vide pour conserver)' : '*' ?>
                    </label>
                    <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>
                           placeholder="<?= $isEdit ? '********' : '' ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <p class="mt-1 text-xs text-gray-500">Pour Gmail, utilisez un mot de passe d'application</p>
                </div>

                <div class="md:col-span-2">
                    <label class="flex items-center">
                        <input type="checkbox" id="is_active" name="is_active" value="1"
                               <?= ($account['is_active'] ?? true) ? 'checked' : '' ?>
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Compte actif</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Paramètres d'ingestion -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
                <span class="inline-flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
                    </svg>
                    Paramètres d'ingestion
                </span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="folder" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dossier IMAP à surveiller</label>
                    <input type="text" id="folder" name="folder"
                           value="<?= htmlspecialchars($account['folder'] ?? 'INBOX') ?>"
                           placeholder="INBOX"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <p class="mt-1 text-xs text-gray-500">Ex: INBOX, Factures, Documents</p>
                </div>

                <div>
                    <label for="processed_folder" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dossier après traitement</label>
                    <input type="text" id="processed_folder" name="processed_folder"
                           value="<?= htmlspecialchars($account['processed_folder'] ?? '') ?>"
                           placeholder="Traités (optionnel)"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                    <p class="mt-1 text-xs text-gray-500">Laisser vide pour ne pas déplacer</p>
                </div>

                <div>
                    <label for="check_interval" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Intervalle de vérification</label>
                    <select id="check_interval" name="check_interval"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="60" <?= ($account['check_interval'] ?? 300) == 60 ? 'selected' : '' ?>>1 minute</option>
                        <option value="300" <?= ($account['check_interval'] ?? 300) == 300 ? 'selected' : '' ?>>5 minutes</option>
                        <option value="600" <?= ($account['check_interval'] ?? 300) == 600 ? 'selected' : '' ?>>10 minutes</option>
                        <option value="1800" <?= ($account['check_interval'] ?? 300) == 1800 ? 'selected' : '' ?>>30 minutes</option>
                        <option value="3600" <?= ($account['check_interval'] ?? 300) == 3600 ? 'selected' : '' ?>>1 heure</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
                <span class="inline-flex items-center">
                    <svg class="w-5 h-5 mr-2 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    Filtres (optionnel)
                </span>
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Filtrer les emails à traiter. Laisser vide pour tout traiter.</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="filter_from" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Filtrer par expéditeur</label>
                    <input type="text" id="filter_from" name="filter_from"
                           value="<?= htmlspecialchars($account['filter_from'] ?? '') ?>"
                           placeholder="factures@exemple.com"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="filter_subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Filtrer par sujet</label>
                    <input type="text" id="filter_subject" name="filter_subject"
                           value="<?= htmlspecialchars($account['filter_subject'] ?? '') ?>"
                           placeholder="Facture"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                </div>
            </div>
        </div>

        <!-- Valeurs par défaut -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
                <span class="inline-flex items-center">
                    <svg class="w-5 h-5 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Valeurs par défaut pour les documents
                </span>
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Ces valeurs seront appliquées aux documents importés depuis ce compte.</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label for="default_document_type_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type de document</label>
                    <select id="default_document_type_id" name="default_document_type_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">-- Aucun --</option>
                        <?php foreach ($documentTypes ?? [] as $type): ?>
                        <option value="<?= $type['id'] ?>" <?= ($account['default_document_type_id'] ?? '') == $type['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="default_correspondent_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Correspondant</label>
                    <select id="default_correspondent_id" name="default_correspondent_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">-- Aucun --</option>
                        <?php foreach ($correspondents as $corr): ?>
                        <option value="<?= $corr['id'] ?>" <?= ($account['default_correspondent_id'] ?? '') == $corr['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($corr['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="default_folder_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dossier de destination</label>
                    <select id="default_folder_id" name="default_folder_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">-- Consume par défaut --</option>
                        <?php foreach ($folders as $folder): ?>
                        <option value="<?= $folder['id'] ?>" <?= ($account['default_folder_id'] ?? '') == $folder['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($folder['path'] ?: $folder['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex justify-between items-center">
            <?php if ($isEdit): ?>
            <button type="button" onclick="testConnection(<?= $account['id'] ?>)" class="btn-secondary">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Tester la connexion
            </button>
            <?php else: ?>
            <div></div>
            <?php endif; ?>

            <div class="flex space-x-3">
                <a href="<?= url('/admin/mail-accounts') ?>" class="btn-secondary">Annuler</a>
                <button type="submit" class="btn-primary">Enregistrer</button>
            </div>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<!-- Logs d'ingestion -->
<div class="max-w-4xl mx-auto mt-8">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
            <span class="inline-flex items-center">
                <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Historique d'ingestion
            </span>
        </h2>

        <div id="ingestion-logs" class="text-sm text-gray-500 dark:text-gray-400">
            Chargement...
        </div>
    </div>
</div>

<script>
function testConnection(id) {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<svg class="animate-spin w-4 h-4 mr-2 inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>Test en cours...';

    fetch('<?= url('/admin/mail-accounts') ?>/' + id + '/test', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Tester la connexion';

            if (data.success) {
                alert('Connexion réussie!\n\nDossier: ' + (data.folder || 'INBOX') + '\nMessages: ' + (data.messages || 0) + '\nRécents: ' + (data.recent || 0));
            } else {
                alert('Échec de la connexion:\n' + (data.error || 'Erreur inconnue'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Tester la connexion';
            alert('Erreur: ' + err.message);
        });
}

// Load ingestion logs
document.addEventListener('DOMContentLoaded', function() {
    fetch('<?= url('/api/email-ingestion/logs?account_id=' . $account['id']) ?>')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.logs.length > 0) {
                let html = '<table class="min-w-full text-sm"><thead><tr class="border-b dark:border-gray-700"><th class="text-left py-2">Date</th><th class="text-left py-2">Sujet</th><th class="text-left py-2">PJ</th><th class="text-left py-2">Docs</th><th class="text-left py-2">Statut</th></tr></thead><tbody>';
                data.logs.forEach(log => {
                    const statusClass = log.status === 'success' ? 'text-green-600' : log.status === 'error' ? 'text-red-600' : 'text-gray-500';
                    html += `<tr class="border-b dark:border-gray-700">
                        <td class="py-2">${new Date(log.created_at).toLocaleString('fr-FR')}</td>
                        <td class="py-2 truncate max-w-xs">${log.email_subject || '-'}</td>
                        <td class="py-2">${log.attachments_count}</td>
                        <td class="py-2">${log.documents_created}</td>
                        <td class="py-2 ${statusClass}">${log.status}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                document.getElementById('ingestion-logs').innerHTML = html;
            } else {
                document.getElementById('ingestion-logs').innerHTML = '<p class="text-gray-400">Aucun historique d\'ingestion</p>';
            }
        })
        .catch(() => {
            document.getElementById('ingestion-logs').innerHTML = '<p class="text-gray-400">Impossible de charger l\'historique</p>';
        });
});
</script>
<?php endif; ?>
