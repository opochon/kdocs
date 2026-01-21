<?php
// $document et $history sont passés depuis le contrôleur
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Historique des modifications</h1>
        <a href="<?= url('/documents/' . $document['id']) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
            ← Retour au document
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">
            <?= htmlspecialchars($document['title'] ?: $document['original_filename']) ?>
        </h2>
        
        <?php if (empty($history)): ?>
        <p class="text-gray-500 text-center py-8">Aucune modification enregistrée</p>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($history as $entry): ?>
            <div class="border-l-4 border-blue-500 pl-4 py-2">
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
                <div class="mt-2 text-sm">
                    <span class="text-red-600 line-through"><?= htmlspecialchars($entry['old_value'] ?? '-') ?></span>
                    <span class="mx-2">→</span>
                    <span class="text-green-600 font-medium"><?= htmlspecialchars($entry['new_value'] ?? '-') ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
