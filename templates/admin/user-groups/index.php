<?php
/**
 * K-Docs - Liste des groupes d'utilisateurs
 */
use KDocs\Core\Config;
$base = Config::basePath();
?>

<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Groupes d'utilisateurs</h1>
            <p class="text-sm text-gray-500 mt-1">Gérez les groupes pour les workflows d'approbation</p>
        </div>
        <a href="<?= url('/admin/user-groups/create') ?>" 
           class="px-4 py-2 bg-gray-900 text-white text-sm rounded-lg hover:bg-gray-800">
            <i class="fas fa-plus mr-2"></i>Nouveau groupe
        </a>
    </div>
    
    <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700">
        <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
        <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
    </div>
    <?php endif; ?>
    
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Groupe</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Membres</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($groups)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                            <div class="flex flex-col items-center">
                                <i class="fas fa-users text-4xl text-gray-300 mb-3"></i>
                                <p>Aucun groupe défini</p>
                                <a href="<?= url('/admin/user-groups/create') ?>" class="mt-2 text-blue-600 hover:underline">
                                    Créer le premier groupe
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($groups as $group):
                        $isAdminGroup = ($group['code'] ?? '') === 'ADMIN';
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-10 h-10 flex-shrink-0 <?= $isAdminGroup ? 'bg-red-100' : 'bg-purple-100' ?> rounded-full flex items-center justify-center">
                                    <i class="fas <?= $isAdminGroup ? 'fa-crown text-red-600' : 'fa-users text-purple-600' ?>"></i>
                                </div>
                                <div class="ml-4">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($group['name']) ?></span>
                                        <?php if ($isAdminGroup): ?>
                                        <span class="px-1.5 py-0.5 text-xs font-semibold rounded bg-red-100 text-red-800">Tous droits</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($group['description'])): ?>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars(substr($group['description'], 0, 50)) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if (!empty($group['code'])): ?>
                            <code class="px-2 py-1 <?= $isAdminGroup ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700' ?> rounded text-xs"><?= htmlspecialchars($group['code']) ?></code>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-user mr-1"></i>
                                <?= $group['member_count'] ?? 0 ?> membre(s)
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($group['is_system'] ?? false): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                <i class="fas fa-lock mr-1"></i> Système
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                Personnalisé
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="<?= url('/admin/user-groups/' . $group['id'] . '/edit') ?>" 
                               class="text-blue-600 hover:text-blue-900 mr-3">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if (!($group['is_system'] ?? false)): ?>
                            <form method="POST" action="<?= url('/admin/user-groups/' . $group['id'] . '/delete') ?>" class="inline"
                                  onsubmit="return confirm('Supprimer ce groupe ?')">
                                <button type="submit" class="text-red-600 hover:text-red-900">
                                    <i class="fas fa-trash"></i>
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
    
    <!-- Info box -->
    <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <h3 class="font-medium text-blue-800 mb-2"><i class="fas fa-info-circle mr-2"></i>Système de permissions par groupes</h3>
        <ul class="text-sm text-blue-700 space-y-1">
            <li><strong>Permissions:</strong> Les droits des utilisateurs sont déterminés par leurs groupes.</li>
            <li><strong>Groupe ADMIN:</strong> Les membres du groupe avec le code <code class="px-1 bg-blue-100 rounded">ADMIN</code> ont automatiquement tous les droits.</li>
            <li><strong>Workflows:</strong> Assignez un document à un groupe et tous les membres recevront la notification d'approbation.</li>
        </ul>
    </div>
</div>
