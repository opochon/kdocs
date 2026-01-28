<?php
// Liste des utilisateurs
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Gestion des utilisateurs</h1>
        <a href="<?= url('/admin/users/create') ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            + Nouvel utilisateur
        </a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Groupes</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Dernière connexion</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
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
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= htmlspecialchars($u['username']) ?></span>
                            <?php if ($isAdmin): ?>
                            <span class="px-1.5 py-0.5 text-xs font-semibold rounded bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">Admin</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($u['email'])): ?>
                        <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($u['email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?php if (!empty($u['groups'])): ?>
                        <?php foreach ($u['groups'] as $group):
                            $isAdminGroup = ($group['code'] ?? '') === 'ADMIN';
                            $bgClass = $isAdminGroup
                                ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
                        ?>
                        <span class="inline-block px-2 py-1 mr-1 mb-1 text-xs rounded <?= $bgClass ?>">
                            <?= htmlspecialchars($group['name']) ?>
                        </span>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <span class="text-amber-500">Aucun groupe</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($u['is_active'] ?? true): ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                            Actif
                        </span>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                            Inactif
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">
                        <?php
                        $lastLogin = $u['last_login_at'] ?? $u['last_login'] ?? null;
                        if ($lastLogin):
                        ?>
                        <?= date('d/m/Y H:i', strtotime($lastLogin)) ?>
                        <?php else: ?>
                        <span class="text-gray-400">Jamais</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm font-medium">
                        <a href="<?= url('/admin/users/' . $u['id'] . '/edit') ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 mr-4">
                            Modifier
                        </a>
                        <?php if ($u['id'] != ($user['id'] ?? 0)): ?>
                        <form method="POST" action="<?= url('/admin/users/' . $u['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?');">
                            <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
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
