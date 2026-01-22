<?php
// Partial pour l'historique (utilisé dans l'onglet)
?>
<div class="space-y-4">
    <?php if (empty($history)): ?>
    <p class="text-gray-500 text-sm">Aucune modification enregistrée</p>
    <?php else: ?>
    <?php foreach ($history as $entry): ?>
    <div class="border-l-4 <?= $entry['action'] === 'created' ? 'border-green-500' : 'border-blue-500' ?> pl-4 py-2">
        <div class="flex items-center justify-between">
            <div>
                <span class="font-medium text-gray-900">
                    <?= htmlspecialchars($entry['field_name']) ?>
                </span>
                <span class="text-sm text-gray-500 ml-2">
                    par <?= htmlspecialchars($entry['user_name'] ?? 'Inconnu') ?>
                </span>
            </div>
            <span class="text-sm text-gray-500">
                <?= date('d/m/Y à H:i', strtotime($entry['created_at'])) ?>
            </span>
        </div>
        <?php if ($entry['action'] !== 'created' && ($entry['old_value'] || $entry['new_value'])): ?>
        <div class="mt-2 text-sm">
            <span class="text-red-600 line-through"><?= htmlspecialchars($entry['old_value'] ?? '-') ?></span>
            <span class="mx-2">→</span>
            <span class="text-green-600 font-medium"><?= htmlspecialchars($entry['new_value'] ?? '-') ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
