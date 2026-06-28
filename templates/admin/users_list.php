<?php
// Liste des utilisateurs
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Gestion des utilisateurs</h1>
        <a href="<?= url('/admin/users/create') ?>" class="btn btn-primary">
            + Nouvel utilisateur
        </a>
    </div>

    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Groupes</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Dernière connexion</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center" style="color:var(--dim)">
                        Aucun utilisateur trouvé.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <?php
                // Vérifier si l'utilisateur est dans le groupe ADMIN
                $isAdmin = false;
                $groupCodes = [];
                if (!empty($u['groups'])) {
                    foreach ($u['groups'] as $g) {
                        $code = $g['code'] ?? '';
                        $groupCodes[] = $code;
                        if ($code === 'ADMIN') {
                            $isAdmin = true;
                        }
                    }
                }
                ?>
                <tr>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($u['username']) ?></span>
                            <?php if ($isAdmin): ?>
                            <span class="ds-chip ds-chip--red px-1.5 py-0.5 text-xs font-semibold">Admin</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($u['email'])): ?>
                        <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars($u['email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <?php if (!empty($u['groups'])): ?>
                        <?php foreach ($u['groups'] as $group):
                            $isAdminGroup = ($group['code'] ?? '') === 'ADMIN';
                            $chipVariant = $isAdminGroup ? 'ds-chip--red' : 'ds-chip--neutral';
                        ?>
                        <span class="ds-chip inline-block px-2 py-1 mr-1 mb-1 text-xs <?= $chipVariant ?>">
                            <?= htmlspecialchars($group['name']) ?>
                        </span>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <span style="color:var(--amber)">Aucun groupe</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($u['is_active'] ?? true): ?>
                        <span class="ds-chip ds-chip--green px-2 py-1 text-xs font-semibold">
                            Actif
                        </span>
                        <?php else: ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs font-semibold">
                            Inactif
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <?php
                        $lastLogin = $u['last_login_at'] ?? $u['last_login'] ?? null;
                        if ($lastLogin):
                        ?>
                        <?= date('d/m/Y H:i', strtotime($lastLogin)) ?>
                        <?php else: ?>
                        <span style="color:var(--dim)">Jamais</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm font-medium">
                        <a href="<?= url('/admin/users/' . $u['id'] . '/edit') ?>" class="mr-4" style="color:var(--accent)">
                            Modifier
                        </a>
                        <?php if ($u['id'] != ($user['id'] ?? 0)): ?>
                        <form method="POST" action="<?= url('/admin/users/' . $u['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?');">
                            <button type="submit" style="color:var(--red)">
                                Supprimer
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
