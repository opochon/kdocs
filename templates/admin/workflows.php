<?php
// Liste des workflows (Phase 3.3)
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Workflows</h1>
        <a href="<?= url('/admin/workflows/new/designer') ?>" class="btn btn-primary">
            + Créer un workflow
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="border px-4 py-3 rounded" style="background:color-mix(in srgb, var(--red) 10%, transparent); border-color:color-mix(in srgb, var(--red) 35%, var(--border)); color:var(--red)">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($workflows)): ?>
    <div class="ds-card shadow p-8 text-center">
        <p class="mb-4" style="color:var(--dim)">Aucun workflow créé.</p>
        <a href="<?= url('/admin/workflows/new/designer') ?>" class="btn btn-primary">
            Créer le premier workflow
        </a>
    </div>
    <?php else: ?>
    <div class="ds-card shadow overflow-hidden">
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Statut</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Nodes</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Créé le</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($workflows as $workflow): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($workflow['name']) ?></div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm max-w-md truncate" style="color:var(--dim)">
                            <?= htmlspecialchars($workflow['description'] ?? '') ?: '<span style="color:var(--dim)">-</span>' ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($workflow['enabled']): ?>
                        <span class="ds-chip ds-chip--green px-2 py-1 text-xs">Actif</span>
                        <?php else: ?>
                        <span class="ds-chip ds-chip--neutral px-2 py-1 text-xs">Inactif</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm" style="color:var(--dim)">
                            <?php
                            $nodes = $workflow['nodes'] ?? [];
                            $nodeCount = count($nodes);
                            if ($nodeCount > 0):
                                // Compter par type
                                $nodeTypes = [];
                                foreach ($nodes as $node) {
                                    $type = $node['node_type'] ?? 'unknown';
                                    $nodeTypes[$type] = ($nodeTypes[$type] ?? 0) + 1;
                                }
                            ?>
                                <div class="space-y-1">
                                    <div class="text-xs font-medium"><?= $nodeCount ?> node(s)</div>
                                    <div class="flex flex-wrap gap-1">
                                        <?php foreach (array_slice($nodeTypes, 0, 3) as $type => $count): ?>
                                            <span class="ds-chip ds-chip--neutral inline-block px-2 py-0.5 text-xs">
                                                <?= htmlspecialchars($type) ?> (<?= $count ?>)
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($nodeTypes) > 3): ?>
                                            <span class="text-xs" style="color:var(--dim)">+<?= count($nodeTypes) - 3 ?> autres</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--dim)">Aucun node</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--dim)">
                        <?= $workflow['created_at'] ? date('d/m/Y', strtotime($workflow['created_at'])) : '-' ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?= url('/admin/workflows/' . $workflow['id'] . '/designer') ?>" class="mr-3">
                            Ouvrir dans le designer
                        </a>
                        <form method="POST" action="<?= url('/admin/workflows/' . $workflow['id'] . '/delete') ?>" class="inline"
                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce workflow ?')">
                            <button type="submit" style="color:var(--red)">
                                Supprimer
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
