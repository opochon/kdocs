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
            <!-- Zone de chargement AJAX -->
            <div id="documents-loading" class="hidden flex flex-col items-center justify-center h-full text-center py-12">
                <svg class="w-8 h-8 text-blue-500 mx-auto mb-3 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-sm text-gray-500">Chargement...</p>
            </div>
            
            <!-- Contenu des documents (chargé via AJAX ou PHP) -->
            <div id="documents-content">
            <?php if (!empty($indexationMessage ?? null)): ?>
            <div class="flex flex-col items-center justify-center h-full text-center py-12">
                <svg class="w-8 h-8 text-orange-500 mx-auto mb-3 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <h2 class="text-base font-medium text-orange-600 mb-1">Indexation en cours</h2>
                <p class="text-sm text-orange-500 mb-4"><?= htmlspecialchars($indexationMessage) ?></p>
                <p class="text-xs text-gray-400">Les documents apparaîtront automatiquement une fois l'indexation terminée.</p>
            </div>
            <?php elseif (empty($documents)): ?>
            <div id="empty-state" class="flex flex-col items-center justify-center h-full text-center py-12">
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
                   class="document-card bg-white border border-gray-100 rounded hover:border-gray-200 hover:shadow-sm transition-all block relative">
                    <!-- Badge statut de validation -->
                    <?php
                    $validation_status = $doc['validation_status'] ?? null;
                    $size = 'sm';
                    ?>
                    <?php if ($validation_status || ($doc['status'] ?? '') === 'pending'): ?>
                    <div class="absolute top-2 right-2 z-10">
                        <?php if ($validation_status): ?>
                            <?php include __DIR__ . '/../components/validation_badge.php'; ?>
                        <?php elseif (($doc['status'] ?? '') === 'pending'): ?>
                        <span class="inline-flex items-center gap-1 rounded-full border bg-orange-100 text-orange-800 border-orange-200 text-xs px-2 py-0.5" title="Document en attente de traitement">
                            À classer
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
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
            </div><!-- /#documents-content -->
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

// Arborescence - Le JavaScript est maintenant généré par FolderTreeHelper::renderJavaScript()
// Plus besoin de code ici, tout est géré côté serveur avec indicateurs d'indexation

// ===== INDEXATION DES DOSSIERS =====
let currentIndexingPath = null;
let indexingPollInterval = null;

// Indexer un dossier (appelé par le bouton)
function indexFolder(path) {
    const btn = document.getElementById('index-folder-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `
            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Indexation...
        `;
    }
    
    currentIndexingPath = path;
    
    // Appeler l'API d'indexation (synchrone)
    fetch(`${BASE_PATH}/api/folders/index`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({path: path, async: false})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Mettre à jour les documents de manière asynchrone (sans recharger)
            updateDocumentsAfterIndexing(path, data);
            
            // Afficher un message de succès
            showNotification(`Indexation terminée: ${data.indexed || 0} document(s) indexé(s)`, 'success');
        } else {
            showNotification(`Erreur: ${data.error || 'Inconnue'}`, 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = `
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Indexer ce dossier
                `;
            }
        }
    })
    .catch(error => {
        console.error('Indexation error:', error);
        showNotification('Erreur de connexion', 'error');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Indexer ce dossier';
        }
    });
}

// Mettre à jour les documents après indexation (sans recharger la page)
function updateDocumentsAfterIndexing(path, indexResult) {
    // Requêter les nouveaux documents indexés
    fetch(`${BASE_PATH}/api/folders/documents?path=${encodeURIComponent(path || '')}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            const grid = document.querySelector('#documents-content .grid');
            if (!grid) return;
            
            // Créer un map des documents actuellement affichés (par filename)
            const existingCards = {};
            grid.querySelectorAll('.document-card').forEach(card => {
                const title = card.querySelector('h3')?.textContent?.trim();
                if (title) existingCards[title] = card;
            });
            
            // Parcourir les nouveaux documents
            data.documents.forEach(doc => {
                const title = doc.title || doc.filename || 'Sans titre';
                const existingCard = existingCards[title];
                
                if (existingCard && doc.id) {
                    // Document existait déjà, mettre à jour la carte
                    updateDocumentCard(existingCard, doc);
                }
            });
            
            // Masquer/mettre à jour la barre d'avertissement
            const warningBar = document.querySelector('#documents-content .bg-yellow-50');
            if (warningBar) {
                if (data.stats.physical_count === 0) {
                    // Plus de fichiers non indexés, masquer la barre avec animation
                    warningBar.style.transition = 'opacity 0.3s, max-height 0.3s';
                    warningBar.style.opacity = '0';
                    warningBar.style.maxHeight = '0';
                    warningBar.style.overflow = 'hidden';
                    setTimeout(() => warningBar.remove(), 300);
                } else {
                    // Mettre à jour le compteur
                    const countSpan = warningBar.querySelector('span');
                    if (countSpan) {
                        countSpan.textContent = `⚠ ${data.stats.physical_count} fichier(s) non indexé(s) dans ce dossier`;
                    }
                    // Réactiver le bouton
                    const btn = warningBar.querySelector('button');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = `
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            Indexer ce dossier
                        `;
                    }
                }
            }
        })
        .catch(error => console.error('Error updating documents:', error));
}

// Mettre à jour une carte de document individuellement
function updateDocumentCard(card, doc) {
    // Supprimer le badge "Non indexé" s'il existe
    const badge = card.querySelector('.bg-yellow-100');
    if (badge) {
        badge.style.transition = 'opacity 0.3s';
        badge.style.opacity = '0';
        setTimeout(() => badge.parentElement?.remove(), 300);
    }
    
    // Retirer l'opacité réduite
    card.classList.remove('opacity-75');
    
    // Mettre à jour le lien
    if (doc.id) {
        card.href = `${BASE_PATH}/documents/${doc.id}`;
    }
    
    // Mettre à jour la thumbnail si disponible
    if (doc.thumbnail_url) {
        const imgContainer = card.querySelector('.aspect-\\[3\\/4\\]');
        if (imgContainer) {
            let img = imgContainer.querySelector('img');
            if (!img) {
                img = document.createElement('img');
                img.className = 'w-full h-full object-cover';
                img.onerror = function() {
                    this.style.display = 'none';
                    this.nextElementSibling.style.display = 'flex';
                };
                imgContainer.insertBefore(img, imgContainer.firstChild);
            }
            img.src = doc.thumbnail_url;
            img.alt = doc.title || doc.filename;
            img.style.display = 'block';
            
            // Cacher l'icône placeholder
            const placeholder = imgContainer.querySelector('div');
            if (placeholder) placeholder.style.display = 'none';
        }
    }
    
    // Mettre à jour le titre si nécessaire
    const titleEl = card.querySelector('h3');
    if (titleEl && doc.title && doc.title !== titleEl.textContent) {
        titleEl.textContent = doc.title;
        titleEl.title = doc.title;
    }
    
    // Animation de succès
    card.style.transition = 'box-shadow 0.3s, border-color 0.3s';
    card.style.boxShadow = '0 0 0 2px rgba(34, 197, 94, 0.5)';
    card.style.borderColor = 'rgb(34, 197, 94)';
    setTimeout(() => {
        card.style.boxShadow = '';
        card.style.borderColor = '';
    }, 2000);
}

// Afficher une notification
function showNotification(message, type = 'info') {
    const colors = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        info: 'bg-blue-500'
    };
    
    const notif = document.createElement('div');
    notif.className = `fixed top-4 right-4 px-4 py-2 rounded text-white text-sm ${colors[type] || colors.info} shadow-lg z-50`;
    notif.textContent = message;
    document.body.appendChild(notif);
    
    setTimeout(() => notif.remove(), 4000);
}

// ===== CHARGEMENT AJAX DES DOCUMENTS =====
const BASE_PATH = '<?= $base ?>';

// Charger les documents d'un dossier via AJAX (appelé par les liens de la sidebar)
function loadFolderDocuments(path, updateUrl = true) {
    const loadingEl = document.getElementById('documents-loading');
    const contentEl = document.getElementById('documents-content');
    
    // Afficher le chargement
    if (loadingEl) loadingEl.classList.remove('hidden');
    if (contentEl) contentEl.innerHTML = '';
    
    // Mettre à jour l'URL sans recharger la page
    if (updateUrl) {
        // Simple hash pour le folderId (pas besoin de MD5 exact)
        const folderId = path ? btoa(path).replace(/[^a-zA-Z0-9]/g, '').substring(0, 32) : '';
        const newUrl = new URL(window.location.href);
        if (path) {
            newUrl.searchParams.set('folder', folderId || 'folder');
            newUrl.searchParams.set('path', path);
        } else {
            newUrl.searchParams.delete('folder');
            newUrl.searchParams.delete('path');
        }
        newUrl.searchParams.delete('page');
        history.pushState({path: path}, '', newUrl.toString());
    }
    
    // Appeler l'API
    fetch(`${BASE_PATH}/api/folders/documents?path=${encodeURIComponent(path || '')}`)
        .then(response => response.json())
        .then(data => {
            if (loadingEl) loadingEl.classList.add('hidden');
            
            if (data.success) {
                renderDocuments(data.documents, data.pagination, data.stats, path);
            } else {
                contentEl.innerHTML = `<div class="text-center py-12 text-red-500">Erreur: ${data.error || 'Inconnue'}</div>`;
            }
        })
        .catch(error => {
            if (loadingEl) loadingEl.classList.add('hidden');
            contentEl.innerHTML = `<div class="text-center py-12 text-red-500">Erreur de chargement</div>`;
            console.error('Error loading documents:', error);
        });
}

// Rendre les documents dans la grille
function renderDocuments(documents, pagination, stats, path) {
    const contentEl = document.getElementById('documents-content');
    if (!contentEl) return;
    
    // NE PAS déclencher l'indexation automatiquement
    // L'utilisateur peut cliquer sur le bouton "Indexer ce dossier" s'il le souhaite
    
    if (!documents || documents.length === 0) {
        contentEl.innerHTML = `
            <div class="flex flex-col items-center justify-center h-full text-center py-12">
                <svg class="w-12 h-12 text-gray-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <h2 class="text-base font-medium text-gray-700 mb-1">Aucun document</h2>
                <p class="text-sm text-gray-400 mb-2">Ce dossier est vide.</p>
                ${stats && stats.physical_count === 0 ? '' : `<p class="text-xs text-orange-500">Fichiers physiques non indexés: ${stats?.physical_count || 0}</p>`}
            </div>
        `;
        return;
    }
    
    // Générer la grille de documents
    let html = `<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-3 w-full max-w-none">`;
    
    documents.forEach(doc => {
        const isPhysical = doc.is_physical || doc.status === 'not_indexed';
        const title = doc.title || doc.filename || 'Sans titre';
        const date = doc.created_at ? new Date(doc.created_at).toLocaleDateString('fr-CH') : '';
        const href = doc.id ? `${BASE_PATH}/documents/${doc.id}` : '#';
        const thumbnailUrl = doc.thumbnail_url || '';
        
        html += `
            <a href="${href}" 
               class="document-card bg-white border border-gray-100 rounded hover:border-gray-200 hover:shadow-sm transition-all block relative ${isPhysical ? 'opacity-75' : ''}">
                ${isPhysical ? `
                <div class="absolute top-2 right-2 z-10">
                    <span class="inline-flex items-center gap-1 rounded-full border bg-yellow-100 text-yellow-800 border-yellow-200 text-xs px-2 py-0.5" title="Fichier non indexé">
                        Non indexé
                    </span>
                </div>` : ''}
                <div class="aspect-[3/4] bg-gray-50 flex items-center justify-center overflow-hidden relative">
                    ${thumbnailUrl ? `
                    <img src="${thumbnailUrl}" alt="${title}" class="w-full h-full object-cover"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    ` : ''}
                    <div class="w-full h-full flex items-center justify-center ${thumbnailUrl ? 'hidden' : ''}">
                        <svg class="w-8 h-8 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="p-1.5 border-t border-gray-50">
                    <h3 class="text-xs font-medium text-gray-900 truncate mb-0.5" title="${title}">${title}</h3>
                    <p class="text-xs text-gray-400">${date}</p>
                </div>
            </a>
        `;
    });
    
    html += `</div>`;
    
    // Ajouter les stats si fichiers physiques
    if (stats && stats.physical_count > 0) {
        html += `
            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex items-center justify-between">
                    <span class="text-yellow-800 text-sm">⚠ ${stats.physical_count} fichier(s) non indexé(s) dans ce dossier</span>
                    <button onclick="indexFolder('${path || ''}')" class="px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 flex items-center gap-1" id="index-folder-btn">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Indexer ce dossier
                    </button>
                </div>
            </div>
        `;
    }
    
    contentEl.innerHTML = html;
}

// Intercepter les clics sur les liens de dossiers dans la sidebar
document.addEventListener('click', function(e) {
    const folderLink = e.target.closest('.folder-link[data-ajax-load]');
    if (folderLink) {
        e.preventDefault();
        const path = folderLink.dataset.path || '';
        loadFolderDocuments(path);
        
        // Mettre en surbrillance le dossier actif
        document.querySelectorAll('.folder-link').forEach(el => el.classList.remove('bg-gray-100', 'font-medium'));
        folderLink.classList.add('bg-gray-100', 'font-medium');
    }
});

// Gérer le bouton retour du navigateur
window.addEventListener('popstate', function(e) {
    if (e.state && e.state.path !== undefined) {
        loadFolderDocuments(e.state.path, false);
    }
});

// ===== FONCTIONS D'INDEXATION (utilisées uniquement via le bouton) =====
// Note: L'indexation automatique a été désactivée pour éviter les rechargements intempestifs

// Polling global pour les indexations (peut être utilisé si on réactive l'async)
var kdocsIndexingPollInterval = null;

// Afficher la barre de progression d'indexation
function showIndexingBar(path, total) {
    const bar = document.getElementById('indexing-status-bar');
    const list = document.getElementById('indexing-progress-list');
    
    if (!bar || !list) return;
    
    bar.style.display = 'block';
    
    // Ajouter ou mettre à jour l'entrée pour ce dossier
    let entry = document.getElementById(`indexing-${btoa(path).replace(/[^a-zA-Z0-9]/g, '')}`);
    if (!entry) {
        entry = document.createElement('div');
        entry.id = `indexing-${btoa(path).replace(/[^a-zA-Z0-9]/g, '')}`;
        entry.className = 'flex items-center gap-3';
        list.appendChild(entry);
    }
    
    entry.innerHTML = `
        <svg class="w-4 h-4 text-orange-500 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span class="text-sm text-gray-700">
            <strong>${path || 'Racine'}</strong> - Indexation de ${total} fichier(s)...
        </span>
    `;
}

// Masquer la barre de progression pour un dossier
function hideIndexingBar(path) {
    const entry = document.getElementById(`indexing-${btoa(path).replace(/[^a-zA-Z0-9]/g, '')}`);
    if (entry) {
        entry.innerHTML = `
            <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <span class="text-sm text-green-700">
                <strong>${path || 'Racine'}</strong> - Indexation terminée!
            </span>
        `;
        
        // Masquer après 3 secondes
        setTimeout(() => {
            entry.remove();
            const list = document.getElementById('indexing-progress-list');
            if (list && list.children.length === 0) {
                document.getElementById('indexing-status-bar').style.display = 'none';
            }
        }, 3000);
    }
}

// Polling pour vérifier l'état des indexations
function startIndexingPolling() {
    if (kdocsIndexingPollInterval) return;
    
    kdocsIndexingPollInterval = setInterval(() => {
        fetch(`${BASE_PATH}/api/folders/indexing-all`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.count > 0) {
                    // Afficher la barre avec les indexations en cours
                    data.indexing.forEach(idx => {
                        showIndexingBar(idx.path, idx.total);
                    });
                } else {
                    // Aucune indexation en cours, arrêter le polling
                    stopIndexingPolling();
                }
            })
            .catch(() => {});
    }, 3000);
}

function stopIndexingPolling() {
    if (kdocsIndexingPollInterval) {
        clearInterval(kdocsIndexingPollInterval);
        kdocsIndexingPollInterval = null;
    }
}

// Charger automatiquement si on a un path dans l'URL et que le contenu est vide
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const pathParam = urlParams.get('path');
    const folderParam = urlParams.get('folder');
    const emptyState = document.getElementById('empty-state');
    const contentEl = document.getElementById('documents-content');
    
    // Si on a un path (dossier filesystem sélectionné), TOUJOURS charger via AJAX
    // Car PHP ne scan pas le filesystem, seulement la DB
    if (pathParam || folderParam) {
        // Charger via AJAX pour avoir les fichiers physiques + DB
        loadFolderDocuments(pathParam || '', false);
    }
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
