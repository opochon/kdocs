<?php
// Vue grille style Paperless-ngx - Version sobre et épurée
// $documents, $logicalFolders, $fsFolders, $documentTypes, $tags, $search, $logicalFolderId, $folderId, $typeId, $currentFolder sont passés
use KDocs\Core\Config;
use KDocs\Models\LogicalFolder;
$base = Config::basePath();
?>

<div class="flex min-h-screen bg-white">
    
    <!-- Sidebar gauche - Minimaliste -->
    <aside class="w-48 bg-white border-r border-gray-100 overflow-y-auto">
        <!-- Dossiers logiques -->
        <?php if (!empty($logicalFolders)): ?>
        <div class="px-3 py-2 border-b border-gray-100">
            <h2 class="text-xs font-medium text-gray-400 uppercase tracking-wider">Dossiers logiques</h2>
        </div>
        <nav class="px-1 py-1">
            <?php foreach ($logicalFolders as $lfolder): ?>
            <?php 
            $isActive = ($logicalFolderId == $lfolder['id']);
            $count = LogicalFolder::countDocuments($lfolder['id']);
            ?>
            <a href="<?= url('/documents?logical_folder=' . $lfolder['id']) ?>" 
               class="flex items-center px-2 py-1 text-sm rounded hover:bg-gray-50
                      <?= $isActive ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                <svg class="w-3.5 h-3.5 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
                <span class="flex-1 truncate"><?= htmlspecialchars(preg_replace('/^[📁📄📋📧📦]\s*/u', '', $lfolder['name'])) ?></span>
                <?php if ($count > 0): ?>
                <span class="text-xs text-gray-400 ml-1"><?= $count ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <!-- Dossiers filesystem - Arborescence dynamique -->
        <div class="px-3 py-2 border-t border-gray-100">
            <h2 class="text-xs font-medium text-gray-400 uppercase tracking-wider">Dossiers</h2>
        </div>
        <nav class="px-1 py-1" id="filesystem-tree">
            <!-- Racine -->
            <div class="folder-item" data-folder-id="<?= md5('/') ?>" data-folder-path="/" data-depth="0">
                <div class="flex items-center px-2 py-1 text-sm rounded hover:bg-gray-50 cursor-pointer folder-toggle
                    <?= (!$folderId && !$logicalFolderId) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>"
                    data-folder-id="<?= md5('/') ?>">
                    <span class="folder-expander w-3 h-3 mr-1 flex items-center justify-center">
                        <!-- Flèche sera ajoutée dynamiquement si le dossier a des enfants -->
                    </span>
                    <svg class="w-3.5 h-3.5 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                    </svg>
                    <a href="<?= url('/documents') ?>" class="flex-1 truncate folder-link">Racine</a>
                </div>
                <div class="folder-children hidden"></div>
            </div>
        </nav>
        
        <!-- Types -->
        <?php if (!empty($documentTypes)): ?>
        <div class="px-3 py-2 border-t border-gray-100">
            <h2 class="text-xs font-medium text-gray-400 uppercase tracking-wider">Types</h2>
        </div>
        <nav class="px-1 py-1">
            <a href="<?= url('/documents') ?>" 
               class="block px-2 py-1 text-sm rounded hover:bg-gray-50
                      <?= (!$typeId) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                Tous
            </a>
            <?php foreach ($documentTypes as $type): ?>
            <a href="<?= url('/documents?type=' . $type['id']) ?>" 
               class="block px-2 py-1 text-sm rounded hover:bg-gray-50
                      <?= ($typeId == $type['id']) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>">
                <?= htmlspecialchars($type['label']) ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </aside>
    
    <!-- Zone principale - Épurée -->
    <main class="flex-1 flex flex-col overflow-hidden">
        
        <!-- Header minimaliste -->
        <header class="bg-white border-b border-gray-100 px-4 py-2">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h1 class="text-base font-medium text-gray-900">Documents</h1>
                    <?php if ($total > 0): ?>
                    <span class="text-xs text-gray-400"><?= $total ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="flex items-center gap-2">
                    <!-- Recherche -->
                    <input type="text" 
                           id="search-input"
                           value="<?= htmlspecialchars($search ?? '') ?>"
                           placeholder="Rechercher..."
                           class="w-48 px-2 py-1 text-sm border border-gray-200 rounded focus:outline-none focus:border-gray-300">
                    
                    <!-- Tri -->
                    <select id="sort-select" class="px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:border-gray-300">
                        <option value="created_at-desc" <?= ($sort == 'created_at' && $order == 'DESC') ? 'selected' : '' ?>>Date ↓</option>
                        <option value="created_at-asc" <?= ($sort == 'created_at' && $order == 'ASC') ? 'selected' : '' ?>>Date ↑</option>
                        <option value="title-asc" <?= ($sort == 'title' && $order == 'ASC') ? 'selected' : '' ?>>Titre A-Z</option>
                        <option value="title-desc" <?= ($sort == 'title' && $order == 'DESC') ? 'selected' : '' ?>>Titre Z-A</option>
                    </select>
                    
                    <!-- Vues -->
                    <div class="flex items-center border border-gray-200 rounded">
                        <button onclick="setViewMode('grid')" 
                                class="view-toggle px-1.5 py-1 <?= ($viewMode ?? 'grid') === 'grid' ? 'bg-gray-100' : '' ?>"
                                title="Grille">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                            </svg>
                        </button>
                        <button onclick="setViewMode('list')" 
                                class="view-toggle px-1.5 py-1 border-l border-gray-200 <?= ($viewMode ?? 'grid') === 'list' ? 'bg-gray-100' : '' ?>"
                                title="Liste">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <!-- Upload -->
                    <a href="<?= url('/documents/upload') ?>" 
                       class="px-2.5 py-1 bg-gray-900 text-white text-xs rounded hover:bg-gray-800">
                        Uploader
                    </a>
                </div>
            </div>
        </header>
        
        <!-- Contenu -->
        <div class="flex-1 overflow-y-auto bg-white p-4">
            <?php if (empty($documents)): ?>
            <div class="flex flex-col items-center justify-center h-full text-center py-12">
                <svg class="w-12 h-12 text-gray-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h2 class="text-base font-medium text-gray-700 mb-1">Aucun document</h2>
                <p class="text-sm text-gray-400 mb-4">L'indexation se fait automatiquement.</p>
                <a href="<?= url('/documents/upload') ?>" 
                   class="px-3 py-1.5 bg-gray-900 text-white text-sm rounded hover:bg-gray-800">
                    Uploader
                </a>
            </div>
            <?php else: ?>
            <!-- Grille de documents -->
            <div id="view-grid-container" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8 gap-2">
                <?php foreach ($documents as $doc): ?>
                <a href="<?= url('/documents/' . ($doc['id'] ?? 'new')) ?>" 
                   class="document-card bg-white border border-gray-100 rounded hover:border-gray-200 hover:shadow-sm transition-all block">
                    <!-- Thumbnail -->
                    <div class="aspect-[3/4] bg-gray-50 flex items-center justify-center overflow-hidden relative">
                        <?php if (!empty($doc['id'])): ?>
                        <img src="<?= url('/documents/' . $doc['id'] . '/thumbnail') ?>" 
                             alt="<?= htmlspecialchars($doc['title'] ?? $doc['filename']) ?>"
                             class="w-full h-full object-cover"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <?php endif; ?>
                        <div class="w-full h-full flex items-center justify-center <?= !empty($doc['id']) ? 'hidden' : '' ?>">
                            <svg class="w-8 h-8 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <!-- Info -->
                    <div class="p-1.5 border-t border-gray-50">
                        <h3 class="text-xs font-medium text-gray-900 truncate mb-0.5" title="<?= htmlspecialchars($doc['title'] ?? $doc['filename']) ?>">
                            <?= htmlspecialchars($doc['title'] ?? $doc['filename']) ?>
                        </h3>
                        <p class="text-xs text-gray-400">
                            <?= date('d/m/Y', strtotime($doc['created_at'] ?? 'now')) ?>
                        </p>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="mt-4 flex items-center justify-center gap-2">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $folderId ? '&folder=' . urlencode($folderId) : '' ?>"
                   class="px-2 py-1 text-xs border border-gray-200 rounded hover:bg-gray-50 text-gray-600">
                    ←
                </a>
                <?php endif; ?>
                
                <span class="px-2 py-1 text-xs text-gray-400">
                    <?= $page ?>/<?= $totalPages ?>
                </span>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $folderId ? '&folder=' . urlencode($folderId) : '' ?>"
                   class="px-2 py-1 text-xs border border-gray-200 rounded hover:bg-gray-50 text-gray-600">
                    →
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

// Arborescence filesystem dynamique
(function() {
    const treeContainer = document.getElementById('filesystem-tree');
    if (!treeContainer) return;
    
    const currentFolderId = '<?= $folderId ?? "" ?>';
    const expandedFolders = new Set();
    
    // Charger les sous-dossiers d'un dossier parent
    async function loadChildren(parentId, parentElement) {
        if (expandedFolders.has(parentId)) {
            return; // Déjà chargé
        }
        
        const childrenContainer = parentElement.querySelector('.folder-children');
        if (!childrenContainer) return;
        
        // Afficher un indicateur de chargement
        childrenContainer.innerHTML = '<div class="px-2 py-1 text-xs text-gray-400">Chargement...</div>';
        childrenContainer.classList.remove('hidden');
        
        try {
            const apiUrl = `<?= url('/api/folders/children') ?>?parent_id=${encodeURIComponent(parentId)}`;
            const response = await fetch(apiUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success && data.folders.length > 0) {
                childrenContainer.innerHTML = '';
                data.folders.forEach(folder => {
                    const folderItem = createFolderItem(folder, parseInt(parentElement.dataset.depth) + 1);
                    childrenContainer.appendChild(folderItem);
                });
            } else {
                childrenContainer.innerHTML = '';
            }
            
            expandedFolders.add(parentId);
        } catch (error) {
            console.error('Erreur chargement sous-dossiers:', error);
            childrenContainer.innerHTML = '<div class="px-2 py-1 text-xs text-red-400">Erreur</div>';
        }
    }
    
    // Créer un élément de dossier
    function createFolderItem(folder, depth) {
        const item = document.createElement('div');
        item.className = 'folder-item';
        item.dataset.folderId = folder.id;
        item.dataset.folderPath = folder.path;
        item.dataset.depth = depth;
        
        const isActive = currentFolderId === folder.id;
        const hasChildren = folder.has_children;
        const indent = depth * 16; // 16px par niveau
        
        item.innerHTML = `
            <div class="flex items-center px-2 py-1 text-sm rounded hover:bg-gray-50 cursor-pointer folder-toggle ${isActive ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600'}"
                 data-folder-id="${folder.id}">
                <span class="folder-expander w-3 h-3 mr-1 flex items-center justify-center">
                    ${hasChildren ? `
                    <svg class="w-2.5 h-2.5 text-gray-400 folder-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    ` : ''}
                </span>
                <svg class="w-3.5 h-3.5 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
                <a href="<?= url('/documents?folder=') ?>${encodeURIComponent(folder.id)}" class="flex-1 truncate folder-link">${escapeHtml(folder.name)}</a>
                ${folder.file_count > 0 ? `<span class="text-xs text-gray-400 ml-1">${folder.file_count}</span>` : ''}
            </div>
            <div class="folder-children hidden" style="margin-left: ${indent}px;"></div>
        `;
        
        return item;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Gérer le clic sur un dossier pour développer/réduire
    treeContainer.addEventListener('click', async function(e) {
        const toggle = e.target.closest('.folder-toggle');
        if (!toggle) return;
        
        e.preventDefault();
        e.stopPropagation();
        
        const folderId = toggle.dataset.folderId;
        const folderItem = toggle.closest('.folder-item');
        const childrenContainer = folderItem.querySelector('.folder-children');
        const arrow = toggle.querySelector('.folder-arrow');
        
        if (!childrenContainer) return;
        
        if (childrenContainer.classList.contains('hidden')) {
            // Développer
            await loadChildren(folderId, folderItem);
            if (arrow) {
                arrow.style.transform = 'rotate(90deg)';
            }
        } else {
            // Réduire
            childrenContainer.classList.add('hidden');
            if (arrow) {
                arrow.style.transform = 'rotate(0deg)';
            }
            expandedFolders.delete(folderId);
        }
    });
    
    // Empêcher la navigation si on clique sur le toggle
    treeContainer.addEventListener('click', function(e) {
        if (e.target.closest('.folder-expander') || e.target.closest('.folder-toggle')) {
            const link = e.target.closest('.folder-toggle')?.querySelector('.folder-link');
            if (link && e.target !== link && !link.contains(e.target)) {
                e.preventDefault();
            }
        }
    });
    
    // Charger les dossiers de la racine au chargement
    const rootItem = treeContainer.querySelector('[data-folder-id="<?= md5('/') ?>"]');
    if (rootItem) {
        const rootId = rootItem.dataset.folderId;
        console.log('Chargement des dossiers racine, ID:', rootId);
        loadChildren(rootId, rootItem).then(() => {
            const childrenContainer = rootItem.querySelector('.folder-children');
            const arrow = rootItem.querySelector('.folder-arrow');
            if (childrenContainer && !childrenContainer.classList.contains('hidden')) {
                if (arrow) {
                    arrow.style.transform = 'rotate(90deg)';
                }
            }
            
            // Si un dossier est sélectionné, développer son chemin
            if (currentFolderId) {
                const currentItem = treeContainer.querySelector(`[data-folder-id="${currentFolderId}"]`);
                if (currentItem) {
                    let parent = currentItem.closest('.folder-item');
                    const path = [];
                    while (parent) {
                        path.unshift(parent);
                        parent = parent.parentElement.closest('.folder-item');
                    }
                    
                    // Développer tous les parents
                    path.forEach(async (item) => {
                        const folderId = item.dataset.folderId;
                        const childrenContainer = item.querySelector('.folder-children');
                        const toggle = item.querySelector('.folder-toggle');
                        const arrow = toggle?.querySelector('.folder-arrow');
                        
                        if (childrenContainer && childrenContainer.classList.contains('hidden')) {
                            await loadChildren(folderId, item);
                            if (arrow) {
                                arrow.style.transform = 'rotate(90deg)';
                            }
                        }
                    });
                }
            }
        });
    }
})();
</script>

<style>
.view-toggle.active {
    background-color: #f3f4f6;
}

.folder-expander {
    transition: transform 0.2s;
}

.folder-arrow {
    transition: transform 0.2s;
}

.folder-children {
    transition: all 0.2s;
}

.folder-item {
    user-select: none;
}
</style>
