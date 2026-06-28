<?php
/**
 * Liste des règles d'attribution
 */
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--ink)">Règles d'attribution</h1>
            <p class="mt-1" style="color:var(--dim)">Définissez des règles automatiques pour classer vos documents</p>
        </div>
        <a href="<?= url('/admin/attribution-rules/create') ?>"
           class="btn btn-primary flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Nouvelle règle
        </a>
    </div>

    <!-- Statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--dim)">Total règles</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['total'] ?></div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--dim)">Règles actives</div>
            <div class="text-2xl font-bold" style="color:var(--green)"><?= $stats['active'] ?></div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--dim)">Exécutions aujourd'hui</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['executions_today'] ?></div>
        </div>
        <div class="ds-card rounded-lg shadow p-4">
            <div class="text-sm" style="color:var(--dim)">Correspondances</div>
            <div class="text-2xl font-bold" style="color:var(--ink)"><?= $stats['matches_today'] ?></div>
        </div>
    </div>

    <!-- Liste des règles -->
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <?php if (empty($rules)): ?>
            <div class="p-8 text-center" style="color:var(--dim)">
                <i class="fas fa-layer-group text-4xl mb-4"></i>
                <p>Aucune règle d'attribution configurée</p>
                <a href="<?= url('/admin/attribution-rules/create') ?>" class="hover:underline mt-2 inline-block" style="color:var(--accent)">
                    Créer votre première règle
                </a>
            </div>
        <?php else: ?>
            <table class="min-w-full">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Règle</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Priorité</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Conditions</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Actions</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td class="px-6 py-4">
                                <div class="font-medium" style="color:var(--ink)"><?= htmlspecialchars($rule['name']) ?></div>
                                <?php if (!empty($rule['description'])): ?>
                                    <div class="text-sm" style="color:var(--dim)"><?= htmlspecialchars(substr($rule['description'], 0, 100)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="ds-chip ds-chip--neutral px-2.5 py-0.5 text-xs font-medium">
                                    <?= $rule['priority'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm" style="color:var(--ink-soft)">
                                    <?= count($rule['conditions']) ?> condition(s)
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm" style="color:var(--ink-soft)">
                                    <?= count($rule['actions']) ?> action(s)
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($rule['is_active']): ?>
                                    <span class="ds-chip ds-chip--green px-2.5 py-0.5 text-xs font-medium">
                                        <i class="fas fa-check-circle mr-1"></i> Active
                                    </span>
                                <?php else: ?>
                                    <span class="ds-chip ds-chip--neutral px-2.5 py-0.5 text-xs font-medium">
                                        <i class="fas fa-pause-circle mr-1"></i> Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="<?= url('/admin/attribution-rules/' . $rule['id'] . '/edit') ?>"
                                       style="color:var(--accent)" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="<?= url('/admin/attribution-rules/' . $rule['id'] . '/logs') ?>"
                                       style="color:var(--ink-soft)" title="Logs">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <button onclick="duplicateRule(<?= $rule['id'] ?>)"
                                            style="color:var(--ink-soft)" title="Dupliquer">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    <button onclick="deleteRule(<?= $rule['id'] ?>, '<?= htmlspecialchars(addslashes($rule['name'])) ?>')"
                                            style="color:var(--red)" title="Supprimer">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
async function duplicateRule(id) {
    if (!confirm('Dupliquer cette règle ?')) return;

    try {
        const response = await fetch(`<?= url('/api/attribution-rules') ?>/${id}/duplicate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Erreur lors de la duplication');
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}

async function deleteRule(id, name) {
    if (!confirm(`Supprimer la règle "${name}" ?`)) return;

    try {
        const response = await fetch(`<?= url('/api/attribution-rules') ?>/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            }
        });

        const result = await response.json();
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Erreur lors de la suppression');
        }
    } catch (e) {
        alert('Erreur: ' + e.message);
    }
}
</script>
