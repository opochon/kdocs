<?php
$user = $user ?? null;
$currentRoute = $_SERVER['REQUEST_URI'] ?? '/';
$basePath = \KDocs\Core\Config::basePath();
$currentPage = $currentPage ?? '';

// Fonction helper pour vérifier si une route est active
function isActive($route, $currentRoute, $basePath) {
    $fullRoute = $basePath . $route;
    return strpos($currentRoute, $fullRoute) !== false || ($route === '/' && ($currentRoute === $basePath . '/' || $currentRoute === $basePath));
}

// Récupérer les statistiques pour le menu
$db = \KDocs\Core\Database::getInstance();
$stats = [
    'documents' => 0,
    'tags' => 0,
    'correspondents' => 0,
    'saved_searches' => 0
];
try {
    $stats['documents'] = $db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL")->fetchColumn();
    $stats['tags'] = $db->query("SELECT COUNT(*) FROM tags")->fetchColumn();
    $stats['correspondents'] = $db->query("SELECT COUNT(*) FROM correspondents")->fetchColumn();
    $stats['saved_searches'] = $db->query("SELECT COUNT(*) FROM saved_searches")->fetchColumn();
} catch (\Exception $e) {
    // Tables n'existent pas encore
}
?>

<aside class="w-64 bg-white border-r border-gray-200 flex flex-col">
    <div class="p-6 border-b border-gray-200">
        <h1 class="text-xl font-semibold text-gray-900">K-Docs</h1>
        <p class="text-xs text-gray-500 mt-1">Gestion de documents</p>
    </div>
    
    <nav class="flex-1 px-3 py-4 overflow-y-auto">
        <ul class="space-y-1">
            <!-- Documents - Principal -->
            <li>
                <a href="<?= url('/documents') ?>" class="flex items-center justify-between px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/documents', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Documents</span>
                    </div>
                    <span class="text-xs text-gray-500 font-normal"><?= $stats['documents'] ?></span>
                </a>
            </li>
            
            <!-- Dashboard -->
            <li>
                <a href="<?= url('/') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <!-- Saved Searches -->
            <?php if ($stats['saved_searches'] > 0): ?>
            <li>
                <a href="<?= url('/documents?saved_search=1') ?>" class="flex items-center justify-between px-3 py-2 rounded-md text-sm font-medium transition-colors <?= strpos($currentRoute, 'saved_search') !== false ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                        </svg>
                        <span>Recherches sauvegardées</span>
                    </div>
                    <span class="text-xs text-gray-500 font-normal"><?= $stats['saved_searches'] ?></span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Séparateur -->
            <li class="pt-4 pb-2">
                <div class="px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Gestion</div>
            </li>
            
            <!-- Tags -->
            <li>
                <a href="<?= url('/admin/tags') ?>" class="flex items-center justify-between px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/tags', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                        </svg>
                        <span>Tags</span>
                    </div>
                    <span class="text-xs text-gray-500 font-normal"><?= $stats['tags'] ?></span>
                </a>
            </li>
            
            <!-- Correspondants -->
            <li>
                <a href="<?= url('/admin/correspondents') ?>" class="flex items-center justify-between px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/correspondents', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <span>Correspondants</span>
                    </div>
                    <span class="text-xs text-gray-500 font-normal"><?= $stats['correspondents'] ?></span>
                </a>
            </li>
            
            <!-- Document Types -->
            <li>
                <a href="<?= url('/admin/document-types') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/document-types', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>Types de documents</span>
                </a>
            </li>
            
            <!-- Custom Fields -->
            <li>
                <a href="<?= url('/admin/custom-fields') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/custom-fields', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>Champs personnalisés</span>
                </a>
            </li>
            
            <!-- Storage Paths -->
            <li>
                <a href="<?= url('/admin/storage-paths') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/storage-paths', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h12a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>Chemins de stockage</span>
                </a>
            </li>
            
            <!-- Workflows -->
            <li>
                <a href="<?= url('/admin/workflows') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/workflows', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span>Workflows</span>
                </a>
            </li>
            
            <!-- Webhooks -->
            <li>
                <a href="<?= url('/admin/webhooks') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/webhooks', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                    </svg>
                    <span>Webhooks</span>
                </a>
            </li>
            
            <!-- Audit Logs -->
            <li>
                <a href="<?= url('/admin/audit-logs') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/audit-logs', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span>Logs d'audit</span>
                </a>
            </li>
            
            <!-- Export/Import -->
            <li>
                <a href="<?= url('/admin/export-import') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/export-import', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    <span>Export/Import</span>
                </a>
            </li>
            
            <!-- Séparateur -->
            <li class="pt-4 pb-2">
                <div class="px-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Configuration</div>
            </li>
            
            <!-- Settings -->
            <li>
                <a href="<?= url('/admin/settings') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin/settings', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span>Paramètres</span>
                </a>
            </li>
            
            <!-- Administration (si admin) -->
            <?php if ($user && ($user['is_admin'] ?? false)): ?>
            <li>
                <a href="<?= url('/admin') ?>" class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors <?= isActive('/admin', $currentRoute, $basePath) && !isActive('/admin/tags', $currentRoute, $basePath) && !isActive('/admin/correspondents', $currentRoute, $basePath) && !isActive('/admin/settings', $currentRoute, $basePath) && !isActive('/admin/custom-fields', $currentRoute, $basePath) && !isActive('/admin/storage-paths', $currentRoute, $basePath) && !isActive('/admin/workflows', $currentRoute, $basePath) && !isActive('/admin/webhooks', $currentRoute, $basePath) && !isActive('/admin/document-types', $currentRoute, $basePath) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50' ?>">
                    <svg class="w-5 h-5 mr-2.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <span>Utilisateurs</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    
    <div class="p-4 border-t border-gray-200 bg-gray-50">
        <?php if ($user): ?>
            <div class="text-sm">
                <p class="font-medium text-gray-900"><?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></p>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($user['email'] ?? '') ?></p>
            </div>
        <?php endif; ?>
    </div>
</aside>
