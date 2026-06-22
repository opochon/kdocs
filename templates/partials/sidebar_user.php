<?php
require __DIR__ . '/sidebar_nav_helpers.php';

$stats = [
    'documents' => 0,
    'pending_validation' => 0,
    'tasks' => 0,
];
try {
    $db = \KDocs\Core\Database::getInstance();
    $docFilter = documentVisibilitySql('documents');
    $stats['documents'] = (int) $db->query(
        "SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL AND (status IS NULL OR status != 'pending') AND {$docFilter}"
    )->fetchColumn();
    $stats['pending_validation'] = (int) $db->query(
        "SELECT COUNT(*) FROM documents WHERE status IN ('pending', 'needs_review')"
    )->fetchColumn();
    if ($user && !empty($user['id'])) {
        $taskService = new \KDocs\Services\TaskUnifiedService();
        $taskCounts = $taskService->getTaskCounts($user['id']);
        $stats['tasks'] = (int) ($taskCounts['total'] ?? 0);
    }
} catch (\Exception $e) {
    // Tables absentes en setup minimal
}

$inboxBadge = max($stats['pending_validation'], $stats['tasks']);
?>

<aside class="w-52 bg-white border-r border-gray-100 flex flex-col">
    <div class="p-4 border-b border-gray-100">
        <h1 class="text-base font-medium text-gray-900">K-Docs</h1>
    </div>

    <nav class="flex-1 px-2 py-3 overflow-y-auto">
        <ul class="space-y-0.5">
            <li>
                <a href="<?= url('/documents') ?>" class="flex items-center justify-between px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/documents', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Bibliothèque</span>
                    </div>
                    <?php if ($stats['documents'] > 0): ?>
                    <span class="text-xs text-gray-400"><?= $stats['documents'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= url('/chat') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/chat', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <span>Recherche</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/mes-taches') ?>" class="flex items-center justify-between px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/mes-taches', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                        </svg>
                        <span>À traiter</span>
                    </div>
                    <?php if ($inboxBadge > 0): ?>
                    <span class="px-1.5 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded-full"><?= $inboxBadge ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= url('/documents/upload') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/documents/upload', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    <span>Importer</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="px-2 py-2 border-t border-gray-100">
        <a href="<?= url('/admin') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
            <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            <span>Administration</span>
        </a>
    </div>

    <div class="p-4 border-t border-gray-200 bg-gray-50">
        <?php if ($user): ?>
            <div class="text-sm">
                <p class="font-medium text-gray-900"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></p>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($user['email'] ?? '') ?></p>
            </div>
        <?php endif; ?>
    </div>
</aside>
