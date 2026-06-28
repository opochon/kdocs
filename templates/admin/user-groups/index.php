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
            <h1 class="text-2xl font-bold" style="color:var(--ink)">Groupes d'utilisateurs</h1>
            <p class="text-sm mt-1" style="color:var(--dim)">Gérez les groupes pour les workflows d'approbation</p>
        </div>
        <a href="<?= url('/admin/user-groups/create') ?>"
           class="btn btn-primary text-sm">
            <i class="fas fa-plus mr-2"></i>Nouveau groupe
        </a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="mb-4 p-4 rounded-lg" style="background:color-mix(in srgb,var(--green) 12%,transparent);border:1px solid color-mix(in srgb,var(--green) 35%,var(--border));color:var(--green)">
        <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="mb-4 p-4 rounded-lg" style="background:color-mix(in srgb,var(--red) 12%,transparent);border:1px solid color-mix(in srgb,var(--red) 45%,var(--border));color:var(--red)">
        <?= $_SESSION['flash_error']; unset($_SESSION['flash_error']); ?>
    </div>
    <?php endif; ?>

    <div class="ds-card rounded-xl shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Groupe</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Membres</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($groups)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center" style="color:var(--dim)">
                            <div class="flex flex-col items-center">
                                <i class="fas fa-users text-4xl mb-3" style="color:var(--muted)"></i>
                                <p>Aucun groupe défini</p>
                                <a href="<?= url('/admin/user-groups/create') ?>" class="mt-2 hover:underline" style="color:var(--accent)">
                                    Créer le premier groupe
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($groups as $group):
                        $isAdminGroup = ($group['code'] ?? '') === 'ADMIN';
                    ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-10 h-10 flex-shrink-0 rounded-full flex items-center justify-center" style="background:<?= $isAdminGroup ? 'color-mix(in srgb,var(--red) 15%,transparent)' : 'var(--hover)' ?>">
                                    <i class="fas <?= $isAdminGroup ? 'fa-crown' : 'fa-users' ?>" style="color:<?= $isAdminGroup ? 'var(--red)' : 'var(--ink-soft)' ?>"></i>
                                </div>
                                <div class="ml-4">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($group['name']) ?></span>
                                        <?php if ($isAdminGroup): ?>
                                        <span class="ds-chip ds-chip--red px-1.5 py-0.5 text-xs font-semibold">Tous droits</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($group['description'])): ?>
                                    <div class="text-xs" style="color:var(--dim)"><?= htmlspecialchars(substr($group['description'], 0, 50)) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if (!empty($group['code'])): ?>
                            <code class="ds-chip px-2 py-1 text-xs <?= $isAdminGroup ? 'ds-chip--red' : 'ds-chip--neutral' ?>"><?= htmlspecialchars($group['code']) ?></code>
                            <?php else: ?>
                            <span style="color:var(--dim)">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="ds-chip ds-chip--neutral inline-flex items-center px-2.5 py-0.5 text-xs font-medium">
                                <i class="fas fa-user mr-1"></i>
                                <?= $group['member_count'] ?? 0 ?> membre(s)
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($group['is_system'] ?? false): ?>
                            <span class="ds-chip ds-chip--neutral inline-flex items-center px-2.5 py-0.5 text-xs font-medium">
                                <i class="fas fa-lock mr-1"></i> Système
                            </span>
                            <?php else: ?>
                            <span class="ds-chip ds-chip--neutral inline-flex items-center px-2.5 py-0.5 text-xs font-medium">
                                Personnalisé
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="<?= url('/admin/user-groups/' . $group['id'] . '/edit') ?>"
                               class="mr-3" style="color:var(--accent)">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if (!($group['is_system'] ?? false) && !$isAdminGroup): ?>
                            <form method="POST" action="<?= url('/admin/user-groups/' . $group['id'] . '/delete') ?>" class="inline"
                                  onsubmit="return confirm('Supprimer ce groupe ?')">
                                <button type="submit" style="color:var(--red)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <span style="color:var(--muted)" title="Groupe protégé"><i class="fas fa-trash"></i></span>
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
    <div class="mt-6 p-4 rounded-lg" style="background:var(--accent-soft);border:1px solid color-mix(in srgb,var(--accent) 30%,var(--border))">
        <h3 class="font-medium mb-2" style="color:var(--accent)"><i class="fas fa-info-circle mr-2"></i>Système de permissions par groupes</h3>
        <ul class="text-sm space-y-1" style="color:var(--ink-soft)">
            <li><strong>Permissions:</strong> Les droits des utilisateurs sont déterminés par leurs groupes.</li>
            <li><strong>Groupe ADMIN:</strong> Les membres du groupe avec le code <code class="px-1 rounded" style="background:color-mix(in srgb,var(--accent) 18%,transparent);color:var(--accent)">ADMIN</code> ont automatiquement tous les droits.</li>
            <li><strong>Workflows:</strong> Assignez un document à un groupe et tous les membres recevront la notification d'approbation.</li>
        </ul>
    </div>
</div>
