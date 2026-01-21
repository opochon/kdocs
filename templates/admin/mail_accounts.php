<?php
// Liste des comptes email
$success = $_GET['success'] ?? null;
$error = $_GET['error'] ?? null;
?>

<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Comptes Email</h1>
        <a href="<?= url('/admin/mail-accounts/create') ?>" class="btn-primary">
            + Nouveau compte
        </a>
    </div>

    <?php if ($success): ?>
    <div class="bg-green-50 dark:bg-green-900 border border-green-200 dark:border-green-700 rounded-lg p-4 mb-4">
        <p class="text-green-800 dark:text-green-200">✅ Opération réussie</p>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-50 dark:bg-red-900 border border-red-200 dark:border-red-700 rounded-lg p-4 mb-4">
        <p class="text-red-800 dark:text-red-200">❌ Erreur : <?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Serveur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Dernière vérification</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($accounts)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">
                        Aucun compte email configuré
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($accounts as $account): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($account['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($account['imap_server']) ?>:<?= $account['imap_port'] ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($account['username']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($account['is_active']): ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Actif</span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                        <?= $account['last_checked_at'] ? date('d/m/Y H:i', strtotime($account['last_checked_at'])) : 'Jamais' ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="<?= url('/admin/mail-accounts/' . $account['id'] . '/edit') ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 mr-3">Modifier</a>
                        <button onclick="testConnection(<?= $account['id'] ?>)" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3">Tester</button>
                        <button onclick="processAccount(<?= $account['id'] ?>)" class="text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300 mr-3">Traiter</button>
                        <form method="POST" action="<?= url('/admin/mail-accounts/' . $account['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Supprimer ce compte ?');">
                            <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Supprimer</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function testConnection(id) {
    fetch('<?= url('/admin/mail-accounts') ?>/' + id + '/test', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ ' + (data.message || 'Connexion réussie'));
            } else {
                alert('❌ ' + (data.error || 'Échec de la connexion'));
            }
        });
}

function processAccount(id) {
    if (!confirm('Traiter les emails de ce compte maintenant ?')) return;
    fetch('<?= url('/admin/mail-accounts') ?>/' + id + '/process', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ ' + data.processed + ' email(s) traité(s)');
                location.reload();
            } else {
                alert('❌ ' + (data.error || 'Erreur lors du traitement'));
            }
        });
}
</script>
