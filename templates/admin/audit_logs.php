<?php
// Liste des logs d'audit
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Logs d'audit</h1>
    </div>

    <!-- Statistiques (7 derniers jours) -->
    <?php if (!empty($stats)): ?>
    <div class="ds-card rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">Actions des 7 derniers jours</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach ($stats as $stat): ?>
            <div class="p-4 rounded-lg" style="background:var(--app-bg)">
                <div class="text-sm" style="color:var(--ink-soft)"><?= htmlspecialchars($stat['action']) ?></div>
                <div class="text-2xl font-bold" style="color:var(--ink)"><?= number_format($stat['count']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filtres -->
    <div class="ds-card rounded-lg shadow p-6">
        <form method="GET" action="<?= url('/admin/audit-logs') ?>" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label for="user_id" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Utilisateur</label>
                <select id="user_id" name="user_id" class="w-full px-3 py-2 rounded-lg">
                    <option value="">Tous</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($filters['user_id'] ?? null) == $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="action" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Action</label>
                <select id="action" name="action" class="w-full px-3 py-2 rounded-lg">
                    <option value="">Toutes</option>
                    <option value="document.created" <?= ($filters['action'] ?? '') === 'document.created' ? 'selected' : '' ?>>Document créé</option>
                    <option value="document.updated" <?= ($filters['action'] ?? '') === 'document.updated' ? 'selected' : '' ?>>Document modifié</option>
                    <option value="document.deleted" <?= ($filters['action'] ?? '') === 'document.deleted' ? 'selected' : '' ?>>Document supprimé</option>
                    <option value="document.restored" <?= ($filters['action'] ?? '') === 'document.restored' ? 'selected' : '' ?>>Document restauré</option>
                    <option value="tag.created" <?= ($filters['action'] ?? '') === 'tag.created' ? 'selected' : '' ?>>Tag créé</option>
                    <option value="tag.updated" <?= ($filters['action'] ?? '') === 'tag.updated' ? 'selected' : '' ?>>Tag modifié</option>
                    <option value="tag.deleted" <?= ($filters['action'] ?? '') === 'tag.deleted' ? 'selected' : '' ?>>Tag supprimé</option>
                </select>
            </div>
            
            <div>
                <label for="date_from" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Date début</label>
                <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" class="w-full px-3 py-2 rounded-lg">
            </div>
            
            <div>
                <label for="date_to" class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Date fin</label>
                <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" class="w-full px-3 py-2 rounded-lg">
            </div>
            
            <div class="md:col-span-4 flex justify-end space-x-2">
                <a href="<?= url('/admin/audit-logs') ?>" class="btn btn-secondary">Réinitialiser</a>
                <button type="submit" class="btn btn-primary">Filtrer</button>
            </div>
        </form>
    </div>

    <!-- Liste des logs -->
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <table class="ds-table">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Utilisateur</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Action</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Objet</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Changements</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center" style="color:var(--dim)">
                        Aucun log d'audit trouvé.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                        <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                        <?= htmlspecialchars($log['user_username'] ?? 'Système') ?>
                    </td>
                    <td class="px-6 py-4">
                        <code class="text-xs ds-chip ds-chip--neutral px-2 py-1">
                            <?= htmlspecialchars($log['action']) ?>
                        </code>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <div class="font-medium"><?= htmlspecialchars($log['object_type']) ?> #<?= $log['object_id'] ?></div>
                        <?php if ($log['object_name']): ?>
                        <div class="text-xs" style="color:var(--dim)"><?= htmlspecialchars($log['object_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm" style="color:var(--ink-soft)">
                        <?php if (!empty($log['changes'])): ?>
                        <details class="cursor-pointer">
                            <summary class="hover:underline" style="color:var(--accent)">Voir changements</summary>
                            <pre class="mt-2 text-xs p-2 rounded overflow-auto max-h-40" style="background:var(--app-bg)"><?= htmlspecialchars(json_encode($log['changes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </details>
                        <?php else: ?>
                        <span style="color:var(--dim)">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm" style="color:var(--ink-soft)">
                        <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-6 py-4 border-t flex items-center justify-between">
            <div class="text-sm" style="color:var(--ink-soft)">
                Page <?= $page ?> sur <?= $totalPages ?> (<?= $total ?> logs)
            </div>
            <div class="flex space-x-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= !empty($filters) ? '&' . http_build_query($filters) : '' ?>" class="btn btn-secondary">Précédent</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= !empty($filters) ? '&' . http_build_query($filters) : '' ?>" class="btn btn-secondary">Suivant</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
