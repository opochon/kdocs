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
            <h1 class="text-2xl font-bold" style="color:var(--ink)">Gestion des rôles</h1>
            <p class="mt-1" style="color:var(--ink-soft)">Assignez des rôles de validation aux utilisateurs</p>
        </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:color-mix(in srgb,var(--green) 12%,transparent);border:1px solid color-mix(in srgb,var(--green) 35%,var(--border));color:var(--green)">
            <?= htmlspecialchars($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:color-mix(in srgb,var(--red) 12%,transparent);border:1px solid color-mix(in srgb,var(--red) 45%,var(--border));color:var(--red)">
            <?= htmlspecialchars($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- Légende des rôles -->
    <div class="ds-card rounded-xl shadow-sm p-6 mb-6">
        <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">Types de rôles disponibles</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($roles as $role): ?>
                <div class="flex items-start gap-3 p-3 rounded-lg" style="background:var(--app-bg)">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center font-semibold text-sm" style="background:var(--hover);color:var(--ink-soft)">
                        <?= $role['level'] ?? 0 ?>
                    </div>
                    <div>
                        <div class="font-medium" style="color:var(--ink)"><?= htmlspecialchars($role['label'] ?? $role['code']) ?></div>
                        <div class="text-xs" style="color:var(--dim)"><?= htmlspecialchars($role['code']) ?></div>
                        <?php if (!empty($role['description'])): ?>
                            <div class="text-sm mt-1" style="color:var(--ink-soft)"><?= htmlspecialchars($role['description']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Liste des utilisateurs et leurs rôles -->
    <div class="ds-card rounded-xl shadow-sm overflow-hidden">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Rôles actuels</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Statut</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10 rounded-full flex items-center justify-center" style="background:var(--hover)">
                                    <span class="font-medium" style="color:var(--ink-soft)">
                                        <?= strtoupper(substr($user['username'], 0, 2)) ?>
                                    </span>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium" style="color:var(--ink)">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </div>
                                    <div class="text-sm" style="color:var(--dim)">
                                        <?= htmlspecialchars($user['email'] ?? '') ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if (!empty($user['roles'])): ?>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($user['roles'] as $role): ?>
                                        <span class="ds-chip ds-chip--neutral inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium">
                                            <?= htmlspecialchars($role['code']) ?>
                                            <?php if ($role['scope'] !== '*'): ?>
                                                <span style="color:var(--dim)">(<?= htmlspecialchars($role['scope']) ?>)</span>
                                            <?php endif; ?>
                                            <?php if ($role['max_amount']): ?>
                                                <span style="color:var(--dim)">&lt;<?= number_format((float)$role['max_amount'], 0, ',', ' ') ?></span>
                                            <?php endif; ?>
                                            <button type="button"
                                                    onclick="removeRole(<?= $user['id'] ?>, '<?= $role['code'] ?>', '<?= $role['scope'] ?>')"
                                                    class="ml-1" style="color:var(--dim)">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-sm" style="color:var(--dim)">Aucun rôle</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($user['is_active']): ?>
                                <span class="ds-chip ds-chip--green inline-flex items-center px-2.5 py-0.5 text-xs font-medium">
                                    Actif
                                </span>
                            <?php else: ?>
                                <span class="ds-chip ds-chip--neutral inline-flex items-center px-2.5 py-0.5 text-xs font-medium">
                                    Inactif
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="<?= $basePath ?>/admin/roles/<?= $user['id'] ?>/assign"
                               style="color:var(--accent)">
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
