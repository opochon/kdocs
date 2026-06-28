<?php
// $document et $history sont passés depuis le contrôleur
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold" style="color:var(--ink)">Historique des modifications</h1>
        <a href="<?= url('/documents/' . $document['id']) ?>" class="btn btn-secondary">
            ← Retour au document
        </a>
    </div>

    <div class="ds-card p-6">
        <h2 class="text-lg font-semibold mb-4" style="color:var(--ink)">
            <?= htmlspecialchars($document['title'] ?: $document['original_filename']) ?>
        </h2>

        <?php if (empty($history)): ?>
        <p class="text-center py-8" style="color:var(--dim)">Aucune modification enregistrée</p>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($history as $entry): ?>
            <div class="border-l-4 pl-4 py-2" style="border-color:var(--border)">
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
                <div class="mt-2 text-sm">
                    <span class="line-through" style="color:var(--red)"><?= htmlspecialchars($entry['old_value'] ?? '-') ?></span>
                    <span class="mx-2">→</span>
                    <span class="font-medium" style="color:var(--green)"><?= htmlspecialchars($entry['new_value'] ?? '-') ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
