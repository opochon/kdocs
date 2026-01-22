<?php
// Vue grille style Paperless-ngx - Version sobre et épurée
// $documents, $logicalFolders, $fsFolders, $documentTypes, $tags, $search, $logicalFolderId, $folderId, $typeId, $currentFolder sont passés
use KDocs\Core\Config;
use KDocs\Models\LogicalFolder;
$base = Config::basePath();
?>

<div class="flex min-h-screen bg-gray-50">
    
    <!-- Sidebar gauche - Épurée -->
    <aside class="w-56 bg-white border-r border-gray-200 overflow-y-auto">
        <!-- Dossiers logiques -->
        <?php if (!empty($logicalFolders)): ?>
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Dossiers logiques</h2>
        </div>
        <nav class="px-2 py-2">
            <?php foreach ($logicalFolders as $lfolder): ?>
            <?php 
            $isActive = ($logicalFolderId == $lfolder['id']);
            $count = LogicalFolder::countDocuments($lfolder['id']);
            ?>
            <a href="<?= url('/documents?logical_folder=' . $lfolder['id']) ?>" 
               class="flex items-center px-2 py-1.5 text-sm rounded hover:bg-gray-50 mb-0.5
                      <?= $isActive ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                <span class="mr-2 text-gray-400"><?= htmlspecialchars($lfolder['icon'] ?: '📁') ?></span>
                <span class="flex-1"><?= htmlspecialchars($lfolder['name']) ?></span>
                <?php if ($count > 0): ?>
                <span class="text-xs text-gray-400 ml-2"><?= $count ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <!-- Dossiers filesystem -->
        <?php if (!empty($fsFolders) || $folderId): ?>
        <div class="px-4 py-3 border-t border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Dossiers</h2>
        </div>
        <nav class="px-2 py-2">
            <?php if ($folderId): ?>
            <?php
            $currentPath = $currentFolderPath ?? null;
            if (!$currentPath) {
                foreach ($fsFolders as $f) {
                    if ($f['id'] === $folderId) {
                        $currentPath = $f['path'];
                        break;
                    }
                }
            }
            $parentPath = null;
            if ($currentPath && $currentPath !== '/' && $currentPath !== '') {
                $parts = explode('/', trim($currentPath, '/'));
                if (count($parts) > 1) {
                    array_pop($parts);
                    $parentPath = implode('/', $parts);
                } else {
                    $parentPath = '/';
                }
            }
            ?>
            <?php if ($parentPath !== null && $parentPath !== ''): ?>
            <a href="<?= url('/documents?folder=' . urlencode(md5($parentPath === '/' ? '/' : $parentPath))) ?>" 
               class="flex items-center px-2 py-1.5 text-sm rounded hover:bg-gray-50 mb-1 text-gray-600">
                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                <span>Retour</span>
            </a>
            <?php else: ?>
            <a href="<?= url('/documents') ?>" 
               class="flex items-center px-2 py-1.5 text-sm rounded hover:bg-gray-50 mb-1 text-gray-600">
                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                <span>Racine</span>
            </a>
            <?php endif; ?>
            <?php else: ?>
            <a href="<?= url('/documents') ?>" 
               class="flex items-center px-2 py-1.5 text-sm rounded hover:bg-gray-50 mb-1
                      <?= (!$currentFolder && !$logicalFolderId) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
                <span>Tous les documents</span>
            </a>
            <?php endif; ?>
            
            <?php foreach ($fsFolders as $folder): ?>
            <a href="<?= url('/documents?folder=' . urlencode($folder['id'])) ?>" 
               class="flex items-center px-2 py-1.5 text-sm rounded hover:bg-gray-50
                      <?= ($folderId == $folder['id']) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
                <span class="flex-1"><?= htmlspecialchars($folder['name']) ?></span>
                <?php if (($folder['file_count'] ?? 0) > 0): ?>
                <span class="text-xs text-gray-400 ml-2"><?= $folder['file_count'] ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <!-- Types -->
        <?php if (!empty($documentTypes)): ?>
        <div class="px-4 py-3 border-t border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Types</h2>
        </div>
        <nav class="px-2 py-2">
            <a href="<?= url('/documents') ?>" 
               class="flex items-center px-2 py-1.5 text-sm rounded hover:bg-gray-50 mb-0.5
                      <?= (!$typeId) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                Tous
            </a>
            <?php foreach ($documentTypes as $type): ?>
            <a href="<?= url('/documents?type=' . $type['id']) ?>" 
               class="flex items-center px-2 py-1.5 text-sm rounded hover:bg-gray-50 mb-0.5
                      <?= ($typeId == $type['id']) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                <?= htmlspecialchars($type['label']) ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </aside>
    
    <!-- Zone principale - Épurée -->
    <main class="flex-1 flex flex-col overflow-hidden">
        
        <!-- Header sobre -->
        <header class="bg-white border-b border-gray-200 px-6 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <h1 class="text-lg font-semibold text-gray-900">Documents</h1>
                    <?php if ($total > 0): ?>
                    <span class="text-sm text-gray-500"><?= $total ?> document<?= $total > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="flex items-center gap-3">
                    <!-- Recherche simple -->
                    <div class="relative">
                        <input type="text" 
                               id="search-input"
                               value="<?= htmlspecialchars($search ?? '') ?>"
                               placeholder="Rechercher..."
                               class="w-64 px-3 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-gray-400 focus:border-gray-400">
                    </div>
                    
                    <!-- Tri -->
                    <select id="sort-select" class="px-3 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-gray-400">
                        <option value="created_at-desc" <?= ($sort == 'created_at' && $order == 'DESC') ? 'selected' : '' ?>>Date (récent)</option>
                        <option value="created_at-asc" <?= ($sort == 'created_at' && $order == 'ASC') ? 'selected' : '' ?>>Date (ancien)</option>
                        <option value="title-asc" <?= ($sort == 'title' && $order == 'ASC') ? 'selected' : '' ?>>Titre (A-Z)</option>
                        <option value="title-desc" <?= ($sort == 'title' && $order == 'DESC') ? 'selected' : '' ?>>Titre (Z-A)</option>
                    </select>
                    
                    <!-- Vues -->
                    <div class="flex items-center border border-gray-300 rounded">
                        <button onclick="setViewMode('grid')" 
                                class="view-toggle px-2 py-1.5 <?= ($viewMode ?? 'grid') === 'grid' ? 'bg-gray-100' : '' ?>"
                                title="Grille">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                            </svg>
                        </button>
                        <button onclick="setViewMode('list')" 
                                class="view-toggle px-2 py-1.5 border-l border-gray-300 <?= ($viewMode ?? 'grid') === 'list' ? 'bg-gray-100' : '' ?>"
                                title="Liste">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <!-- Upload -->
                    <a href="<?= url('/documents/upload') ?>" 
                       class="px-4 py-1.5 bg-gray-900 text-white text-sm rounded hover:bg-gray-800">
                        Uploader
                    </a>
                </div>
            </div>
        </header>
        
        <!-- Contenu -->
        <div class="flex-1 overflow-y-auto bg-gray-50 p-6">
            <?php if (empty($documents)): ?>
            <div class="flex flex-col items-center justify-center h-full text-center">
                <svg class="w-16 h-16 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h2 class="text-xl font-semibold text-gray-900 mb-2">Aucun document trouvé</h2>
                <p class="text-gray-500 mb-6">L'indexation du filesystem se fait automatiquement.</p>
                <a href="<?= url('/documents/upload') ?>" 
                   class="px-4 py-2 bg-gray-900 text-white rounded hover:bg-gray-800">
                    Uploader un document
                </a>
            </div>
            <?php else: ?>
            <!-- Grille de documents -->
            <div id="view-grid-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-4">
                <?php foreach ($documents as $doc): ?>
                <div class="document-card bg-white border border-gray-200 rounded-lg overflow-hidden hover:border-gray-300 hover:shadow-sm transition-all cursor-pointer"
                     onclick="window.location.href='<?= url('/documents/' . ($doc['id'] ?? 'new')) ?>'">
                    <!-- Thumbnail -->
                    <div class="aspect-[3/4] bg-gray-100 flex items-center justify-center overflow-hidden">
                        <?php if (!empty($doc['thumbnail_path']) && file_exists($doc['thumbnail_path'])): ?>
                        <img src="<?= url('/documents/' . $doc['id'] . '/thumbnail') ?>" 
                             alt="<?= htmlspecialchars($doc['title'] ?? $doc['filename']) ?>"
                             class="w-full h-full object-cover">
                        <?php else: ?>
                        <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Info -->
                    <div class="p-2">
                        <h3 class="text-xs font-medium text-gray-900 truncate mb-1" title="<?= htmlspecialchars($doc['title'] ?? $doc['filename']) ?>">
                            <?= htmlspecialchars($doc['title'] ?? $doc['filename']) ?>
                        </h3>
                        <p class="text-xs text-gray-500">
                            <?= date('d/m/Y', strtotime($doc['created_at'] ?? 'now')) ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="mt-6 flex items-center justify-center gap-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $folderId ? '&folder=' . urlencode($folderId) : '' ?>"
                   class="px-3 py-1.5 text-sm border border-gray-300 rounded hover:bg-gray-50">
                    Précédent
                </a>
                <?php endif; ?>
                
                <span class="px-3 py-1.5 text-sm text-gray-600">
                    Page <?= $page ?> sur <?= $totalPages ?>
                </span>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $folderId ? '&folder=' . urlencode($folderId) : '' ?>"
                   class="px-3 py-1.5 text-sm border border-gray-300 rounded hover:bg-gray-50">
                    Suivant
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
// Recherche
document.getElementById('search-input')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        const search = this.value;
        const url = new URL(window.location.href);
        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }
        url.searchParams.delete('page');
        window.location.href = url.toString();
    }
});

// Tri
document.getElementById('sort-select')?.addEventListener('change', function() {
    const [sort, order] = this.value.split('-');
    const url = new URL(window.location.href);
    url.searchParams.set('sort', sort);
    url.searchParams.set('order', order.toUpperCase());
    window.location.href = url.toString();
});

// Vue
function setViewMode(mode) {
    localStorage.setItem('viewMode', mode);
    // Recharger avec le paramètre
    const url = new URL(window.location.href);
    url.searchParams.set('view', mode);
    window.location.href = url.toString();
}
</script>

<style>
.view-toggle.active {
    background-color: #f3f4f6;
}
</style>
