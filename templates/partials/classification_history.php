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

// DS monochrome : la source (manuel/regles/ml/ia/import/api) est une categorie,
// pas un etat -> pastille neutre tokenisee, l'icone herite de --ink-soft.
$sourceIcons = [
    'manual' => ['icon' => 'user', 'color' => '', 'bg' => 'ds-chip--neutral'],
    'rules' => ['icon' => 'layer-group', 'color' => '', 'bg' => 'ds-chip--neutral'],
    'ml' => ['icon' => 'brain', 'color' => '', 'bg' => 'ds-chip--neutral'],
    'ai' => ['icon' => 'robot', 'color' => '', 'bg' => 'ds-chip--neutral'],
    'import' => ['icon' => 'upload', 'color' => '', 'bg' => 'ds-chip--neutral'],
    'api' => ['icon' => 'code', 'color' => '', 'bg' => 'ds-chip--neutral']
];
?>

<div id="classification-history" class="ds-card mt-6">
    <div class="px-6 py-4 border-b">
        <h3 class="font-medium" style="color:var(--ink)">
            <?= icon('history', ['class' => 'mr-2', 'style' => 'color:var(--dim)']) ?>Historique des classifications
        </h3>
    </div>

    <div class="ds-divide-y">
        <?php foreach ($history as $entry):
            $source = $sourceIcons[$entry['change_source']] ?? $sourceIcons['manual'];
        ?>
            <div class="ds-row-hover px-6 py-3 flex items-start gap-4">
                <div class="<?= $source['bg'] ?> rounded-full p-2 mt-1">
                    <?= icon($source['icon'], ['class' => trim($source['color'] . ' text-sm')]) ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium" style="color:var(--ink)">
                            <?= htmlspecialchars($entry['field_label'] ?? $entry['field_code']) ?>
                        </span>
                        <?php if (!empty($entry['rule_name'])): ?>
                            <span class="ds-chip--accent text-xs px-2 py-0.5 rounded">
                                <?= htmlspecialchars($entry['rule_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-sm mt-1" style="color:var(--ink-soft)">
                        <?php if ($entry['old_value']): ?>
                            <span class="line-through" style="color:var(--muted)"><?= htmlspecialchars($entry['old_value']) ?></span>
                            <?= icon('arrow-right', ['class' => 'mx-2', 'style' => 'color:var(--muted)']) ?>
                        <?php endif; ?>
                        <span class="font-medium"><?= htmlspecialchars($entry['new_value'] ?? '(vide)') ?></span>
                    </div>
                    <?php if (!empty($entry['change_reason'])): ?>
                        <div class="text-xs mt-1" style="color:var(--dim)">
                            <?= htmlspecialchars($entry['change_reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-right text-sm">
                    <div style="color:var(--dim)">
                        <?= date('d/m/Y H:i', strtotime($entry['created_at'])) ?>
                    </div>
                    <?php if (!empty($entry['user_name'])): ?>
                        <div class="text-xs" style="color:var(--dim)">
                            par <?= htmlspecialchars($entry['user_name']) ?>
                        </div>
                    <?php else: ?>
                        <div class="text-xs" style="color:var(--dim)">
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
               class="text-sm hover:underline" style="color:var(--accent)">
                Voir l'historique complet
            </a>
        </div>
    <?php endif; ?>
</div>
