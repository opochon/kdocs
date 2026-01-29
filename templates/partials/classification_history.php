<?php
/**
 * Historique des classifications d'un document
 * À inclure dans la vue détail document
 *
 * Variables attendues:
 * - $documentId: ID du document
 * - $history: array de l'historique (optionnel, sera chargé si absent)
 */

if (!isset($history)) {
    $history = [];
    try {
        $auditService = new \KDocs\Services\Audit\ClassificationAuditService();
        $history = $auditService->getDocumentHistory($documentId, 20);
    } catch (\Exception $e) {
        // Ignorer si le service n'est pas disponible
    }
}

if (empty($history)) {
    return;
}

$sourceIcons = [
    'manual' => ['icon' => 'fa-user', 'color' => 'text-blue-500', 'bg' => 'bg-blue-100'],
    'rules' => ['icon' => 'fa-layer-group', 'color' => 'text-purple-500', 'bg' => 'bg-purple-100'],
    'ml' => ['icon' => 'fa-brain', 'color' => 'text-green-500', 'bg' => 'bg-green-100'],
    'ai' => ['icon' => 'fa-robot', 'color' => 'text-orange-500', 'bg' => 'bg-orange-100'],
    'import' => ['icon' => 'fa-upload', 'color' => 'text-gray-500', 'bg' => 'bg-gray-100'],
    'api' => ['icon' => 'fa-code', 'color' => 'text-indigo-500', 'bg' => 'bg-indigo-100']
];
?>

<div id="classification-history" class="bg-white rounded-lg shadow mt-6">
    <div class="px-6 py-4 border-b">
        <h3 class="font-medium text-gray-800">
            <i class="fas fa-history text-gray-500 mr-2"></i>Historique des classifications
        </h3>
    </div>

    <div class="divide-y divide-gray-100">
        <?php foreach ($history as $entry):
            $source = $sourceIcons[$entry['change_source']] ?? $sourceIcons['manual'];
        ?>
            <div class="px-6 py-3 flex items-start gap-4 hover:bg-gray-50">
                <div class="<?= $source['bg'] ?> rounded-full p-2 mt-1">
                    <i class="fas <?= $source['icon'] ?> <?= $source['color'] ?> text-sm"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-gray-800">
                            <?= htmlspecialchars($entry['field_label'] ?? $entry['field_code']) ?>
                        </span>
                        <?php if (!empty($entry['rule_name'])): ?>
                            <span class="text-xs text-purple-600 bg-purple-50 px-2 py-0.5 rounded">
                                <?= htmlspecialchars($entry['rule_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1">
                        <?php if ($entry['old_value']): ?>
                            <span class="line-through text-gray-400"><?= htmlspecialchars($entry['old_value']) ?></span>
                            <i class="fas fa-arrow-right text-gray-300 mx-2"></i>
                        <?php endif; ?>
                        <span class="font-medium"><?= htmlspecialchars($entry['new_value'] ?? '(vide)') ?></span>
                    </div>
                    <?php if (!empty($entry['change_reason'])): ?>
                        <div class="text-xs text-gray-500 mt-1">
                            <?= htmlspecialchars($entry['change_reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-right text-sm">
                    <div class="text-gray-500">
                        <?= date('d/m/Y H:i', strtotime($entry['created_at'])) ?>
                    </div>
                    <?php if (!empty($entry['user_name'])): ?>
                        <div class="text-xs text-gray-400">
                            par <?= htmlspecialchars($entry['user_name']) ?>
                        </div>
                    <?php else: ?>
                        <div class="text-xs text-gray-400">
                            <?= htmlspecialchars($entry['source_label'] ?? $entry['change_source']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($history) >= 20): ?>
        <div class="px-6 py-3 border-t text-center">
            <a href="<?= url('/api/documents/' . $documentId . '/classification-history') ?>"
               class="text-sm text-blue-600 hover:underline">
                Voir l'historique complet
            </a>
        </div>
    <?php endif; ?>
</div>
