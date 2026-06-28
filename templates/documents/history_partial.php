<?php
// Partial pour l'historique (utilisé dans l'onglet)
?>
<div class="space-y-4">
    <?php if (empty($history)): ?>
    <p class="text-sm" style="color:var(--dim)">Aucune modification enregistrée</p>
    <?php else: ?>
    <?php foreach ($history as $entry): ?>
    <div class="border-l-4 pl-4 py-2" style="border-color:<?= $entry['action'] === 'created' ? 'var(--green)' : 'var(--border)' ?>">
        <div class="flex items-center justify-between">
            <div>
                <span class="font-medium" style="color:var(--ink)">
                    <?= htmlspecialchars($entry['field_name']) ?>
                </span>
                <span class="text-sm ml-2" style="color:var(--dim)">
                    par <?= htmlspecialchars($entry['user_name'] ?? 'Inconnu') ?>
                </span>
            </div>
            <span class="text-sm" style="color:var(--dim)">
                <?= date('d/m/Y à H:i', strtotime($entry['created_at'])) ?>
            </span>
        </div>
        <?php if ($entry['action'] !== 'created' && ($entry['old_value'] || $entry['new_value'])): ?>
        <div class="mt-2 text-sm">
            <span class="line-through" style="color:var(--red)"><?= htmlspecialchars($entry['old_value'] ?? '-') ?></span>
            <span class="mx-2">→</span>
            <span class="font-medium" style="color:var(--green)"><?= htmlspecialchars($entry['new_value'] ?? '-') ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
