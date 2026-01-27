<?php
/**
 * Template: Administration des rôles utilisateurs
 */
$pageTitle = 'Gestion des rôles';
include __DIR__ . '/../layout/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Gestion des rôles</h1>
            <p class="text-gray-600 mt-1">Assignez des rôles de validation aux utilisateurs</p>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="mb-4 p-4 bg-green-100 border border-green-200 text-green-800 rounded-lg">
            <?= htmlspecialchars($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="mb-4 p-4 bg-red-100 border border-red-200 text-red-800 rounded-lg">
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- Légende des rôles -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Types de rôles disponibles</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($roles as $role): ?>
                <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                    <div class="flex-shrink-0 w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-semibold text-sm">
                        <?= $role['level'] ?? 0 ?>
                    </div>
                    <div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars($role['label'] ?? $role['code']) ?></div>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($role['code']) ?></div>
                        <?php if (!empty($role['description'])): ?>
                            <div class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($role['description']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Liste des utilisateurs et leurs rôles -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rôles actuels</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10 bg-gray-200 rounded-full flex items-center justify-center">
                                    <span class="text-gray-600 font-medium">
                                        <?= strtoupper(substr($user['username'], 0, 2)) ?>
                                    </span>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars($user['email'] ?? '') ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if (!empty($user['roles'])): ?>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($user['roles'] as $role): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?= htmlspecialchars($role['code']) ?>
                                            <?php if ($role['scope'] !== '*'): ?>
                                                <span class="text-blue-600">(<?= htmlspecialchars($role['scope']) ?>)</span>
                                            <?php endif; ?>
                                            <?php if ($role['max_amount']): ?>
                                                <span class="text-blue-600">&lt;<?= number_format((float)$role['max_amount'], 0, ',', ' ') ?></span>
                                            <?php endif; ?>
                                            <button type="button"
                                                    onclick="removeRole(<?= $user['id'] ?>, '<?= $role['code'] ?>', '<?= $role['scope'] ?>')"
                                                    class="ml-1 text-blue-600 hover:text-red-600">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-gray-400 text-sm">Aucun rôle</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($user['is_active']): ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Actif
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    Inactif
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="<?= $basePath ?>/admin/roles/<?= $user['id'] ?>/assign"
                               class="text-blue-600 hover:text-blue-900">
                                Assigner rôle
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function removeRole(userId, roleCode, scope) {
    if (!confirm(`Retirer le rôle ${roleCode} de cet utilisateur ?`)) return;

    const url = `<?= $basePath ?>/admin/roles/${userId}/remove/${roleCode}` + (scope !== '*' ? `?scope=${encodeURIComponent(scope)}` : '');

    fetch(url, { method: 'POST' })
        .then(() => location.reload())
        .catch(e => alert('Erreur: ' + e.message));
}
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
