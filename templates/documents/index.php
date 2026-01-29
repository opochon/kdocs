<?php
// Vue grille style Paperless-ngx - Version sobre et épurée
// $documents, $logicalFolders, $fsFolders, $documentTypes, $tags, $search, $logicalFolderId, $folderId, $typeId, $currentFolder sont passés
use KDocs\Core\Config;
use KDocs\Models\LogicalFolder;
$base = Config::basePath();
?>

<!-- Modale de prévisualisation du document -->
<div id="document-preview-modal" class="fixed inset-0 z-50 hidden">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/50" onclick="closeDocumentPreview()"></div>

    <!-- Panneau latéral (90% de la largeur) -->
    <div class="absolute right-0 top-0 bottom-0 w-full max-w-6xl bg-white shadow-2xl flex flex-col transform transition-transform duration-300 translate-x-full" id="preview-panel">
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-2 border-b bg-gray-50">
            <div class="flex items-center gap-3">
                <button onclick="closeDocumentPreview()" class="p-1 hover:bg-gray-200 rounded" title="Fermer (Echap)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                <h2 id="preview-title" class="font-medium text-gray-900 truncate max-w-lg">Chargement...</h2>
            </div>
            <div class="flex items-center gap-2">
                <!-- Navigation prev/next -->
                <button onclick="navigatePreview(-1)" id="preview-prev-btn" class="p-1.5 hover:bg-gray-200 rounded disabled:opacity-30" title="Document précédent (←)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
                <span class="text-xs text-gray-400" id="preview-position"></span>
                <button onclick="navigatePreview(1)" id="preview-next-btn" class="p-1.5 hover:bg-gray-200 rounded disabled:opacity-30" title="Document suivant (→)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
                <span class="mx-1 text-gray-300">|</span>
                <!-- Actions -->
                <a href="#" id="preview-download-btn" class="p-1.5 hover:bg-gray-200 rounded" title="Télécharger">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                </a>
                <a href="#" id="preview-fullpage-btn" class="p-1.5 hover:bg-gray-200 rounded" title="Ouvrir page complète">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                    </svg>
                </a>
            </div>
        </div>

        <!-- Contenu -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Viewer (55%) -->
            <div class="w-1/2 lg:w-3/5 bg-gray-100 flex items-center justify-center overflow-auto" id="preview-viewer-container">
                <div id="preview-loading" class="text-center">
                    <svg class="w-8 h-8 text-gray-400 mx-auto animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-sm text-gray-500 mt-2">Chargement...</p>
                </div>
                <div id="preview-viewer" class="hidden w-full h-full">
                    <!-- PDF ou Image sera injecté ici -->
                </div>
            </div>

            <!-- Formulaire d'édition (45%) -->
            <div class="w-1/2 lg:w-2/5 border-l bg-white overflow-y-auto p-3">
                <div id="preview-metadata">
                    <!-- Formulaire sera injecté ici -->
                </div>
            </div>
        </div>
    </div>
</div>

<div class="flex min-h-screen bg-white w-full overflow-hidden">
    
    <!-- Sidebar gauche - Minimaliste avec redimensionnement -->
    <aside id="documents-sidebar" class="bg-white border-r border-gray-100 overflow-y-auto flex-shrink-0 relative" style="max-height: 100vh; min-width: 180px; width: 240px;">
        <!-- Poignée de redimensionnement -->
        <div id="sidebar-resize-handle" class="absolute right-0 top-0 bottom-0 w-1.5 cursor-col-resize z-20 group" style="margin-right: -3px;">
            <div class="absolute inset-y-0 left-0 w-full bg-transparent hover:bg-blue-400 active:bg-blue-500 transition-colors"></div>
            <div class="absolute top-1/2 -translate-y-1/2 left-1/2 -translate-x-1/2 w-1 h-8 rounded-full bg-gray-300 group-hover:bg-blue-400 transition-colors opacity-0 group-hover:opacity-100"></div>
        </div>
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
                    <!-- Recherche avancée -->
                    <div class="relative">
                        <input type="text"
                               id="search-input"
                               value="<?= htmlspecialchars($search ?? '') ?>"
                               placeholder="Rechercher... (AND, OR, &quot;phrase&quot;)"
                               class="w-64 px-2 py-1 text-sm border border-gray-200 rounded focus:outline-none focus:border-gray-400 pr-8">
                        <button type="button" id="toggle-search-options" class="absolute right-1 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600" title="Options de recherche">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                            </svg>
                        </button>
                    </div>

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

            <!-- Panneau options de recherche avancée (collapsible) -->
            <div id="search-options-panel" class="hidden border-t border-gray-100 pt-2 mt-2">
                <div class="flex flex-wrap items-center gap-4 text-xs">
                    <!-- Scope -->
                    <div class="flex items-center gap-1">
                        <span class="text-gray-500">Dans:</span>
                        <button type="button" data-scope="all" class="scope-btn px-2 py-0.5 rounded bg-gray-800 text-white">Tout</button>
                        <button type="button" data-scope="name" class="scope-btn px-2 py-0.5 rounded bg-gray-100 text-gray-600 hover:bg-gray-200">Nom</button>
                        <button type="button" data-scope="content" class="scope-btn px-2 py-0.5 rounded bg-gray-100 text-gray-600 hover:bg-gray-200">Contenu</button>
                    </div>

                    <!-- Période -->
                    <div class="flex items-center gap-1">
                        <span class="text-gray-500">Période:</span>
                        <input type="date" id="search-date-from" class="px-1.5 py-0.5 border border-gray-200 rounded text-xs">
                        <span class="text-gray-300">-</span>
                        <input type="date" id="search-date-to" class="px-1.5 py-0.5 border border-gray-200 rounded text-xs">
                    </div>

                    <!-- Aide syntaxe -->
                    <button type="button" id="syntax-help-btn" class="text-gray-400 hover:text-gray-600" title="Aide syntaxe">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </button>

                    <!-- Bouton rechercher -->
                    <button type="button" id="do-advanced-search" class="px-2 py-0.5 bg-gray-800 text-white rounded hover:bg-gray-700">
                        Rechercher
                    </button>

                    <!-- Reset -->
                    <button type="button" id="reset-search" class="text-gray-400 hover:text-gray-600 text-xs underline">
                        Réinitialiser
                    </button>
                </div>

                <!-- Popup aide syntaxe -->
                <div id="syntax-help-popup" class="hidden mt-2 p-2 bg-gray-50 border border-gray-200 rounded text-xs">
                    <h4 class="font-semibold text-gray-700 mb-1">Syntaxe de recherche</h4>
                    <div class="grid grid-cols-3 gap-x-4 gap-y-1 text-gray-600">
                        <div><code class="bg-white px-1 rounded">mot1 AND mot2</code> Les deux</div>
                        <div><code class="bg-white px-1 rounded">mot1 OR mot2</code> L'un ou l'autre</div>
                        <div><code class="bg-white px-1 rounded">"phrase exacte"</code> Expression exacte</div>
                        <div><code class="bg-white px-1 rounded">NOT mot</code> Exclure</div>
                        <div><code class="bg-white px-1 rounded">fact*</code> Commence par</div>
                        <div><code class="bg-white px-1 rounded">t?st</code> Un caractère variable</div>
                    </div>
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
                <?php foreach ($documents as $index => $doc): ?>
                <div class="document-card bg-white border border-gray-100 rounded hover:border-gray-200 hover:shadow-sm transition-all block relative cursor-pointer"
                     data-doc-id="<?= $doc['id'] ?? '' ?>"
                     data-doc-index="<?= $index ?>"
                     onclick="openDocumentPreview(<?= $doc['id'] ?? 0 ?>, <?= $index ?>)">
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
                </div>
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
// ===== MODALE DE PRÉVISUALISATION =====
let currentPreviewIndex = 0;
let documentsList = [];

// Collecter tous les IDs des documents affichés
function collectDocumentIds() {
    documentsList = [];
    document.querySelectorAll('.document-card[data-doc-id]').forEach(card => {
        const id = parseInt(card.dataset.docId);
        if (id > 0) {
            documentsList.push(id);
        }
    });
}

// Ouvrir la modale de prévisualisation
function openDocumentPreview(docId, index) {
    if (!docId) return;

    collectDocumentIds();
    currentPreviewIndex = index;

    const modal = document.getElementById('document-preview-modal');
    const panel = document.getElementById('preview-panel');

    // Afficher la modale
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    // Animer le panneau
    setTimeout(() => {
        panel.classList.remove('translate-x-full');
    }, 10);

    // Charger le document
    loadDocumentPreview(docId);

    // Mettre à jour la navigation
    updatePreviewNavigation();
}

// Fermer la modale
function closeDocumentPreview() {
    const modal = document.getElementById('document-preview-modal');
    const panel = document.getElementById('preview-panel');

    panel.classList.add('translate-x-full');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
        // Nettoyer le viewer
        document.getElementById('preview-viewer').innerHTML = '';
        document.getElementById('preview-viewer').classList.add('hidden');
        document.getElementById('preview-loading').classList.remove('hidden');
    }, 300);
}

// Naviguer entre les documents
function navigatePreview(direction) {
    const newIndex = currentPreviewIndex + direction;
    if (newIndex >= 0 && newIndex < documentsList.length) {
        currentPreviewIndex = newIndex;
        const docId = documentsList[newIndex];
        loadDocumentPreview(docId);
        updatePreviewNavigation();
    }
}

// Mettre à jour les boutons de navigation
function updatePreviewNavigation() {
    const prevBtn = document.getElementById('preview-prev-btn');
    const nextBtn = document.getElementById('preview-next-btn');
    const position = document.getElementById('preview-position');

    prevBtn.disabled = currentPreviewIndex <= 0;
    nextBtn.disabled = currentPreviewIndex >= documentsList.length - 1;
    position.textContent = `${currentPreviewIndex + 1} / ${documentsList.length}`;
}

// Charger les détails du document
function loadDocumentPreview(docId) {
    const loading = document.getElementById('preview-loading');
    const viewer = document.getElementById('preview-viewer');
    const metadata = document.getElementById('preview-metadata');

    loading.classList.remove('hidden');
    viewer.classList.add('hidden');

    fetch(`${BASE_PATH}/api/documents/${docId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data) {
                throw new Error(data.error || 'Document non trouvé');
            }

            const doc = data.data;

            // Mettre à jour le titre
            document.getElementById('preview-title').textContent = doc.title || doc.filename;

            // Mettre à jour les liens
            document.getElementById('preview-download-btn').href = `${BASE_PATH}/documents/${docId}/download`;
            document.getElementById('preview-fullpage-btn').href = `${BASE_PATH}/documents/${docId}`;

            // Afficher le viewer selon le type
            renderDocumentViewer(doc);

            // Afficher les métadonnées
            renderDocumentMetadata(doc);

            loading.classList.add('hidden');
            viewer.classList.remove('hidden');
        })
        .catch(error => {
            console.error('Error loading document:', error);
            loading.innerHTML = `<p class="text-red-500">Erreur: ${error.message}</p>`;
        });
}

// Afficher le document (PDF ou image)
function renderDocumentViewer(doc) {
    const viewer = document.getElementById('preview-viewer');
    const mimeType = doc.mime_type || '';

    if (mimeType === 'application/pdf') {
        // PDF viewer avec iframe
        viewer.innerHTML = `
            <iframe src="${BASE_PATH}/documents/${doc.id}/view#toolbar=1&navpanes=0"
                    class="w-full h-full border-0 rounded bg-white"
                    style="min-height: 600px;"></iframe>
        `;
    } else if (mimeType.startsWith('image/')) {
        // Image
        viewer.innerHTML = `
            <div class="flex items-center justify-center w-full h-full">
                <img src="${BASE_PATH}/documents/${doc.id}/view"
                     alt="${doc.title || doc.filename}"
                     class="max-w-full max-h-full object-contain rounded shadow">
            </div>
        `;
    } else {
        // Autres types - afficher la miniature
        viewer.innerHTML = `
            <div class="flex flex-col items-center justify-center w-full h-full text-center">
                <img src="${BASE_PATH}/documents/${doc.id}/thumbnail"
                     alt="${doc.title || doc.filename}"
                     class="max-w-64 max-h-64 object-contain rounded shadow mb-4"
                     onerror="this.style.display='none'">
                <p class="text-gray-500">Aperçu non disponible pour ce type de fichier</p>
                <a href="${BASE_PATH}/documents/${doc.id}/download"
                   class="mt-2 px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                    Télécharger le fichier
                </a>
            </div>
        `;
    }
}

// Variable globale pour le document actuel
let currentPreviewDocument = null;

// Afficher les métadonnées (version éditable complète)
function renderDocumentMetadata(doc) {
    currentPreviewDocument = doc;
    const metadata = document.getElementById('preview-metadata');
    const meta = doc._meta || {};

    const formatDate = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toISOString().split('T')[0];
    };

    const formatDisplayDate = (dateStr) => {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleDateString('fr-CH');
    };

    // Options pour les selects
    const correspondentOptions = (meta.correspondents || []).map(c =>
        `<option value="${c.id}" ${doc.correspondent_id == c.id ? 'selected' : ''}>${c.name}</option>`
    ).join('');

    const typeOptions = (meta.document_types || []).map(t =>
        `<option value="${t.id}" ${doc.document_type_id == t.id ? 'selected' : ''}>${t.label}</option>`
    ).join('');

    // Tags sélectionnés
    const selectedTagIds = (doc.tags || []).map(t => t.id);
    const tagCheckboxes = (meta.all_tags || []).map(t => `
        <label class="flex items-center gap-1.5 text-xs cursor-pointer">
            <input type="checkbox" name="preview_tags" value="${t.id}" ${selectedTagIds.includes(t.id) ? 'checked' : ''}
                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <span class="px-1.5 py-0.5 rounded" style="background-color: ${t.color || '#e5e7eb'}20; color: ${t.color || '#6b7280'}">
                ${t.name}
            </span>
        </label>
    `).join('');

    // Statut de validation
    const validationStatus = doc.validation_status || 'pending';
    const validationBadge = {
        'approved': '<span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800">Validé</span>',
        'rejected': '<span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800">Rejeté</span>',
        'na': '<span class="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-600">N/A</span>',
        'pending': '<span class="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-800">En attente</span>'
    }[validationStatus] || '';

    // Notes
    const notesHtml = (doc.notes || []).length > 0
        ? doc.notes.map(note => `
            <div class="bg-gray-50 rounded p-2 text-xs border">
                <div class="flex justify-between items-start">
                    <p class="text-gray-700 whitespace-pre-wrap flex-1">${note.note || note.content || ''}</p>
                    <button onclick="deleteNotePreview(${note.id})" class="text-red-500 hover:text-red-700 ml-2">×</button>
                </div>
                <p class="text-gray-400 mt-1">${note.user_name || ''} • ${formatDisplayDate(note.created_at)}</p>
            </div>
        `).join('')
        : '<p class="text-xs text-gray-400">Aucune note</p>';

    // OCR/Résumé (premiers 500 caractères)
    const ocrPreview = doc.ocr_text
        ? (doc.ocr_text.length > 500 ? doc.ocr_text.substring(0, 500) + '...' : doc.ocr_text)
        : '';

    metadata.innerHTML = `
        <div class="space-y-3 text-sm">
            <!-- Header avec AI et validation -->
            <div class="flex items-center justify-between pb-2 border-b">
                ${doc.ai_available ? `
                <button onclick="getAISuggestionsPreview(${doc.id})" class="px-2 py-1 text-xs border border-purple-300 text-purple-700 rounded hover:bg-purple-50">
                    Suggestions IA
                </button>
                ` : '<span></span>'}
                <div>${validationBadge}</div>
            </div>

            <!-- Validation rapide -->
            ${doc.can_validate ? `
            <div class="flex gap-1 pb-2 border-b">
                <button onclick="setValidationStatus(${doc.id}, 'approved')"
                        class="flex-1 px-2 py-1 text-xs rounded ${validationStatus === 'approved' ? 'bg-green-600 text-white' : 'border border-green-300 text-green-700 hover:bg-green-50'}">
                    Valider
                </button>
                <button onclick="setValidationStatus(${doc.id}, 'rejected')"
                        class="flex-1 px-2 py-1 text-xs rounded ${validationStatus === 'rejected' ? 'bg-red-600 text-white' : 'border border-red-300 text-red-700 hover:bg-red-50'}">
                    Rejeter
                </button>
                <button onclick="setValidationStatus(${doc.id}, 'na')"
                        class="flex-1 px-2 py-1 text-xs rounded ${validationStatus === 'na' ? 'bg-gray-600 text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50'}">
                    N/A
                </button>
            </div>
            ` : ''}

            <!-- Titre -->
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Titre</label>
                <input type="text" id="preview-title-input" value="${doc.title || ''}" placeholder="${doc.filename || ''}"
                       class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Type de document -->
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                <select id="preview-type-select" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
                    <option value="">Non défini</option>
                    ${typeOptions}
                </select>
            </div>

            <!-- Correspondant -->
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Correspondant</label>
                <select id="preview-correspondent-select" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
                    <option value="">Non défini</option>
                    ${correspondentOptions}
                </select>
            </div>

            <!-- Date -->
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Date du document</label>
                <input type="date" id="preview-date-input" value="${formatDate(doc.document_date)}"
                       class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:ring-1 focus:ring-blue-500">
            </div>

            <!-- Tags -->
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Tags</label>
                <div class="max-h-24 overflow-y-auto space-y-1 p-2 border rounded bg-gray-50">
                    ${tagCheckboxes || '<p class="text-xs text-gray-400">Aucun tag disponible</p>'}
                </div>
            </div>

            <!-- Notes -->
            <div class="pt-2 border-t">
                <label class="block text-xs font-medium text-gray-500 mb-1">Notes</label>
                <div class="space-y-2 max-h-32 overflow-y-auto mb-2">
                    ${notesHtml}
                </div>
                <div class="flex gap-1">
                    <input type="text" id="preview-new-note" placeholder="Ajouter une note..."
                           class="flex-1 px-2 py-1 text-xs border rounded focus:ring-1 focus:ring-blue-500"
                           onkeypress="if(event.key==='Enter')addNotePreview(${doc.id})">
                    <button onclick="addNotePreview(${doc.id})" class="px-2 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700">+</button>
                </div>
            </div>

            <!-- Résumé/OCR -->
            ${ocrPreview ? `
            <div class="pt-2 border-t">
                <label class="block text-xs font-medium text-gray-500 mb-1">Contenu extrait</label>
                <div class="text-xs text-gray-600 bg-gray-50 p-2 rounded max-h-24 overflow-y-auto whitespace-pre-wrap">${ocrPreview}</div>
            </div>
            ` : ''}

            <!-- Infos fichier -->
            <div class="pt-2 border-t text-xs text-gray-500">
                <p><span class="font-medium">Fichier:</span> ${doc.original_filename || doc.filename}</p>
                <p><span class="font-medium">Créé:</span> ${formatDisplayDate(doc.created_at)}</p>
                ${doc.asn ? `<p><span class="font-medium">ASN:</span> ${doc.asn}</p>` : ''}
            </div>

            <!-- Actions -->
            <div class="pt-3 border-t space-y-2">
                <div class="flex gap-2">
                    <button onclick="closeDocumentPreview()" class="flex-1 px-3 py-2 text-sm border rounded hover:bg-gray-50">
                        Annuler
                    </button>
                    <button onclick="saveDocumentPreview(${doc.id})" class="flex-1 px-3 py-2 text-sm bg-green-600 text-white rounded hover:bg-green-700">
                        Enregistrer
                    </button>
                </div>
                <button onclick="saveDocumentPreview(${doc.id}, true)" class="w-full px-3 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">
                    Enregistrer & suivant
                </button>
            </div>
        </div>
    `;
}

// Sauvegarder le document depuis le preview
async function saveDocumentPreview(docId, goNext = false) {
    const title = document.getElementById('preview-title-input')?.value || '';
    const documentTypeId = document.getElementById('preview-type-select')?.value || null;
    const correspondentId = document.getElementById('preview-correspondent-select')?.value || null;
    const documentDate = document.getElementById('preview-date-input')?.value || null;

    // Récupérer les tags sélectionnés
    const tagCheckboxes = document.querySelectorAll('input[name="preview_tags"]:checked');
    const tagIds = Array.from(tagCheckboxes).map(cb => parseInt(cb.value));

    try {
        const response = await fetch(`${BASE_PATH}/api/documents/${docId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                title: title,
                document_type_id: documentTypeId || null,
                correspondent_id: correspondentId || null,
                document_date: documentDate || null,
                tags: tagIds
            })
        });

        const result = await response.json();
        if (result.success) {
            // Mettre à jour l'affichage dans la grille si visible
            const card = document.querySelector(`[data-doc-id="${docId}"]`);
            if (card) {
                const titleEl = card.querySelector('.doc-title');
                if (titleEl) titleEl.textContent = title || currentPreviewDocument?.filename || '';
            }

            if (goNext) {
                navigatePreview(1);
            } else {
                // Recharger le document pour afficher les changements
                loadDocumentPreview(docId);
            }
        } else {
            alert('Erreur: ' + (result.error || 'Échec de la sauvegarde'));
        }
    } catch (error) {
        console.error('Save error:', error);
        alert('Erreur lors de la sauvegarde');
    }
}

// Définir le statut de validation
async function setValidationStatus(docId, status) {
    try {
        const response = await fetch(`${BASE_PATH}/api/validation/${docId}/status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ status: status })
        });

        const result = await response.json();
        if (result.success) {
            // Recharger le document
            loadDocumentPreview(docId);
        } else {
            alert('Erreur: ' + (result.error || 'Échec de la validation'));
        }
    } catch (error) {
        console.error('Validation error:', error);
        alert('Erreur lors de la validation');
    }
}

// Suggestions IA
async function getAISuggestionsPreview(docId) {
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = 'Analyse...';
    btn.disabled = true;

    try {
        const response = await fetch(`${BASE_PATH}/api/documents/${docId}/classify-ai`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });

        const result = await response.json();
        if (result.success && result.data?.suggestions) {
            const s = result.data.suggestions;
            const matched = s.matched || {};

            // Appliquer les suggestions aux champs
            if (s.title_suggestion) {
                document.getElementById('preview-title-input').value = s.title_suggestion;
            }
            if (matched.document_type_id) {
                document.getElementById('preview-type-select').value = matched.document_type_id;
            }
            if (matched.correspondent_id) {
                document.getElementById('preview-correspondent-select').value = matched.correspondent_id;
            }
            if (s.document_date) {
                document.getElementById('preview-date-input').value = s.document_date;
            }
            if (matched.tag_ids && matched.tag_ids.length > 0) {
                // Cocher les tags suggérés
                document.querySelectorAll('input[name="preview_tags"]').forEach(cb => {
                    cb.checked = matched.tag_ids.includes(parseInt(cb.value));
                });
            }

            // Afficher un message avec le résumé si disponible
            if (s.summary) {
                console.log('Résumé IA:', s.summary);
            }
        } else {
            alert(result.error || 'Aucune suggestion disponible');
        }
    } catch (error) {
        console.error('AI error:', error);
        alert('Erreur lors de l\'analyse IA');
    } finally {
        btn.textContent = originalText;
        btn.disabled = false;
    }
}

// Ajouter une note
async function addNotePreview(docId) {
    const input = document.getElementById('preview-new-note');
    const note = input?.value?.trim();
    if (!note) return;

    try {
        const response = await fetch(`${BASE_PATH}/api/documents/${docId}/notes`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ note: note })
        });

        const result = await response.json();
        if (result.success) {
            input.value = '';
            loadDocumentPreview(docId);
        } else {
            alert('Erreur: ' + (result.error || 'Échec de l\'ajout'));
        }
    } catch (error) {
        console.error('Note error:', error);
        alert('Erreur lors de l\'ajout de la note');
    }
}

// Supprimer une note
async function deleteNotePreview(noteId) {
    if (!confirm('Supprimer cette note ?')) return;

    try {
        const response = await fetch(`${BASE_PATH}/api/documents/${currentPreviewDocument.id}/notes/${noteId}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json' }
        });

        const result = await response.json();
        if (result.success && currentPreviewDocument) {
            loadDocumentPreview(currentPreviewDocument.id);
        }
    } catch (error) {
        console.error('Delete note error:', error);
    }
}

// Raccourci clavier pour fermer
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('document-preview-modal');
        if (!modal.classList.contains('hidden')) {
            closeDocumentPreview();
        }
    }
    // Navigation avec flèches
    if (e.key === 'ArrowLeft') {
        const modal = document.getElementById('document-preview-modal');
        if (!modal.classList.contains('hidden')) {
            navigatePreview(-1);
        }
    }
    if (e.key === 'ArrowRight') {
        const modal = document.getElementById('document-preview-modal');
        if (!modal.classList.contains('hidden')) {
            navigatePreview(1);
        }
    }
});

// ===== RECHERCHE AVANCÉE =====
let searchScope = 'all';

// Toggle panneau options
document.getElementById('toggle-search-options')?.addEventListener('click', function() {
    const panel = document.getElementById('search-options-panel');
    panel?.classList.toggle('hidden');
});

// Scope buttons
document.querySelectorAll('.scope-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.scope-btn').forEach(b => {
            b.classList.remove('bg-gray-800', 'text-white');
            b.classList.add('bg-gray-100', 'text-gray-600');
        });
        this.classList.remove('bg-gray-100', 'text-gray-600');
        this.classList.add('bg-gray-800', 'text-white');
        searchScope = this.dataset.scope;
    });
});

// Syntax help toggle
document.getElementById('syntax-help-btn')?.addEventListener('click', function() {
    document.getElementById('syntax-help-popup')?.classList.toggle('hidden');
});

// Fonction de recherche avancée
function doAdvancedSearch() {
    const search = document.getElementById('search-input')?.value || '';
    const dateFrom = document.getElementById('search-date-from')?.value || '';
    const dateTo = document.getElementById('search-date-to')?.value || '';

    const url = new URL(window.location.href);

    if (search) {
        url.searchParams.set('search', search);
    } else {
        url.searchParams.delete('search');
    }

    if (searchScope !== 'all') {
        url.searchParams.set('scope', searchScope);
    } else {
        url.searchParams.delete('scope');
    }

    if (dateFrom) {
        url.searchParams.set('date_from', dateFrom);
    } else {
        url.searchParams.delete('date_from');
    }

    if (dateTo) {
        url.searchParams.set('date_to', dateTo);
    } else {
        url.searchParams.delete('date_to');
    }

    url.searchParams.delete('page');
    window.location.href = url.toString();
}

// Recherche sur Enter ou bouton
document.getElementById('search-input')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        doAdvancedSearch();
    }
});

document.getElementById('do-advanced-search')?.addEventListener('click', doAdvancedSearch);

// Reset recherche
document.getElementById('reset-search')?.addEventListener('click', function() {
    const url = new URL(window.location.href);
    url.searchParams.delete('search');
    url.searchParams.delete('scope');
    url.searchParams.delete('date_from');
    url.searchParams.delete('date_to');
    url.searchParams.delete('page');
    window.location.href = url.toString();
});

// Initialiser les valeurs depuis PHP/URL
(function initSearchFromUrl() {
    // Valeurs PHP
    const phpScope = '<?= htmlspecialchars($searchScope ?? 'all') ?>';
    const phpDateFrom = '<?= htmlspecialchars($dateFrom ?? '') ?>';
    const phpDateTo = '<?= htmlspecialchars($dateTo ?? '') ?>';

    // Scope
    if (phpScope && phpScope !== 'all') {
        searchScope = phpScope;
        document.querySelectorAll('.scope-btn').forEach(btn => {
            if (btn.dataset.scope === phpScope) {
                btn.classList.remove('bg-gray-100', 'text-gray-600');
                btn.classList.add('bg-gray-800', 'text-white');
            } else {
                btn.classList.remove('bg-gray-800', 'text-white');
                btn.classList.add('bg-gray-100', 'text-gray-600');
            }
        });
    }

    // Dates
    if (phpDateFrom) document.getElementById('search-date-from').value = phpDateFrom;
    if (phpDateTo) document.getElementById('search-date-to').value = phpDateTo;

    // Ouvrir le panneau si des options sont actives
    if ((phpScope && phpScope !== 'all') || phpDateFrom || phpDateTo) {
        document.getElementById('search-options-panel')?.classList.remove('hidden');
    }
})();

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

    // Mettre à jour le gestionnaire de clic et les data attributes
    if (doc.id) {
        card.dataset.docId = doc.id;
        const index = card.dataset.docIndex || 0;
        card.onclick = function() { openDocumentPreview(doc.id, parseInt(index)); };
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

    let docIndex = 0;
    documents.forEach(doc => {
        const isPhysical = doc.is_physical || doc.status === 'not_indexed';
        const title = doc.title || doc.filename || 'Sans titre';
        const date = doc.created_at ? new Date(doc.created_at).toLocaleDateString('fr-CH') : '';
        const thumbnailUrl = doc.thumbnail_url || (doc.id ? `${BASE_PATH}/documents/${doc.id}/thumbnail` : '');
        const clickHandler = doc.id ? `onclick="openDocumentPreview(${doc.id}, ${docIndex})"` : '';

        html += `
            <div ${clickHandler}
               data-doc-id="${doc.id || ''}"
               data-doc-index="${docIndex}"
               class="document-card bg-white border border-gray-100 rounded hover:border-gray-200 hover:shadow-sm transition-all block relative cursor-pointer ${isPhysical ? 'opacity-75' : ''}">
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
            </div>
        `;
        docIndex++;
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

// Redimensionnement de la sidebar avec limites basées sur le contenu
(function() {
    const sidebar = document.getElementById('documents-sidebar');
    const resizeHandle = document.getElementById('sidebar-resize-handle');

    if (!sidebar || !resizeHandle) return;

    const MIN_WIDTH = 180; // Largeur minimum
    const MAX_WIDTH_RATIO = 0.4; // Maximum 40% de la fenêtre

    // Calculer la largeur maximale nécessaire pour le contenu
    function calculateMaxContentWidth() {
        let maxWidth = MIN_WIDTH;

        // Mesurer tous les éléments texte dans la sidebar
        const textElements = sidebar.querySelectorAll('a, span, h2');
        const measureDiv = document.createElement('div');
        measureDiv.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;font:inherit;padding:0 8px;';
        document.body.appendChild(measureDiv);

        textElements.forEach(el => {
            // Calculer l'indentation (pour les dossiers imbriqués)
            let indent = 0;
            const paddingLeft = window.getComputedStyle(el).paddingLeft;
            if (paddingLeft) indent = parseInt(paddingLeft) || 0;

            // Mesurer le texte
            measureDiv.style.font = window.getComputedStyle(el).font;
            measureDiv.textContent = el.textContent;
            const textWidth = measureDiv.offsetWidth + indent + 40; // +40 pour icônes et marges

            if (textWidth > maxWidth) {
                maxWidth = textWidth;
            }
        });

        document.body.removeChild(measureDiv);

        // Limiter au ratio max de la fenêtre
        const windowMaxWidth = window.innerWidth * MAX_WIDTH_RATIO;
        return Math.min(Math.max(maxWidth, MIN_WIDTH + 50), windowMaxWidth);
    }

    let maxContentWidth = calculateMaxContentWidth();

    // Recalculer après chargement complet
    window.addEventListener('load', () => {
        maxContentWidth = calculateMaxContentWidth();
    });

    // Charger la largeur sauvegardée depuis localStorage
    const savedWidth = localStorage.getItem('documents-sidebar-width');
    if (savedWidth) {
        const width = parseInt(savedWidth);
        if (width >= MIN_WIDTH && width <= maxContentWidth) {
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
        maxContentWidth = calculateMaxContentWidth(); // Recalculer à chaque resize

        document.body.style.userSelect = 'none';
        document.body.classList.add('sidebar-resizing');
        sidebar.style.transition = 'none'; // Désactiver les transitions pendant le drag

        // Ajouter une classe pour le feedback visuel
        resizeHandle.classList.add('resizing');
        e.preventDefault();
    });

    document.addEventListener('mousemove', function(e) {
        if (!isResizing) return;

        const diff = e.clientX - startX;
        // Limiter entre MIN_WIDTH et maxContentWidth
        const newWidth = Math.min(Math.max(MIN_WIDTH, startWidth + diff), maxContentWidth);

        sidebar.style.width = newWidth + 'px';

        // Feedback visuel si on atteint les limites
        if (newWidth <= MIN_WIDTH) {
            sidebar.style.boxShadow = 'inset -2px 0 0 #ef4444'; // Rouge si min
        } else if (newWidth >= maxContentWidth) {
            sidebar.style.boxShadow = 'inset -2px 0 0 #22c55e'; // Vert si max
        } else {
            sidebar.style.boxShadow = '';
        }
    });

    document.addEventListener('mouseup', function() {
        if (isResizing) {
            isResizing = false;
            document.body.style.userSelect = '';
            document.body.classList.remove('sidebar-resizing');
            sidebar.style.transition = '';
            sidebar.style.boxShadow = '';
            resizeHandle.classList.remove('resizing');

            // Sauvegarder la largeur dans localStorage
            localStorage.setItem('documents-sidebar-width', sidebar.offsetWidth.toString());
        }
    });

    // Double-clic pour ajuster automatiquement à la largeur optimale
    resizeHandle.addEventListener('dblclick', function() {
        maxContentWidth = calculateMaxContentWidth();
        sidebar.style.transition = 'width 0.2s ease';
        sidebar.style.width = maxContentWidth + 'px';
        localStorage.setItem('documents-sidebar-width', maxContentWidth.toString());
        setTimeout(() => sidebar.style.transition = '', 200);
    });

    // Tooltip avec la largeur actuelle pendant le drag
    let widthTooltip = null;

    function showWidthTooltip(width, x, y) {
        if (!widthTooltip) {
            widthTooltip = document.createElement('div');
            widthTooltip.className = 'fixed bg-gray-800 text-white text-xs px-2 py-1 rounded shadow-lg z-50 pointer-events-none';
            document.body.appendChild(widthTooltip);
        }
        widthTooltip.textContent = `${width}px`;
        widthTooltip.style.left = (x + 10) + 'px';
        widthTooltip.style.top = (y - 20) + 'px';
        widthTooltip.style.display = 'block';
    }

    function hideWidthTooltip() {
        if (widthTooltip) {
            widthTooltip.style.display = 'none';
        }
    }

    // Modifier le mousemove pour afficher le tooltip
    const originalMouseMove = document.onmousemove;
    document.addEventListener('mousemove', function(e) {
        if (isResizing) {
            showWidthTooltip(sidebar.offsetWidth, e.clientX, e.clientY);
        }
    });

    // Cacher le tooltip au mouseup
    document.addEventListener('mouseup', function() {
        hideWidthTooltip();
    });
})();

// ===== DRAG & DROP DE FICHIERS =====
(function() {
    // Extensions autorisées
    const allowedExtensions = <?= json_encode(Config::get('storage.allowed_extensions', ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'doc', 'docx'])) ?>;

    // Variable pour stocker le dossier courant
    let currentDropFolder = '';

    // Récupérer le dossier courant depuis l'URL
    function getCurrentFolder() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('path') || '';
    }

    // Créer l'overlay de drop
    function createDropOverlay() {
        if (document.getElementById('drop-overlay')) return;

        const overlay = document.createElement('div');
        overlay.id = 'drop-overlay';
        overlay.className = 'fixed inset-0 bg-blue-500/20 backdrop-blur-sm z-40 pointer-events-none hidden';
        overlay.innerHTML = `
            <div class="absolute inset-4 border-4 border-dashed border-blue-500 rounded-2xl flex items-center justify-center">
                <div class="text-center bg-white/90 rounded-xl p-8 shadow-2xl">
                    <svg class="w-16 h-16 mx-auto mb-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="text-xl font-semibold text-gray-800" id="drop-overlay-text">Déposez vos fichiers ici</p>
                    <p class="text-sm text-gray-500 mt-2" id="drop-overlay-folder"></p>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    // Afficher l'overlay
    function showDropOverlay(folderPath) {
        const overlay = document.getElementById('drop-overlay');
        const folderText = document.getElementById('drop-overlay-folder');
        if (overlay) {
            overlay.classList.remove('hidden');
            if (folderText) {
                folderText.textContent = folderPath ? `Dossier: ${folderPath}` : 'Dossier racine';
            }
        }
    }

    // Cacher l'overlay
    function hideDropOverlay() {
        const overlay = document.getElementById('drop-overlay');
        if (overlay) {
            overlay.classList.add('hidden');
        }
    }

    // Créer l'overlay de progression
    function createProgressOverlay() {
        if (document.getElementById('upload-progress-overlay')) return;

        const overlay = document.createElement('div');
        overlay.id = 'upload-progress-overlay';
        overlay.className = 'fixed bottom-4 right-4 bg-white rounded-lg shadow-2xl p-4 z-50 min-w-80 hidden';
        overlay.innerHTML = `
            <div class="flex items-center justify-between mb-2">
                <span class="font-medium text-gray-800">Upload en cours...</span>
                <button onclick="this.parentElement.parentElement.classList.add('hidden')" class="text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <div id="upload-progress-list" class="space-y-2 max-h-48 overflow-y-auto"></div>
        `;
        document.body.appendChild(overlay);
    }

    // Ajouter un fichier à la liste de progression
    function addFileProgress(filename, status = 'pending') {
        const list = document.getElementById('upload-progress-list');
        if (!list) return;

        const id = 'upload-' + btoa(filename).replace(/[^a-zA-Z0-9]/g, '').substring(0, 16);
        let item = document.getElementById(id);

        if (!item) {
            item = document.createElement('div');
            item.id = id;
            item.className = 'flex items-center gap-2 text-sm';
            list.appendChild(item);
        }

        const icons = {
            pending: '<svg class="w-4 h-4 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>',
            success: '<svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
            error: '<svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>'
        };

        item.innerHTML = `${icons[status] || icons.pending}<span class="truncate flex-1">${filename}</span>`;
        return item;
    }

    // Upload d'un fichier
    async function uploadFile(file, folder) {
        const formData = new FormData();
        formData.append('files[]', file);
        formData.append('folder', folder);

        try {
            const response = await fetch(`${BASE_PATH}/api/documents/upload`, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            return result;
        } catch (error) {
            console.error('Upload error:', error);
            return { success: false, error: error.message };
        }
    }

    // Gérer le drop de fichiers
    async function handleFileDrop(files, targetFolder) {
        if (!files || files.length === 0) return;

        // Afficher l'overlay de progression
        const progressOverlay = document.getElementById('upload-progress-overlay');
        if (progressOverlay) {
            progressOverlay.classList.remove('hidden');
            document.getElementById('upload-progress-list').innerHTML = '';
        }

        const results = [];

        for (const file of files) {
            // Vérifier l'extension
            const ext = file.name.split('.').pop().toLowerCase();
            if (!allowedExtensions.includes(ext)) {
                addFileProgress(file.name, 'error');
                showNotification(`${file.name}: Extension non autorisée`, 'error');
                continue;
            }

            // Ajouter à la liste avec statut pending
            addFileProgress(file.name, 'pending');

            // Upload
            const result = await uploadFile(file, targetFolder);

            if (result.success && result.results) {
                const fileResult = result.results[0];
                if (fileResult.success) {
                    addFileProgress(file.name, 'success');
                    results.push(fileResult);
                } else {
                    addFileProgress(file.name, 'error');
                    showNotification(`${file.name}: ${fileResult.error}`, 'error');
                }
            } else {
                addFileProgress(file.name, 'error');
                showNotification(`${file.name}: ${result.error || 'Erreur inconnue'}`, 'error');
            }
        }

        // Notification de succès
        if (results.length > 0) {
            showNotification(`${results.length} fichier(s) uploadé(s) avec succès`, 'success');

            // Recharger les documents du dossier courant
            const currentFolder = getCurrentFolder();
            if (currentFolder === targetFolder || (!currentFolder && !targetFolder)) {
                setTimeout(() => loadFolderDocuments(targetFolder, false), 500);
            }
        }

        // Masquer l'overlay de progression après 3 secondes si tout est OK
        if (results.length === files.length) {
            setTimeout(() => {
                if (progressOverlay) progressOverlay.classList.add('hidden');
            }, 3000);
        }
    }

    // Initialiser les overlays
    createDropOverlay();
    createProgressOverlay();

    // Compteur pour gérer les événements dragenter/dragleave imbriqués
    let dragCounter = 0;

    // Event listeners sur la zone principale (main)
    const mainArea = document.querySelector('main');
    if (mainArea) {
        mainArea.addEventListener('dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dragCounter++;
            if (dragCounter === 1) {
                currentDropFolder = getCurrentFolder();
                showDropOverlay(currentDropFolder);
            }
        });

        mainArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dragCounter--;
            if (dragCounter === 0) {
                hideDropOverlay();
            }
        });

        mainArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
        });

        mainArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dragCounter = 0;
            hideDropOverlay();

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileDrop(Array.from(files), currentDropFolder);
            }
        });
    }

    // Event listeners sur les dossiers de la sidebar
    function setupFolderDropZones() {
        // Dossiers filesystem
        document.querySelectorAll('.folder-link[data-path]').forEach(folderLink => {
            const folderPath = folderLink.dataset.path || '';

            folderLink.addEventListener('dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('bg-blue-100', 'ring-2', 'ring-blue-400');
            });

            folderLink.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('bg-blue-100', 'ring-2', 'ring-blue-400');
            });

            folderLink.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.dataTransfer.dropEffect = 'copy';
            });

            folderLink.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('bg-blue-100', 'ring-2', 'ring-blue-400');
                hideDropOverlay();
                dragCounter = 0;

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    handleFileDrop(Array.from(files), folderPath);
                }
            });
        });
    }

    // Observer pour les dossiers chargés dynamiquement
    const sidebarObserver = new MutationObserver(() => {
        setupFolderDropZones();
    });

    const sidebar = document.getElementById('documents-sidebar');
    if (sidebar) {
        sidebarObserver.observe(sidebar, { childList: true, subtree: true });
    }

    // Setup initial
    setupFolderDropZones();

    // Empêcher le comportement par défaut du navigateur (ouvrir le fichier)
    document.addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    document.addEventListener('drop', function(e) {
        e.preventDefault();
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
}

.folder-toggle {
    white-space: nowrap; /* Empêche le retour à la ligne */
    width: 100%;
}

.folder-link {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Les noms de dossiers dans la sidebar */
#documents-sidebar nav a,
#documents-sidebar .folder-link {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Permettre le scroll horizontal si contenu dépasse */
#documents-sidebar {
    overflow-x: auto;
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

/* Poignée de redimensionnement de la sidebar */
#sidebar-resize-handle {
    touch-action: none;
}

#sidebar-resize-handle:hover,
#sidebar-resize-handle.resizing {
    background-color: rgba(59, 130, 246, 0.1);
}

#sidebar-resize-handle.resizing > div:first-child {
    background-color: #3b82f6 !important;
}

#sidebar-resize-handle.resizing > div:last-child {
    opacity: 1 !important;
    background-color: #3b82f6 !important;
}

/* Curseur de redimensionnement global pendant le drag */
body.sidebar-resizing,
body.sidebar-resizing * {
    cursor: col-resize !important;
}

/* Transition douce pour la sidebar */
#documents-sidebar {
    transition: box-shadow 0.2s;
}

#documents-sidebar:has(+ main) {
    /* Ombre subtile quand survolée */
}

/* Styles pour le drag & drop */
.folder-link.drag-over,
.folder-link[data-path].drag-over {
    background-color: rgba(59, 130, 246, 0.1) !important;
    box-shadow: inset 0 0 0 2px #3b82f6;
}

/* Zone de drop principale */
#drop-overlay {
    transition: opacity 0.2s;
}

#drop-overlay.hidden {
    opacity: 0;
    pointer-events: none;
}

/* Animation de pulsation pour l'overlay */
#drop-overlay > div {
    animation: dropPulse 1.5s ease-in-out infinite;
}

@keyframes dropPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.01); }
}

/* Overlay de progression des uploads */
#upload-progress-overlay {
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateY(20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Indicateur de drop sur les dossiers */
.folder-link {
    transition: background-color 0.15s, box-shadow 0.15s;
}
</style>
