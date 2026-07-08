<?php
// $users est passé depuis le contrôleur
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Gestion des utilisateurs</h1>
        <a href="<?= url('/admin') ?>" class="btn btn-secondary">
            ← Retour
        </a>
    </div>

    <?php if (empty($users)): ?>
        <div class="ds-card rounded-lg shadow p-12 text-center">
            <p class="text-lg" style="color:var(--dim)">Aucun utilisateur trouvé</p>
        </div>
    <?php else: ?>
        <div class="ds-card rounded-lg shadow overflow-hidden">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Utilisateur</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Rôle</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Documents</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Tâches</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Dernière connexion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium" style="color:var(--ink)">
                                    <?= htmlspecialchars($u['username']) ?>
                                </div>
                                <?php if ($u['first_name'] || $u['last_name']): ?>
                                    <div class="text-sm" style="color:var(--dim)">
                                        <?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= htmlspecialchars($u['email']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($u['is_admin']): ?>
                                    <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">Administrateur</span>
                                <?php else: ?>
                                    <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">Utilisateur</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= $u['document_count'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= $u['task_count'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($u['is_active']): ?>
                                    <span class="ds-chip ds-chip--green px-2 py-1 text-xs">Actif</span>
                                <?php else: ?>
                                    <span class="ds-chip ds-chip--red px-2 py-1 text-xs">Inactif</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                                <?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Jamais' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
