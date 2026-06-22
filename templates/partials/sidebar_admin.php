<?php
require __DIR__ . '/sidebar_nav_helpers.php';

$stats = [
    'tags' => 0,
    'correspondents' => 0,
    'saved_searches' => 0,
    'pending_validation' => 0,
];
try {
    $db = \KDocs\Core\Database::getInstance();
    $stats['tags'] = (int) $db->query('SELECT COUNT(*) FROM tags')->fetchColumn();
    $stats['correspondents'] = (int) $db->query('SELECT COUNT(*) FROM correspondents')->fetchColumn();
    $stats['saved_searches'] = (int) $db->query('SELECT COUNT(*) FROM saved_searches')->fetchColumn();
    $stats['pending_validation'] = (int) $db->query(
        "SELECT COUNT(*) FROM documents WHERE status IN ('pending', 'needs_review')"
    )->fetchColumn();
} catch (\Exception $e) {
    // Tables absentes
}
?>

<aside class="w-52 bg-white border-r border-gray-100 flex flex-col">
    <div class="p-4 border-b border-gray-100">
        <a href="<?= url('/documents') ?>" class="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1 mb-2">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Retour à K-Docs
        </a>
        <h1 class="text-base font-medium text-gray-900">Administration</h1>
    </div>

    <nav class="flex-1 px-2 py-3 overflow-y-auto">
        <ul class="space-y-0.5">
            <li>
                <a href="<?= url('/admin') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= ($currentRoute === url('/admin') || $currentRoute === url('/admin') . '/') ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                    </svg>
                    <span>Hub admin</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/diagnostic') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/diagnostic', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    <span>Diagnostic</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/consume') ?>" class="flex items-center justify-between px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/consume', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Fichiers à valider</span>
                    </div>
                    <?php if ($stats['pending_validation'] > 0): ?>
                    <span class="px-1.5 py-0.5 text-xs font-medium bg-red-100 text-red-800 rounded-full"><?= $stats['pending_validation'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/indexing') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/indexing', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span>Indexation</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/time') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/time', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>K-Time</span>
                </a>
            </li>

            <li class="pt-3 pb-1.5">
                <div class="px-2 text-xs font-medium text-gray-400 uppercase tracking-wider">Référentiels</div>
            </li>
            <li>
                <a href="<?= url('/admin/tags') ?>" class="flex items-center justify-between px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/tags', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                        </svg>
                        <span>Étiquettes</span>
                    </div>
                    <?php if ($stats['tags'] > 0): ?>
                    <span class="text-xs text-gray-400"><?= $stats['tags'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/correspondents') ?>" class="flex items-center justify-between px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/correspondents', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <span>Correspondants</span>
                    </div>
                    <?php if ($stats['correspondents'] > 0): ?>
                    <span class="text-xs text-gray-400"><?= $stats['correspondents'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/document-types') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/document-types', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                    </svg>
                    <span>Types de document</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/custom-fields') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/custom-fields', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                    <span>Champs personnalisés</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/classification-fields') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/classification-fields', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                    </svg>
                    <span>Champs de classification</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/attribution-rules') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/attribution-rules', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                    <span>Règles d'attribution</span>
                </a>
            </li>
            <?php if ($stats['saved_searches'] > 0): ?>
            <li>
                <a href="<?= url('/documents?saved_search=1') ?>" class="flex items-center justify-between px-2 py-1.5 rounded text-sm transition-colors <?= str_contains($currentRoute, 'saved_search') ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"></path>
                        </svg>
                        <span>Vues enregistrées</span>
                    </div>
                    <span class="text-xs text-gray-400"><?= $stats['saved_searches'] ?></span>
                </a>
            </li>
            <?php endif; ?>
            <li>
                <a href="<?= url('/admin/workflows') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/workflows', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span>Workflows</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/webhooks') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/webhooks', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                    </svg>
                    <span>Webhooks</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/audit-logs') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/audit-logs', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                    <span>Journaux</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/export-import') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/export-import', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span>Export/Import</span>
                </a>
            </li>

            <li class="pt-3 pb-1.5">
                <div class="px-2 text-xs font-medium text-gray-400 uppercase tracking-wider">Système</div>
            </li>
            <li>
                <a href="<?= url('/admin/settings') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/settings', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>Paramètres</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/api-usage') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/api-usage', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <span>Statistiques API</span>
                </a>
            </li>
            <?php if ($user && ($user['is_admin'] ?? false)): ?>
            <li>
                <a href="<?= url('/admin/users') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/users', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <span>Utilisateurs</span>
                </a>
            </li>
            <li>
                <a href="<?= url('/admin/user-groups') ?>" class="flex items-center px-2 py-1.5 rounded text-sm transition-colors <?= sidebarIsActive('/admin/user-groups', $currentRoute, $basePath) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <span>Groupes</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="p-4 border-t border-gray-200 bg-gray-50">
        <?php if ($user): ?>
            <div class="text-sm">
                <p class="font-medium text-gray-900"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></p>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($user['email'] ?? '') ?></p>
            </div>
        <?php endif; ?>
    </div>
</aside>
