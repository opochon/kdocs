<?php
// Hub admin — tuiles épurées (B1.2)
// $stats passé par AdminController
// $adminTiles passé par AdminController

$adminTiles = $adminTiles ?? [];
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900">Administration</h1>
        <p class="text-sm text-gray-500 mt-1">Référentiels, diagnostic et paramètres système</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <p class="text-xs text-gray-500">Utilisateurs</p>
            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= (int) ($stats['users'] ?? 0) ?></p>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <p class="text-xs text-gray-500">Documents</p>
            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= (int) ($stats['documents'] ?? 0) ?></p>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <p class="text-xs text-gray-500">Types</p>
            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= (int) ($stats['document_types'] ?? 0) ?></p>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <p class="text-xs text-gray-500">Correspondants</p>
            <p class="text-2xl font-semibold text-gray-900 mt-1"><?= (int) ($stats['correspondents'] ?? 0) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($adminTiles as $tile): ?>
        <a href="<?= htmlspecialchars($tile['href'], ENT_QUOTES, 'UTF-8') ?>"
           class="bg-white border border-gray-200 rounded-lg p-4 hover:border-gray-300 transition-colors flex gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-gray-50 flex items-center justify-center text-gray-500">
                <?= $tile['icon'] ?? '' ?>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($tile['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($tile['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
