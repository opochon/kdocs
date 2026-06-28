<?php
// Liste des comptes email
$success = $_GET['success'] ?? null;
$error = $_GET['error'] ?? null;
?>

<div class="max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Comptes Email</h1>
        <a href="<?= url('/admin/mail-accounts/create') ?>" class="btn-primary">
            + Nouveau compte
        </a>
    </div>

    <?php if ($success): ?>
    <div class="border rounded-lg p-4 mb-4" style="background:color-mix(in srgb, var(--green) 12%, transparent); border-color:color-mix(in srgb, var(--green) 35%, var(--border))">
        <p style="color:var(--green)">✅ Opération réussie</p>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="border rounded-lg p-4 mb-4" style="background:color-mix(in srgb, var(--red) 12%, transparent); border-color:color-mix(in srgb, var(--red) 35%, var(--border))">
        <p style="color:var(--red)">❌ Erreur : <?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <div class="ds-card shadow overflow-hidden">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Serveur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Dernière vérification</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($accounts)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center" style="color:var(--dim)">
                        Aucun compte email configuré
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($accounts as $account): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($account['name']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($account['imap_server']) ?>:<?= $account['imap_port'] ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($account['username']) ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($account['is_active']): ?>
                        <span class="ds-chip ds-chip--green px-2 py-1 text-xs font-semibold">Actif</span>
                        <?php else: ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs font-semibold">Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                        <?= $account['last_checked_at'] ? date('d/m/Y H:i', strtotime($account['last_checked_at'])) : 'Jamais' ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="<?= url('/admin/mail-accounts/' . $account['id'] . '/edit') ?>" class="mr-3">Modifier</a>
                        <button onclick="testConnection(<?= $account['id'] ?>)" class="mr-3" style="color:var(--accent)">Tester</button>
                        <button onclick="processAccount(<?= $account['id'] ?>)" class="mr-3" style="color:var(--accent)">Traiter</button>
                        <form method="POST" action="<?= url('/admin/mail-accounts/' . $account['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Supprimer ce compte ?');">
                            <button type="submit" style="color:var(--red)">Supprimer</button>
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
