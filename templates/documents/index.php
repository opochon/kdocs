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
        
        <!-- Dossiers filesystem - Arborescence dynamique -->
        <div class="px-3 py-2 border-t border-gray-100">
            <h2 class="text-xs font-medium text-gray-400 uppercase tracking-wider">Dossiers</h2>
        </div>
        <nav class="px-1 py-1 overflow-x-auto" id="filesystem-tree" style="min-width: 100%; width: max-content;">
            <!-- Racine -->
            <div class="folder-item" data-folder-id="<?= md5('/') ?>" data-folder-path="/" data-depth="0">
                <div class="flex items-center px-2 py-1 text-sm rounded hover:bg-gray-50 cursor-pointer folder-toggle
                    <?= (!$folderId && !$logicalFolderId) ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>"
                    data-folder-id="<?= md5('/') ?>"
                    style="padding-left: 12px;">
                    <span class="folder-expander w-3 h-3 mr-1 flex items-center justify-center flex-shrink-0">
                        <?php if (!empty($rootFolders)): ?>
                        <svg class="w-2.5 h-2.5 text-gray-400 folder-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                        <?php endif; ?>
                    </span>
                    <svg class="w-3.5 h-3.5 mr-1.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                    </svg>
                    <a href="<?= url('/documents') ?>" class="flex-1 truncate folder-link min-w-0">Racine</a>
                </div>
                <div class="folder-children <?= !empty($rootFolders) ? '' : 'hidden' ?>">
                    <?php if (!empty($rootFolders)): ?>
                        <?php foreach ($rootFolders as $folder): ?>
                            <?php
                            $isActive = ($folderId === $folder['id']);
                            $indent = 12; // Premier niveau après racine
                            ?>
                            <div class="folder-item" data-folder-id="<?= htmlspecialchars($folder['id']) ?>" data-folder-path="<?= htmlspecialchars($folder['path']) ?>" data-depth="1">
                                <div class="flex items-center px-2 py-1 text-sm rounded hover:bg-gray-50 cursor-pointer folder-toggle <?= $isActive ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600' ?>"
                                     data-folder-id="<?= htmlspecialchars($folder['id']) ?>"
                                     style="padding-left: <?= 12 + $indent ?>px; white-space: nowrap;">
                                    <span class="folder-expander w-3 h-3 mr-1 flex items-center justify-center flex-shrink-0">
                                        <?php if ($folder['has_children']): ?>
                                        <svg class="w-2.5 h-2.5 text-gray-400 folder-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                        <?php else: ?>
                                        <span class="w-2.5"></span>
                                        <?php endif; ?>
                                    </span>
                                    <svg class="w-3.5 h-3.5 mr-1.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                                    </svg>
                                    <a href="<?= url('/documents?folder=' . urlencode($folder['id'])) ?>" class="flex-1 truncate folder-link min-w-0"><?= htmlspecialchars($folder['name']) ?></a>
                                    <span class="folder-count text-xs ml-1 flex-shrink-0">
                                        <?php if ($folder['file_count'] > 0): ?>
                                            <span class="text-xs text-gray-400"><?= $folder['file_count'] ?></span>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">-</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="folder-children hidden"></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
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

// Arborescence filesystem dynamique - Version simplifiée
(function() {
    const treeContainer = document.getElementById('filesystem-tree');
    if (!treeContainer) return;
    
    const currentFolderId = '<?= $folderId ?? "" ?>';
    const expandedFolders = new Set();
    const loadingFolders = new Set(); // Suivi des dossiers en cours de chargement
    const loadControllers = new Map(); // AbortController par dossier
    
    // Les dossiers racine sont déjà chargés côté serveur, pas besoin de les recharger
    
    // Fonction simplifiée pour charger les dossiers enfants
    async function loadChildren(parentId, parentElement) {
        const childrenContainer = parentElement.querySelector('.folder-children');
        if (!childrenContainer) return;
        
        // Si déjà chargé, ne pas recharger
        if (expandedFolders.has(parentId)) {
            return;
        }
        
        // Si déjà en cours de chargement, annuler la requête précédente
        if (loadingFolders.has(parentId)) {
            const controller = loadControllers.get(parentId);
            if (controller) {
                controller.abort();
            }
        }
        
        // Créer un nouveau AbortController pour cette requête
        const controller = new AbortController();
        loadControllers.set(parentId, controller);
        loadingFolders.add(parentId);
        
        // Afficher le message de chargement seulement si le conteneur est vide
        if (childrenContainer.innerHTML.trim() === '' || childrenContainer.innerHTML.includes('<!-- Les enfants seront chargés dynamiquement')) {
            childrenContainer.innerHTML = '<div class="px-2 py-1 text-xs text-gray-400">Chargement...</div>';
        }
        childrenContainer.classList.remove('hidden');
        
        try {
            const response = await fetch(`<?= url('/api/folders/children') ?>?parent_id=${encodeURIComponent(parentId)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
                cache: 'default' // Utiliser le cache pour améliorer les performances
            });
            
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Erreur');
            
            childrenContainer.innerHTML = '';
            if (data.folders && data.folders.length > 0) {
                data.folders.forEach(folder => {
                    const item = createFolderItem(folder, parseInt(parentElement.dataset.depth || 0) + 1);
                    childrenContainer.appendChild(item);
                    // Le comptage DB sera chargé de manière asynchrone via la file d'attente
                });
                // Ne pas cacher le conteneur, il est déjà visible
            } else {
                childrenContainer.classList.add('hidden');
            }
            
            expandedFolders.add(parentId);
            loadingFolders.delete(parentId);
        } catch (error) {
            if (error.name === 'AbortError') {
                // Requête annulée, ne rien afficher
                return;
            }
            console.error('Erreur chargement dossiers:', error);
            const retryButtonId = 'retry-folder-' + parentId.replace(/[^a-zA-Z0-9]/g, '-');
            childrenContainer.innerHTML = `
                <div class="px-2 py-1 text-xs text-red-500 flex items-center justify-between">
                    <span>Erreur de chargement</span>
                    <button id="${retryButtonId}" class="ml-2 text-blue-600 hover:text-blue-800" title="Réessayer">↻</button>
                </div>
            `;
            // Attacher le gestionnaire d'événement
            setTimeout(() => {
                const retryBtn = document.getElementById(retryButtonId);
                if (retryBtn) {
                    retryBtn.addEventListener('click', () => {
                        expandedFolders.delete(parentId);
                        loadingFolders.delete(parentId);
                        loadChildren(parentId, parentElement);
                    });
                }
            }, 10);
            expandedFolders.delete(parentId);
            
            // Masquer automatiquement le message d'erreur après 5 secondes
            setTimeout(() => {
                if (childrenContainer.innerHTML.includes('Erreur de chargement')) {
                    childrenContainer.classList.add('hidden');
                    childrenContainer.innerHTML = '';
                }
            }, 5000);
        }
    }
    
    // File d'attente pour charger les comptages DB de manière progressive
    const folderCountQueue = [];
    let isProcessingCountQueue = false;
    const COUNT_BATCH_SIZE = 20; // Traiter 20 dossiers à la fois (augmenté pour plus de rapidité)
    const COUNT_BATCH_DELAY = 50; // Délai réduit à 50ms entre chaque batch
    
    // Ajouter un dossier à la file d'attente pour le comptage DB
    // Limiter la taille de la queue pour éviter la saturation
    const MAX_QUEUE_SIZE = 100;
    function queueFolderCount(folderPath, folderId, folderElement) {
        // Éviter les doublons
        const exists = folderCountQueue.some(item => item.folderPath === folderPath);
        if (exists) {
            return;
        }
        
        // Limiter la taille de la queue
        if (folderCountQueue.length >= MAX_QUEUE_SIZE) {
            console.warn('File d\'attente des comptages saturée, ignoré:', folderPath);
            return;
        }
        
        folderCountQueue.push({ folderPath, folderId, folderElement });
        // Ne pas appeler immédiatement, laisser un délai
        setTimeout(() => processFolderCountQueue(), 100); // Réduire le délai initial
    }
    
    // Traiter la file d'attente des comptages DB de manière progressive
    async function processFolderCountQueue() {
        if (isProcessingCountQueue || folderCountQueue.length === 0) {
            return;
        }
        
        isProcessingCountQueue = true;
        let consecutiveErrors = 0;
        const MAX_CONSECUTIVE_ERRORS = 3;
        
        while (folderCountQueue.length > 0) {
            // Prendre un batch de dossiers (réduire la taille du batch)
            const batch = folderCountQueue.splice(0, COUNT_BATCH_SIZE); // Utiliser la taille complète du batch
            const paths = batch.map(item => item.folderPath);
            
            try {
                // Charger les comptages DB pour ce batch avec timeout
                const response = await fetch(`<?= url('/api/folders/counts') ?>`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ paths }),
                    signal: AbortSignal.timeout(10000) // Timeout de 10 secondes
                });
                
                if (response.ok) {
                    consecutiveErrors = 0; // Réinitialiser en cas de succès
                    const data = await response.json();
                    if (data.success && data.counts) {
                        // Mettre à jour chaque dossier du batch
                        batch.forEach(({ folderPath, folderId, folderElement }) => {
                            const dbCount = data.counts[folderPath] ?? 0;
                            const countSpan = folderElement?.querySelector('.folder-count');
                            if (countSpan) {
                                const physicalCount = parseInt(countSpan.textContent) || 0;
                                updateFolderCountDisplay(countSpan, physicalCount, dbCount, folderPath, folderId, folderElement);
                            }
                        });
                    }
                } else {
                    consecutiveErrors++;
                    if (consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
                        console.warn('Trop d\'erreurs lors du chargement des comptages, arrêt temporaire');
                        isProcessingCountQueue = false;
                        return;
                    }
                }
            } catch (error) {
                if (error.name !== 'AbortError') {
                    consecutiveErrors++;
                    if (consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
                        console.warn('Trop d\'erreurs lors du chargement des comptages, arrêt temporaire');
                        isProcessingCountQueue = false;
                        return;
                    }
                }
            }
            
            // Attendre avant le prochain batch pour ne pas surcharger le serveur (augmenter le délai)
            if (folderCountQueue.length > 0) {
                await new Promise(resolve => setTimeout(resolve, COUNT_BATCH_DELAY * 2)); // Doubler le délai
            }
        }
        
        isProcessingCountQueue = false;
    }
    
    // Mettre à jour l'affichage du compteur avec détection de désynchronisation
    function updateFolderCountDisplay(countSpan, physicalCount, dbCount, folderPath, folderId = null, folderElement = null) {
        if (physicalCount > 0) {
            // Si dbCount est null, on attend encore le chargement
            if (dbCount === null || dbCount === undefined) {
                countSpan.innerHTML = `<span class="text-xs text-gray-400">${physicalCount}</span> <span class="text-xs text-gray-300">...</span>`;
                return;
            }
            
            if (physicalCount > dbCount) {
                // Désynchronisation détectée : lancer l'indexation si pas déjà en cours
                if (!crawlingFolders.has(folderPath)) {
                    countSpan.innerHTML = `<span class="text-orange-600 font-medium" title="Physique: ${physicalCount}, DB: ${dbCount}">${physicalCount}</span> <span class="text-orange-500" title="Indexation en cours">🔄</span>`;
                    // Déclencher le crawl avec un délai pour éviter de surcharger
                    setTimeout(() => {
                        if (folderId && folderElement) {
                            triggerCrawl(folderPath, folderId, folderElement);
                        } else {
                            // Fallback : trouver folderId et folderElement depuis countSpan
                            const toggle = countSpan.closest('.folder-toggle');
                            if (toggle) {
                                const item = toggle.closest('.folder-item');
                                if (item) {
                                    const id = item.dataset.folderId;
                                    triggerCrawl(folderPath, id, item);
                                }
                            }
                        }
                    }, Math.random() * 1000); // Délai aléatoire entre 0 et 1 seconde pour éviter les pics
                } else {
                    // Crawl déjà en cours, juste mettre à jour l'affichage
                    countSpan.innerHTML = `<span class="text-orange-600 font-medium" title="Physique: ${physicalCount}, DB: ${dbCount}">${physicalCount}</span> <span class="text-orange-500" title="Indexation en cours">🔄</span>`;
                }
            } else {
                // Synchronisé : retirer l'indicateur de crawl
                countSpan.innerHTML = `<span class="text-xs text-gray-400">${physicalCount}</span>`;
                crawlingFolders.delete(folderPath);
            }
        } else {
            countSpan.innerHTML = `<span class="text-xs text-gray-400">-</span>`;
        }
    }
    
    // Suivi des indexations en cours (via fichiers .indexing et .indexed)
    const indexingStatuses = new Map(); // folderPath -> { status, data }
    let indexingStatusInterval = null;
    let indexingStatusController = null; // AbortController pour annuler les requêtes précédentes
    
    // Mettre à jour le statut des indexations depuis les fichiers
    async function updateIndexingStatus() {
        // Annuler la requête précédente si elle existe encore (mais seulement si elle est vraiment en cours)
        if (indexingStatusController && !indexingStatusController.signal.aborted) {
            indexingStatusController.abort();
        }
        
        // Créer un nouveau AbortController pour cette requête
        indexingStatusController = new AbortController();
        const timeoutId = setTimeout(() => {
            if (!indexingStatusController.signal.aborted) {
                indexingStatusController.abort();
            }
        }, 5000); // Timeout de 5 secondes au lieu de 3
        
        try {
            // Récupérer la liste des queues en cours
            const queueResponse = await fetch(`<?= url('/api/folders/crawl-status') ?>`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: indexingStatusController.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!queueResponse.ok) return;
            
            const queueData = await queueResponse.json();
            if (!queueData.success) return;
            
            const queues = queueData.queues || [];
            const progressList = document.getElementById('indexing-progress-list');
            const statusBar = document.getElementById('indexing-status-bar');
            
            if (!progressList || !statusBar) return;
            
            // Limiter le nombre de requêtes simultanées pour éviter la surcharge
            const MAX_CONCURRENT_REQUESTS = 3;
            const activeIndexings = [];
            
            // Traiter les queues par batch pour éviter trop de requêtes simultanées
            for (let i = 0; i < queues.length; i += MAX_CONCURRENT_REQUESTS) {
                const batch = queues.slice(i, i + MAX_CONCURRENT_REQUESTS);
                const statusPromises = batch.map(async (queue) => {
                    try {
                        const response = await fetch(`<?= url('/api/folders/indexing-status') ?>?path=${encodeURIComponent(queue.path)}`, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            signal: indexingStatusController.signal,
                            cache: 'no-cache' // Éviter le cache pour avoir les données à jour
                        });
                        
                        if (!response.ok) return null;
                        
                        const data = await response.json();
                        if (data.success && data.status === 'indexing' && data.data) {
                            return { path: queue.path, ...data.data };
                        }
                        return null;
                    } catch (error) {
                        // Ignorer les erreurs d'annulation
                        if (error.name !== 'AbortError') {
                            console.warn('Erreur récupération statut indexation:', error);
                        }
                        return null;
                    }
                });
                
                const batchResults = await Promise.all(statusPromises);
                activeIndexings.push(...batchResults.filter(s => s !== null));
            }
            
            if (activeIndexings.length > 0) {
                progressList.innerHTML = activeIndexings.map(status => {
                    const progress = status.total > 0 ? Math.round((status.current / status.total) * 100) : 0;
                    const pathDisplay = status.path || 'Racine';
                    
                    return `
                        <div class="bg-white rounded-lg border border-orange-200 p-3 shadow-sm">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium text-gray-800 text-sm">${escapeHtml(pathDisplay)}</span>
                                <span class="text-xs text-gray-500">${status.current} / ${status.total}</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2 mb-1">
                                <div class="bg-orange-500 h-2 rounded-full transition-all duration-300" style="width: ${progress}%"></div>
                            </div>
                            <div class="flex items-center justify-between text-xs text-gray-600">
                                <span>${progress}%</span>
                                <div class="flex items-center gap-1">
                                    <svg class="w-4 h-4 progress-wheel text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                    </svg>
                                    <span>En cours...</span>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
                statusBar.style.display = 'block';
            } else {
                statusBar.style.display = 'none';
            }
        } catch (error) {
            // Ignorer les erreurs silencieusement
        }
    }
    
    // Mettre à jour le statut toutes les 5 secondes (réduire la fréquence pour éviter les requêtes annulées)
    // Ne démarrer le polling que s'il y a des queues actives
    let lastQueueCount = 0;
    indexingStatusInterval = setInterval(() => {
        // Vérifier s'il y a des queues avant de faire la requête
        updateIndexingStatus().then(() => {
            const statusBar = document.getElementById('indexing-status-bar');
            const hasActiveIndexing = statusBar && statusBar.style.display !== 'none';
            
            // Si plus d'indexation active, arrêter le polling après quelques vérifications
            if (!hasActiveIndexing) {
                lastQueueCount++;
                if (lastQueueCount > 3) {
                    // Arrêter le polling si aucune indexation active depuis 15 secondes
                    if (indexingStatusInterval) {
                        clearInterval(indexingStatusInterval);
                        indexingStatusInterval = null;
                    }
                }
            } else {
                lastQueueCount = 0;
            }
        });
    }, 5000); // Polling toutes les 5 secondes au lieu de 3
    
    // Démarrer immédiatement
    setTimeout(updateIndexingStatus, 1000);
    
    // Déclencher le crawl d'un dossier (le worker s'occupe du reste)
    async function triggerCrawl(folderPath, folderId, folderElement) {
        try {
            // Vérifier si un crawl est déjà en cours pour ce dossier
            if (crawlingFolders.has(folderPath)) {
                return; // Déjà en cours
            }
            
            // Enregistrer le crawl en cours
            crawlingFolders.set(folderPath, {
                folderId: folderId,
                folderElement: folderElement,
                startTime: Date.now()
            });
            
            // Déclencher le crawl (le worker créera le fichier .indexing)
            await fetch(`<?= url('/api/folders/crawl') ?>`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ path: folderPath }),
                signal: AbortSignal.timeout(5000)
            });
            
            // Le statut sera mis à jour automatiquement par updateIndexingStatus()
            // Mettre à jour le compteur immédiatement pour afficher l'indicateur
            const countSpan = folderElement?.querySelector('.folder-count');
            if (countSpan) {
                const physicalCount = parseInt(countSpan.textContent) || 0;
                countSpan.innerHTML = `<span class="text-orange-600 font-medium">${physicalCount}</span> <span class="text-orange-500">🔄</span>`;
            }
        } catch (error) {
            console.error('Erreur déclenchement crawl:', error);
            crawlingFolders.delete(folderPath);
        }
    }
    
    // Vérifier le statut d'indexation et mettre à jour le compteur
    async function checkIndexingAndUpdateCount(folderPath, folderId, folderElement) {
        try {
            const response = await fetch(`<?= url('/api/folders/indexing-status') ?>?path=${encodeURIComponent(folderPath)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: AbortSignal.timeout(3000)
            });
            
            if (!response.ok) return;
            
            const data = await response.json();
            if (!data.success) return;
            
            const countSpan = folderElement?.querySelector('.folder-count');
            if (!countSpan) return;
            
            if (data.status === 'indexed' && data.data) {
                // Indexation terminée : mettre à jour avec les données du fichier .indexed
                const physicalCount = data.data.file_count || 0;
                const dbCount = data.data.db_count || 0;
                
                if (physicalCount <= dbCount) {
                    countSpan.innerHTML = `<span class="text-xs text-gray-400">${physicalCount}</span>`;
                    crawlingFolders.delete(folderPath);
                } else {
                    countSpan.innerHTML = `<span class="text-orange-600 font-medium" title="Physique: ${physicalCount}, DB: ${dbCount}">${physicalCount}</span> <span class="text-orange-500">🔄</span>`;
                }
            } else if (data.status === 'indexing' && data.data) {
                // Indexation en cours : afficher l'indicateur
                const physicalCount = data.data.total || 0;
                countSpan.innerHTML = `<span class="text-orange-600 font-medium">${physicalCount}</span> <span class="text-orange-500">🔄</span>`;
            }
        } catch (error) {
            // Ignorer les erreurs silencieusement
        }
    }
    
    // Contrôleur pour les requêtes de chargement de contenu
    let contentController = null;
    
    // Charger le contenu d'un dossier (documents) immédiatement
    async function loadFolderContent(folderId) {
        // Annuler la requête précédente si elle existe encore
        if (contentController) {
            contentController.abort();
        }
        
        // Créer un nouveau AbortController pour cette requête
        contentController = new AbortController();
        const timeoutId = setTimeout(() => contentController.abort(), 10000); // Timeout de 10 secondes
        
        try {
            window.location.href = `<?= url('/documents?folder=') ?>${encodeURIComponent(folderId)}`;
        } catch (error) {
            console.error('Erreur chargement contenu:', error);
        }
    }
    
    // Créer un élément de dossier (pour chargement dynamique)
    function createFolderItem(folder, depth) {
        const item = document.createElement('div');
        item.className = 'folder-item';
        item.dataset.folderId = folder.id;
        item.dataset.folderPath = folder.path;
        item.dataset.depth = depth;
        
        const isActive = currentFolderId === folder.id;
        const hasChildren = folder.has_children || (folder.children && folder.children.length > 0);
        const indent = Math.min(depth * 12, 120); // 12px par niveau, max 120px
        
        // Compteur initial (sera mis à jour par AJAX avec détection désynchronisation)
        const physicalCount = folder.file_count || 0;
        const dbCount = folder.db_file_count || 0;
        const initialCountHtml = physicalCount > 0 ? 
            `<span class="text-xs text-gray-400 ml-1">${physicalCount}</span>` : 
            '<span class="text-xs text-gray-400 ml-1">-</span>';
        
        item.innerHTML = `
            <div class="flex items-center px-2 py-1 text-sm rounded hover:bg-gray-50 cursor-pointer folder-toggle ${isActive ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600'}"
                 data-folder-id="${folder.id}"
                 style="padding-left: ${12 + indent}px; white-space: nowrap;">
                <span class="folder-expander w-3 h-3 mr-1 flex items-center justify-center flex-shrink-0">
                    ${hasChildren ? `
                    <svg class="w-2.5 h-2.5 text-gray-400 folder-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    ` : '<span class="w-2.5"></span>'}
                </span>
                <svg class="w-3.5 h-3.5 mr-1.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>
                </svg>
                <a href="<?= url('/documents?folder=') ?>${encodeURIComponent(folder.id)}" class="flex-1 truncate folder-link min-w-0">${escapeHtml(folder.name)}</a>
                <span class="folder-count text-xs ml-1 flex-shrink-0">${initialCountHtml}</span>
            </div>
            <div class="folder-children hidden">
                <!-- Les enfants seront chargés dynamiquement au clic sur le dossier -->
            </div>
        `;
        
        // Ajouter à la file d'attente pour charger le comptage DB de manière asynchrone
        if (physicalCount > 0) {
            queueFolderCount(folder.path, folder.id, item);
        }
        
        return item;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Gérer le clic sur un dossier
    treeContainer.addEventListener('click', async function(e) {
        const link = e.target.closest('.folder-link');
        const toggle = e.target.closest('.folder-toggle');
        
        // Si clic sur le lien, charger immédiatement le contenu du dossier
        if (link && !e.target.closest('.folder-expander')) {
            e.preventDefault();
            const folderId = toggle?.dataset.folderId;
            if (folderId) {
                loadFolderContent(folderId);
            }
            return;
        }
        
        // Si clic sur le toggle (expander), développer/réduire
        if (toggle && !link) {
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
    
    // Si un dossier est sélectionné, développer seulement son parent direct (pas tout le chemin)
    <?php if ($folderId): ?>
    const currentItem = treeContainer.querySelector(`[data-folder-id="<?= htmlspecialchars($folderId) ?>"]`);
    if (currentItem) {
        const parentItem = currentItem.parentElement.closest('.folder-item');
        if (parentItem) {
            const parentId = parentItem.dataset.folderId;
            const parentChildrenContainer = parentItem.querySelector('.folder-children');
            const parentToggle = parentItem.querySelector('.folder-toggle');
            const parentArrow = parentToggle?.querySelector('.folder-arrow');
            
            if (parentChildrenContainer && parentChildrenContainer.classList.contains('hidden')) {
                parentChildrenContainer.classList.remove('hidden');
                if (parentArrow) {
                    parentArrow.style.transform = 'rotate(90deg)';
                }
                expandedFolders.add(parentId);
            }
        }
    }
    <?php endif; ?>
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
