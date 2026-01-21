<?php
// $users est passé depuis le contrôleur
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Gestion des utilisateurs</h1>
        <a href="<?= url('/admin') ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour
        </a>
    </div>

    <?php if (empty($users)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <p class="text-gray-500 text-lg">Aucun utilisateur trouvé</p>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilisateur</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rôle</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Documents</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tâches</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dernière connexion</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($u['username']) ?>
                                </div>
                                <?php if ($u['first_name'] || $u['last_name']): ?>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= htmlspecialchars($u['email']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($u['is_admin']): ?>
                                    <span class="px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded">Administrateur</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded">Utilisateur</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $u['document_count'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $u['task_count'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($u['is_active']): ?>
                                    <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Actif</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">Inactif</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Jamais' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
