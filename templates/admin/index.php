<?php
// Hub admin — tuiles épurées (B1.2)
// $stats passé par AdminController
// $adminTiles passé par AdminController

$adminTiles = $adminTiles ?? [];
?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold" style="color:var(--ink)">Administration</h1>
        <p class="text-sm mt-1" style="color:var(--dim)">Référentiels, diagnostic et paramètres système</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="ds-card rounded-lg p-4">
            <p class="text-xs" style="color:var(--dim)">Utilisateurs</p>
            <p class="text-2xl font-semibold mt-1" style="color:var(--ink)"><?= (int) ($stats['users'] ?? 0) ?></p>
        </div>
        <div class="ds-card rounded-lg p-4">
            <p class="text-xs" style="color:var(--dim)">Documents</p>
            <p class="text-2xl font-semibold mt-1" style="color:var(--ink)"><?= (int) ($stats['documents'] ?? 0) ?></p>
        </div>
        <div class="ds-card rounded-lg p-4">
            <p class="text-xs" style="color:var(--dim)">Types</p>
            <p class="text-2xl font-semibold mt-1" style="color:var(--ink)"><?= (int) ($stats['document_types'] ?? 0) ?></p>
        </div>
        <div class="ds-card rounded-lg p-4">
            <p class="text-xs" style="color:var(--dim)">Correspondants</p>
            <p class="text-2xl font-semibold mt-1" style="color:var(--ink)"><?= (int) ($stats['correspondents'] ?? 0) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($adminTiles as $tile): ?>
        <a href="<?= htmlspecialchars($tile['href'], ENT_QUOTES, 'UTF-8') ?>"
           class="ds-card ds-card--link rounded-lg p-4 transition-colors flex gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center" style="background:var(--app-bg);color:var(--dim)">
                <?= $tile['icon'] ?? '' ?>
            </div>
            <div class="min-w-0">
                <div class="text-sm font-medium" style="color:var(--ink)"><?= htmlspecialchars($tile['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <p class="text-xs mt-0.5" style="color:var(--dim)"><?= htmlspecialchars($tile['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
