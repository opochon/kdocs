<?php
// Comparaison de snapshots
?>

<div class="max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <a href="<?= url('/admin/snapshots') ?>" class="text-sm hover:underline" style="color:var(--accent)">&larr; Retour aux snapshots</a>
            <h1 class="text-2xl font-bold mt-2" style="color:var(--ink)">Comparer les snapshots</h1>
        </div>
    </div>

    <!-- Selecteurs -->
    <div class="ds-card rounded-lg shadow p-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Snapshot de base</label>
                <select name="from" required class="w-full px-3 py-2 rounded-lg">
                    <option value="">Selectionnez...</option>
                    <?php foreach ($snapshots as $snap): ?>
                    <option value="<?= $snap['id'] ?>" <?= $fromId == $snap['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($snap['name']) ?> (<?= date('d/m/Y', strtotime($snap['created_at'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" style="color:var(--ink-soft)">Comparer a</label>
                <select name="to" required class="w-full px-3 py-2 rounded-lg">
                    <option value="">Selectionnez...</option>
                    <?php foreach ($snapshots as $snap): ?>
                    <option value="<?= $snap['id'] ?>" <?= $toId == $snap['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($snap['name']) ?> (<?= date('d/m/Y', strtotime($snap['created_at'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="w-full btn btn-primary">
                    Comparer
                </button>
            </div>
        </form>
    </div>

    <?php if ($diff): ?>
    <!-- Resume -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-lg shadow p-6" style="background:color-mix(in srgb,var(--green) 10%,transparent)">
            <div class="text-sm" style="color:var(--green)">Ajoutes</div>
            <div class="text-3xl font-bold" style="color:var(--green)"><?= count($diff['added'] ?? []) ?></div>
        </div>
        <div class="rounded-lg shadow p-6" style="background:color-mix(in srgb,var(--amber) 10%,transparent)">
            <div class="text-sm" style="color:var(--amber)">Modifies</div>
            <div class="text-3xl font-bold" style="color:var(--amber)"><?= count($diff['modified'] ?? []) ?></div>
        </div>
        <div class="rounded-lg shadow p-6" style="background:color-mix(in srgb,var(--red) 10%,transparent)">
            <div class="text-sm" style="color:var(--red)">Supprimes</div>
            <div class="text-3xl font-bold" style="color:var(--red)"><?= count($diff['removed'] ?? []) ?></div>
        </div>
    </div>

    <!-- Details des changements -->
    <?php if (!empty($diff['added'])): ?>
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold" style="color:var(--green)">Elements ajoutes (<?= count($diff['added']) ?>)</h3>
        </div>
        <table class="w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($diff['added'], 0, 50) as $item): ?>
                <tr>
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-1 text-xs font-medium ds-chip ds-chip--green"><?= ucfirst($item['entity_type']) ?></span>
                    </td>
                    <td class="px-6 py-3 text-sm" style="color:var(--ink-soft)">#<?= $item['entity_id'] ?></td>
                    <td class="px-6 py-3 text-sm" style="color:var(--ink)"><?= htmlspecialchars($item['entity_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($diff['added']) > 50): ?>
                <tr>
                    <td colspan="3" class="px-6 py-3 text-center text-sm" style="color:var(--dim)">
                        ... et <?= count($diff['added']) - 50 ?> autres elements
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($diff['modified'])): ?>
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold" style="color:var(--amber)">Elements modifies (<?= count($diff['modified']) ?>)</h3>
        </div>
        <table class="w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Changement</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($diff['modified'], 0, 50) as $item): ?>
                <tr>
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-1 text-xs font-medium ds-chip ds-chip--amber"><?= ucfirst($item['entity_type']) ?></span>
                    </td>
                    <td class="px-6 py-3 text-sm" style="color:var(--ink-soft)">#<?= $item['entity_id'] ?></td>
                    <td class="px-6 py-3 text-sm" style="color:var(--ink)"><?= htmlspecialchars($item['entity_name'] ?? '-') ?></td>
                    <td class="px-6 py-3 text-sm" style="color:var(--dim)">
                        <?php if (!empty($item['changes'])): ?>
                        <code class="text-xs"><?= htmlspecialchars(json_encode($item['changes'])) ?></code>
                        <?php else: ?>
                        Checksum modifie
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($diff['modified']) > 50): ?>
                <tr>
                    <td colspan="4" class="px-6 py-3 text-center text-sm" style="color:var(--dim)">
                        ... et <?= count($diff['modified']) - 50 ?> autres elements
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($diff['removed'])): ?>
    <div class="ds-card rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold" style="color:var(--red)">Elements supprimes (<?= count($diff['removed']) ?>)</h3>
        </div>
        <table class="w-full">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase">Nom</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($diff['removed'], 0, 50) as $item): ?>
                <tr>
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-1 text-xs font-medium ds-chip ds-chip--red"><?= ucfirst($item['entity_type']) ?></span>
                    </td>
                    <td class="px-6 py-3 text-sm" style="color:var(--ink-soft)">#<?= $item['entity_id'] ?></td>
                    <td class="px-6 py-3 text-sm" style="color:var(--ink)"><?= htmlspecialchars($item['entity_name'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($diff['removed']) > 50): ?>
                <tr>
                    <td colspan="3" class="px-6 py-3 text-center text-sm" style="color:var(--dim)">
                        ... et <?= count($diff['removed']) - 50 ?> autres elements
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php elseif ($fromId && $toId): ?>
    <div class="ds-card rounded-lg shadow p-8 text-center">
        <p style="color:var(--dim)">Aucune difference trouvee entre ces deux snapshots.</p>
    </div>
    <?php else: ?>
    <div class="ds-card rounded-lg shadow p-8 text-center">
        <p style="color:var(--dim)">Selectionnez deux snapshots a comparer.</p>
    </div>
    <?php endif; ?>
</div>
