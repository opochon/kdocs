<?php
// Vue grille style Paperless-ngx - Version sobre et épurée
// $documents, $logicalFolders, $fsFolders, $documentTypes, $tags, $search, $logicalFolderId, $folderId, $typeId, $currentFolder sont passés
use KDocs\Core\Config;
use KDocs\Models\LogicalFolder;
$base = Config::basePath();
?>

<div class="flex min-h-screen bg-white w-full overflow-hidden">
    
    <!-- Sidebar gauche - Minimaliste -->
    <aside id="documents-sidebar" class="bg-white border-r border-gray-100 overflow-y-auto overflow-x-auto flex-shrink-0 relative" style="max-height: 100vh; min-width: 200px; width: 256px;">
        <!-- Poignée de redimensionnement -->
        <div id="sidebar-resize-handle" class="absolute right-0 top-0 bottom-0 w-1 cursor-col-resize hover:bg-gray-300 bg-transparent transition-colors z-10" style="margin-right: -2px;"></div>
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
        
        <!-- Dossiers filesystem - Rendu côté serveur (RAPIDE) -->
        <div class="px-3 py-2 border-t border-gray-100">
            <h2 class="text-xs font-medium text-gray-400 uppercase tracking-wider">Dossiers</h2>
        </div>
        <?= $folderTreeHtml ?? '' ?>
        
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
    <main class="flex-1 flex flex-col overflow-hidden min-w-0">
        
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
        <div class="flex-1 overflow-y-auto bg-white p-4" style="min-width: 0; width: 100%;">
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
            <!-- Grille de documents - Utilise toute la largeur disponible -->
            <div id="view-grid-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-3 w-full max-w-none">
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
                <?php 
                $paginationParams = [];
                if ($search) $paginationParams[] = 'search=' . urlencode($search);
                if ($folderId) {
                    $paginationParams[] = 'folder=' . urlencode($folderId);
                    if (isset($currentFolder) && $currentFolder !== null) {
                        $paginationParams[] = 'path=' . urlencode($currentFolder);
                    }
                }
                $paginationQuery = $paginationParams ? '&' . implode('&', $paginationParams) : '';
                ?>
                
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $paginationQuery ?>"
                   class="px-2 py-1 text-xs border border-gray-200 rounded hover:bg-gray-50 text-gray-600">
                    ←
                </a>
                <?php endif; ?>
                
                <span class="px-2 py-1 text-xs text-gray-400">
                    <?= $page ?>/<?= $totalPages ?>
                </span>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $paginationQuery ?>"
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

<!-- Indicateur de progression des indexations -->
<div id="indexing-status-bar" class="fixed bottom-0 left-0 right-0 bg-gradient-to-r from-orange-50 to-orange-100 border-t-2 border-orange-400 shadow-lg px-4 py-3 z-50" style="display: none;">
    <div class="w-full px-4">
        <div class="flex items-center justify-between mb-2">
            <span class="font-semibold text-gray-800 text-sm">Indexation en cours</span>
            <button onclick="document.getElementById('indexing-status-bar').style.display='none'" class="text-gray-500 hover:text-gray-700 text-lg leading-none" title="Masquer">×</button>
        </div>
        <div id="indexing-progress-list" class="space-y-2">
            <!-- Les progressions seront ajoutées ici dynamiquement -->
        </div>
    </div>
</div>

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
.progress-wheel {
    animation: spin 1s linear infinite;
    transform-origin: center;
}
</style>

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

// Arborescence - Version simplifiée
// Tout est rendu côté serveur, le JS gère juste le toggle
(function() {
    const tree = document.getElementById('filesystem-tree');
    if (!tree) return;
    
    tree.addEventListener('click', function(e) {
        // Clic sur l'expander (flèche)
        const expander = e.target.closest('.folder-expander');
        if (expander && expander.querySelector('.folder-arrow')) {
            e.preventDefault();
            e.stopPropagation();
            
            const folderItem = expander.closest('.folder-item');
            const children = folderItem.querySelector('.folder-children');
            const arrow = expander.querySelector('.folder-arrow');
            
            if (children) {
                const isHidden = children.style.display === 'none';
                children.style.display = isHidden ? 'block' : 'none';
                arrow.style.transform = isHidden ? 'rotate(90deg)' : 'rotate(0deg)';
            }
            return;
        }
        
        // Clic sur le lien = navigation normale (pas d'interception)
    });
})();

// Redimensionnement de la sidebar
(function() {
    const sidebar = document.getElementById('documents-sidebar');
    const resizeHandle = document.getElementById('sidebar-resize-handle');
    
    if (!sidebar || !resizeHandle) return;
    
    // Charger la largeur sauvegardée depuis localStorage
    const savedWidth = localStorage.getItem('documents-sidebar-width');
    if (savedWidth) {
        const width = parseInt(savedWidth);
        if (width >= 200) { // Respecter le min-width
            sidebar.style.width = width + 'px';
        }
    }
    
    let isResizing = false;
    let startX = 0;
    let startWidth = 0;
    
    resizeHandle.addEventListener('mousedown', function(e) {
        isResizing = true;
        startX = e.clientX;
        startWidth = sidebar.offsetWidth;
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isResizing) return;
        
        const diff = e.clientX - startX;
        const newWidth = Math.max(200, startWidth + diff); // Minimum 200px
        
        sidebar.style.width = newWidth + 'px';
    });
    
    document.addEventListener('mouseup', function() {
        if (isResizing) {
            isResizing = false;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            
            // Sauvegarder la largeur dans localStorage
            localStorage.setItem('documents-sidebar-width', sidebar.offsetWidth.toString());
        }
    });
})();
</script>

<style>
/* Assurer que le body et html utilisent toute la largeur */
html, body {
    width: 100%;
    margin: 0;
    padding: 0;
    overflow-x: hidden;
}

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
    /* Pas de margin-left, l'indentation est gérée par padding-left sur le parent */
}

.folder-item {
    user-select: none;
    min-width: fit-content; /* Permet le scroll horizontal si nécessaire */
}

/* Améliorer la navigation dans l'arborescence */
#filesystem-tree {
    min-width: 100%;
    width: max-content; /* Permet le scroll horizontal si nécessaire */
    max-width: 100%; /* Ne pas dépasser la largeur de la sidebar */
}

.folder-toggle {
    white-space: nowrap; /* Empêche le retour à la ligne */
    width: 100%;
    max-width: 100%;
}

.folder-link {
    min-width: 0; /* Permet le truncate de fonctionner */
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

/* Améliorer le scroll dans la sidebar */
aside {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e0 #f7fafc;
}

aside::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

aside::-webkit-scrollbar-track {
    background: #f7fafc;
}

aside::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 4px;
}

aside::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}
</style>
