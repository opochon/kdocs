<?php
// Vue grille style Paperless-ngx
// $documents, $logicalFolders, $fsFolders, $documentTypes, $tags, $search, $logicalFolderId, $folderId, $typeId, $currentFolder sont passés
use KDocs\Core\Config;
use KDocs\Models\LogicalFolder;
$base = Config::basePath();
?>

<div class="flex min-h-screen bg-gray-100" style="margin: -1rem -1rem;">
    
    <!-- Sidebar gauche - Dossiers -->
    <aside class="w-64 bg-white border-r border-gray-200 overflow-y-auto">
        <!-- Dossiers logiques -->
        <?php if (!empty($logicalFolders)): ?>
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Dossiers logiques</h2>
        </div>
        <nav class="p-2">
            <?php foreach ($logicalFolders as $lfolder): ?>
            <?php 
            $isActive = ($logicalFolderId == $lfolder['id']);
            $count = LogicalFolder::countDocuments($lfolder['id']);
            ?>
            <a href="<?= url('/documents?logical_folder=' . $lfolder['id']) ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100 mb-1
                      <?= $isActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700' ?>"
               style="<?= $isActive && !empty($lfolder['color']) ? 'border-left: 3px solid ' . htmlspecialchars($lfolder['color']) : '' ?>">
                <span class="mr-2"><?= htmlspecialchars($lfolder['icon'] ?: '📁') ?></span>
                <span class="flex-1"><?= htmlspecialchars($lfolder['name']) ?></span>
                <span class="text-xs text-gray-400"><?= $count ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <!-- Dossiers filesystem - Arborescence dynamique -->
        <?php if (!empty($fsFolders) || $folderId): ?>
        <div class="p-4 border-t border-b border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Dossiers</h2>
        </div>
        <nav class="p-2">
            <?php if ($folderId): ?>
            <!-- Bouton retour si on est dans un sous-dossier -->
            <?php
            // Utiliser currentFolderPath depuis le contrôleur si disponible
            $currentPath = $currentFolderPath ?? null;
            
            // Sinon, trouver le chemin depuis fsFolders
            if (!$currentPath) {
                foreach ($fsFolders as $f) {
                    if ($f['id'] === $folderId) {
                        $currentPath = $f['path'];
                        break;
                    }
                }
            }
            
            // Calculer le chemin parent
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
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100 mb-1 text-gray-700">
                <span class="mr-2">←</span>
                <span class="flex-1">Retour</span>
            </a>
            <?php else: ?>
            <a href="<?= url('/documents') ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100 mb-1 text-gray-700">
                <span class="mr-2">←</span>
                <span class="flex-1">Retour à la racine</span>
            </a>
            <?php endif; ?>
            <?php else: ?>
            <!-- Vue racine -->
            <a href="<?= url('/documents') ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100 mb-1
                      <?= (!$currentFolder && !$logicalFolderId) ? 'bg-blue-50 text-blue-700' : 'text-gray-700' ?>">
                <span class="mr-2">📁</span>
                <span class="flex-1">Tous les documents</span>
            </a>
            <?php endif; ?>
            
            <?php foreach ($fsFolders as $folder): ?>
            <a href="<?= url('/documents?folder=' . urlencode($folder['id'])) ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100
                      <?= ($folderId == $folder['id']) ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700' ?>">
                <span class="mr-2"><?= ($folder['is_root'] ?? false) ? '📁' : '📂' ?></span>
                <span class="flex-1"><?= htmlspecialchars($folder['name']) ?></span>
                <?php if (($folder['file_count'] ?? 0) > 0): ?>
                <span class="text-xs text-gray-400 ml-2"><?= $folder['file_count'] ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <?php if (!empty($documentTypes)): ?>
        <div class="p-4 border-t border-b border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Types</h2>
        </div>
        <nav class="p-2">
            <a href="<?= url('/documents') ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100 mb-1
                      <?= (!$typeId) ? 'bg-blue-50 text-blue-700' : 'text-gray-700' ?>">
                Tous les types
            </a>
            <?php foreach ($documentTypes as $type): ?>
            <a href="<?= url('/documents?type=' . $type['id']) ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100">
                <span class="flex-1"><?= htmlspecialchars($type['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <?php if (!empty($correspondents)): ?>
        <div class="p-4 border-t border-b border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Correspondants</h2>
        </div>
        <nav class="p-2">
            <?php foreach ($correspondents as $corr): ?>
            <a href="<?= url('/documents?correspondent=' . $corr['id']) ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100 mb-1">
                <span class="flex-1"><?= htmlspecialchars($corr['name']) ?></span>
                <span class="text-xs text-gray-400"><?= $corr['doc_count'] ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <?php if (!empty($tags)): ?>
        <div class="p-4 border-t border-b border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Tags</h2>
        </div>
        <nav class="p-2">
            <?php foreach ($tags as $tag): ?>
            <a href="<?= url('/documents?tag=' . $tag['id']) ?>" 
               class="inline-block px-2 py-1 m-1 text-xs rounded-full hover:opacity-80 cursor-pointer"
               style="background-color: <?= htmlspecialchars($tag['color'] ?? '#3b82f6') ?>20; color: <?= htmlspecialchars($tag['color'] ?? '#3b82f6') ?>">
                <?= htmlspecialchars($tag['name']) ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        
        <!-- Storage Paths (Phase 2.2) -->
        <?php if (!empty($storagePaths)): ?>
        <div class="p-4 border-t border-b border-gray-200">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Chemins de stockage</h2>
        </div>
        <nav class="p-2">
            <?php foreach ($storagePaths as $spath): ?>
            <?php
            // Compter les documents avec ce storage path
            try {
                $db = \KDocs\Core\Database::getInstance();
                $countStmt = $db->prepare("SELECT COUNT(*) FROM documents WHERE storage_path_id = ? AND deleted_at IS NULL");
                $countStmt->execute([$spath['id']]);
                $docCount = $countStmt->fetchColumn();
            } catch (\Exception $e) {
                $docCount = 0;
            }
            ?>
            <a href="<?= url('/documents?storage_path=' . $spath['id']) ?>" 
               class="flex items-center px-3 py-2 text-sm rounded-lg hover:bg-gray-100 mb-1 text-gray-700">
                <span class="mr-2">📦</span>
                <span class="flex-1 font-mono text-xs"><?= htmlspecialchars($spath['path']) ?></span>
                <span class="text-xs text-gray-400"><?= $docCount ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </aside>
    
    <!-- Zone principale -->
    <main class="flex-1 overflow-y-auto" style="min-width: 0;">
        
        <!-- Header avec recherche et actions -->
        <header class="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <h1 class="text-lg font-semibold text-gray-900">Documents</h1>
                    <span class="text-sm text-gray-500"><?= $total ?> fichier<?= $total > 1 ? 's' : '' ?></span>
                </div>
                
                <div class="flex items-center space-x-4">
                    <!-- Tri (Priorité 1.1) -->
                    <div class="flex items-center space-x-2">
                        <label class="text-sm text-gray-600">Trier par:</label>
                        <select name="sort" 
                                onchange="updateSort(this.value, '<?= htmlspecialchars($order ?? 'DESC') ?>')"
                                class="px-3 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="created_at" <?= ($sort ?? 'created_at') === 'created_at' ? 'selected' : '' ?>>Date</option>
                            <option value="title" <?= ($sort ?? '') === 'title' ? 'selected' : '' ?>>Titre</option>
                            <option value="filename" <?= ($sort ?? '') === 'filename' ? 'selected' : '' ?>>Nom de fichier</option>
                            <option value="document_date" <?= ($sort ?? '') === 'document_date' ? 'selected' : '' ?>>Date document</option>
                            <option value="amount" <?= ($sort ?? '') === 'amount' ? 'selected' : '' ?>>Montant</option>
                        </select>
                        <button onclick="toggleOrder()" 
                                class="px-2 py-2 border rounded-lg hover:bg-gray-50"
                                title="<?= ($order ?? 'DESC') === 'DESC' ? 'Croissant' : 'Décroissant' ?>">
                            <?= ($order ?? 'DESC') === 'DESC' ? '↓' : '↑' ?>
                        </button>
                    </div>
                    
                    <!-- Recherche avec raccourci clavier et recherches sauvegardées (Priorité 3.2 + 3.6) -->
                    <div class="relative">
                        <form method="GET" action="<?= url('/documents') ?>" class="relative inline-block">
                            <input type="text" 
                                   id="search-input"
                                   name="search"
                                   value="<?= htmlspecialchars($search ?? '') ?>"
                                   placeholder="Rechercher... (Ctrl+K ou /)" 
                                   class="pl-10 pr-4 py-2 border rounded-lg w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   title="Raccourci: Ctrl+K ou /"
                                   onkeydown="if(event.key === 'Enter') this.form.submit()">
                            <svg class="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <button type="button" 
                                    onclick="openAdvancedSearch()" 
                                    class="px-3 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 ml-2"
                                    title="Recherche avancée">
                                🔍 Avancée
                            </button>
                        <?php if ($logicalFolderId): ?>
                            <input type="hidden" name="logical_folder" value="<?= $logicalFolderId ?>">
                        <?php endif; ?>
                        <?php if ($folderId): ?>
                            <input type="hidden" name="folder" value="<?= $folderId ?>">
                        <?php endif; ?>
                        <?php if ($typeId): ?>
                            <input type="hidden" name="type" value="<?= $typeId ?>">
                        <?php endif; ?>
                        <?php if (isset($correspondentId) && $correspondentId): ?>
                            <input type="hidden" name="correspondent" value="<?= $correspondentId ?>">
                        <?php endif; ?>
                        <?php if (isset($tagId) && $tagId): ?>
                            <input type="hidden" name="tag" value="<?= $tagId ?>">
                        <?php endif; ?>
                        <?php if (isset($sort)): ?>
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                        <?php endif; ?>
                        <?php if (isset($order)): ?>
                            <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
                        <?php endif; ?>
                        </form>
                        <!-- Bouton recherches sauvegardées (Priorité 3.2) -->
                        <button onclick="showSavedSearches()" class="ml-2 p-2 border border-gray-300 rounded-md hover:bg-gray-50 transition-colors" title="Recherches sauvegardées">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <!-- Vue liste/grille/tableau (Priorité 1.5 + 3.1) -->
                    <div class="flex items-center border border-gray-300 rounded-md overflow-hidden">
                        <button onclick="setViewMode('grid')" 
                                id="view-grid"
                                class="px-3 py-2 border-r border-gray-300 hover:bg-gray-50 view-toggle active bg-blue-50 text-blue-700 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                            </svg>
                        </button>
                        <button onclick="setViewMode('list')" 
                                id="view-list"
                                class="px-3 py-2 border-r border-gray-300 hover:bg-gray-50 view-toggle text-gray-600 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                            </svg>
                        </button>
                        <button onclick="setViewMode('table')" 
                                id="view-table"
                                class="px-3 py-2 hover:bg-gray-50 view-toggle text-gray-600 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <a href="<?= url('/documents/upload') ?>" class="flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm font-medium transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                        </svg>
                        Uploader
                    </a>
                </div>
            </div>
        </header>
        
        <!-- Barre d'actions groupées (Priorité 1.3) -->
        <div id="bulk-actions" class="hidden bg-blue-50 border-b border-blue-200 px-6 py-3 flex items-center justify-between" style="display: none !important;">
            <span id="selected-count" class="font-medium text-blue-800">0 document(s) sélectionné(s)</span>
            <div class="flex items-center gap-2">
                <select id="bulk-action-select" class="px-3 py-1 border border-gray-300 rounded text-sm">
                    <option value="">-- Action --</option>
                    <option value="add_tag">Ajouter un tag</option>
                    <option value="remove_tag">Retirer un tag</option>
                    <option value="set_type">Définir le type</option>
                    <option value="set_correspondent">Définir le correspondant</option>
                    <option value="delete">Supprimer</option>
                </select>
                <button onclick="executeBulkAction()" class="px-4 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                    Appliquer
                </button>
                <button onclick="clearSelection()" class="px-4 py-1 border border-gray-300 rounded text-sm hover:bg-gray-50">
                    Annuler
                </button>
            </div>
        </div>
        
        <!-- Zone de drop -->
        <div id="drop-zone" 
             class="hidden fixed inset-0 bg-blue-500 bg-opacity-20 z-50 flex items-center justify-center">
            <div class="bg-white p-8 rounded-xl shadow-2xl text-center">
                <div class="text-6xl mb-4">📄</div>
                <p class="text-xl font-semibold">Déposez vos fichiers ici</p>
            </div>
        </div>
        
        <?php if (empty($documents)): ?>
            <div class="p-12 text-center">
                <p class="text-gray-500 text-lg mb-4">Aucun document trouvé</p>
                <p class="text-sm text-gray-400 mb-4">
                    L'indexation du filesystem se fait automatiquement.<br>
                    Les nouveaux fichiers seront détectés dans l'heure.
                </p>
                <a href="<?= url('/documents/upload') ?>" class="inline-flex items-center px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm font-medium transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Uploader un document
                </a>
            </div>
        <?php else: ?>
            <!-- Vue grille (par défaut) -->
            <div id="view-grid-container">
                <?php foreach ($documents as $doc): ?>
                <div class="document-card bg-white rounded-lg shadow hover:shadow-lg transition-shadow relative
                     <?= !empty($doc['id']) ? 'cursor-pointer' : '' ?>"
                     <?php if (!empty($doc['id'])): ?>
                     onclick="if (!event.target.closest('.checkbox-wrapper')) openDocument(<?= $doc['id'] ?>)"
                     <?php endif; ?>>
                    
                    <!-- Checkbox de sélection (Priorité 1.3) -->
                    <?php if (!empty($doc['id'])): ?>
                    <div class="checkbox-wrapper absolute top-2 left-2 z-10">
                        <input type="checkbox" 
                               class="document-checkbox w-5 h-5 cursor-pointer"
                               value="<?= $doc['id'] ?>"
                               onchange="updateBulkActions()">
                    </div>
                    <?php endif; ?>
                    
                    <!-- Badge modifié/non indexé -->
                    <?php if (!empty($doc['_modified'])): ?>
                    <span class="absolute top-2 right-2 px-2 py-1 bg-yellow-500 text-white text-xs rounded z-10" title="Fichier modifié">
                        ⚠ Modifié
                    </span>
                    <?php elseif (!empty($doc['_not_indexed'])): ?>
                    <span class="absolute top-2 right-2 px-2 py-1 bg-gray-500 text-white text-xs rounded z-10" title="Non indexé">
                        📋 Non indexé
                    </span>
                    <?php endif; ?>
                    
                    <!-- Miniature -->
                    <div class="bg-gray-100 rounded-t-lg overflow-hidden relative" style="aspect-ratio: 3/4; width: 100%;">
                        <?php 
                        $thumbnailUrl = null;
                        if (!empty($doc['thumbnail_path'] ?? null)) {
                            // Utiliser le chemin depuis la config
                            $config = \KDocs\Core\Config::load();
                            $thumbBasePath = $config['storage']['thumbnails'] ?? __DIR__ . '/../../storage/thumbnails';
                            $thumbBasePath = realpath($thumbBasePath) ?: $thumbBasePath;
                            $thumbnailFile = $thumbBasePath . DIRECTORY_SEPARATOR . basename($doc['thumbnail_path']);
                            
                            if (file_exists($thumbnailFile)) {
                                $thumbnailUrl = '/kdocs/storage/thumbnails/' . basename($doc['thumbnail_path']);
                            }
                        }
                        ?>
                        <?php if ($thumbnailUrl): ?>
                        <img src="<?= htmlspecialchars($thumbnailUrl) ?>" 
                             alt="" 
                             class="w-full h-full object-cover"
                             onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center text-4xl text-gray-300\'>📄</div>'">
                        <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-4xl text-gray-300 bg-gray-50">
                            <?php
                            // Afficher une icône selon le type de fichier
                            $ext = strtolower(pathinfo($doc['filename'] ?? '', PATHINFO_EXTENSION));
                            if ($ext === 'pdf') {
                                echo '📕';
                            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                echo '🖼️';
                            } elseif (in_array($ext, ['doc', 'docx'])) {
                                echo '📘';
                            } else {
                                echo '📄';
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Type badge -->
                        <?php if (!empty($doc['document_type_label'])): ?>
                        <span class="absolute bottom-2 left-2 px-2 py-0.5 bg-blue-600 text-white text-xs rounded">
                            <?= htmlspecialchars($doc['document_type_label']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Infos -->
                    <div class="p-2 text-xs">
                        <h3 class="font-medium text-xs truncate" title="<?= htmlspecialchars($doc['title'] ?? $doc['original_filename'] ?? $doc['filename'] ?? 'Sans titre') ?>">
                            <?= htmlspecialchars($doc['title'] ?? $doc['original_filename'] ?? $doc['filename'] ?? 'Sans titre') ?>
                        </h3>
                        <div class="flex items-center justify-between mt-1 text-xs text-gray-500">
                            <span><?= htmlspecialchars($doc['correspondent_name'] ?? '-') ?></span>
                            <span><?= !empty($doc['created_at']) ? date('d/m/Y', strtotime($doc['created_at'])) : '-' ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 flex items-center justify-between border-t">
                <div class="text-sm text-gray-700">
                    Page <?= $page ?> sur <?= $totalPages ?>
                </div>
                <div class="flex space-x-2">
                    <?php
                    $queryString = '';
                    if ($search) $queryString .= '&search=' . urlencode($search);
                    if ($logicalFolderId) $queryString .= '&logical_folder=' . $logicalFolderId;
                    if ($folderId) $queryString .= '&folder=' . $folderId;
                    if ($typeId) $queryString .= '&type=' . $typeId;
                    if (isset($sort)) $queryString .= '&sort=' . urlencode($sort);
                    if (isset($order)) $queryString .= '&order=' . urlencode($order);
                    ?>
                    <?php if ($page > 1): ?>
                        <a href="<?= url('/documents?page=' . ($page - 1) . $queryString) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Précédent
                        </a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= url('/documents?page=' . ($page + 1) . $queryString) ?>" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                            Suivant
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- Vue liste (Priorité 1.5) -->
        <?php if (!empty($documents)): ?>
        <div id="view-list-container" class="hidden p-6" style="display: none;">
            <table class="w-full bg-white rounded-lg shadow">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                            <input type="checkbox" onchange="toggleAll(this)" class="w-4 h-4">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taille</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($documents as $doc): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <?php if (!empty($doc['id'])): ?>
                            <input type="checkbox" 
                                   class="document-checkbox w-4 h-4"
                                   value="<?= $doc['id'] ?>"
                                   onchange="updateBulkActions()">
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center">
                                <div class="text-2xl mr-3">📄</div>
                                <div>
                                    <div class="font-medium text-gray-900">
                                        <?= htmlspecialchars($doc['title'] ?? $doc['original_filename'] ?? $doc['filename'] ?? 'Sans titre') ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?= htmlspecialchars($doc['original_filename'] ?? $doc['filename'] ?? '') ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?= htmlspecialchars($doc['document_type_label'] ?? '-') ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?= $doc['document_date'] ?? ($doc['created_at'] ? date('d.m.Y', strtotime($doc['created_at'])) : '-') ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?= $doc['file_size'] ? number_format($doc['file_size'] / 1024, 1) . ' KB' : '-' ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if (!empty($doc['id'])): ?>
                            <a href="<?= url('/documents/' . $doc['id']) ?>" 
                               class="text-blue-600 hover:text-blue-800 text-sm">Voir</a>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">Non indexé</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Vue tableau détaillée (Priorité 3.1) -->
        <?php if (!empty($documents)): ?>
        <div id="view-table-container" class="hidden p-6" style="display: none;">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <input type="checkbox" onchange="toggleAll(this)" class="w-4 h-4">
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Correspondant</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date document</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taille</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Créé le</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tags</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($documents as $doc): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <?php if (!empty($doc['id'])): ?>
                                    <input type="checkbox" 
                                           class="document-checkbox w-4 h-4"
                                           value="<?= $doc['id'] ?>"
                                           onchange="updateBulkActions()">
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center">
                                        <?php 
                                        $thumbnailUrl = null;
                                        if (!empty($doc['thumbnail_path'])) {
                                            // Utiliser le chemin depuis la config
                                            $config = \KDocs\Core\Config::load();
                                            $thumbBasePath = $config['storage']['thumbnails'] ?? __DIR__ . '/../../storage/thumbnails';
                                            $thumbBasePath = realpath($thumbBasePath) ?: $thumbBasePath;
                                            $thumbnailFile = $thumbBasePath . DIRECTORY_SEPARATOR . basename($doc['thumbnail_path']);
                                            
                                            if (file_exists($thumbnailFile)) {
                                                $thumbnailUrl = '/kdocs/storage/thumbnails/' . basename($doc['thumbnail_path']);
                                            }
                                        }
                                        ?>
                                        <?php if ($thumbnailUrl): ?>
                                        <img src="<?= htmlspecialchars($thumbnailUrl) ?>" 
                                             alt="" 
                                             class="w-10 h-14 object-cover rounded mr-3"
                                             onerror="this.style.display='none'">
                                        <?php else: ?>
                                        <div class="w-10 h-14 bg-gray-100 rounded mr-3 flex items-center justify-center text-gray-400 text-xs">
                                            <?php
                                            // Afficher une icône selon le type de fichier
                                            $ext = strtolower(pathinfo($doc['filename'] ?? '', PATHINFO_EXTENSION));
                                            if ($ext === 'pdf') {
                                                echo '📕';
                                            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                echo '🖼️';
                                            } elseif (in_array($ext, ['doc', 'docx'])) {
                                                echo '📘';
                                            } else {
                                                echo '📄';
                                            }
                                            ?>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-medium text-gray-900">
                                                <?php if (!empty($doc['id'])): ?>
                                                <a href="<?= url('/documents/' . $doc['id']) ?>" class="text-blue-600 hover:text-blue-800">
                                                    <?= htmlspecialchars($doc['title'] ?? $doc['original_filename'] ?? $doc['filename'] ?? 'Sans titre') ?>
                                                </a>
                                                <?php else: ?>
                                                <?= htmlspecialchars($doc['title'] ?? $doc['original_filename'] ?? $doc['filename'] ?? 'Sans titre') ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?= htmlspecialchars($doc['original_filename'] ?? $doc['filename'] ?? '') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?php if (!empty($doc['document_type_label'])): ?>
                                    <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">
                                        <?= htmlspecialchars($doc['document_type_label']) ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= htmlspecialchars($doc['correspondent_name'] ?? '-') ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= $doc['document_date'] ? date('d.m.Y', strtotime($doc['document_date'])) : ($doc['doc_date'] ? date('d.m.Y', strtotime($doc['doc_date'])) : '-') ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?php if (!empty($doc['amount'])): ?>
                                    <span class="font-medium"><?= number_format($doc['amount'], 2, ',', ' ') ?></span>
                                    <span class="text-gray-400"><?= htmlspecialchars($doc['currency'] ?? 'EUR') ?></span>
                                    <?php else: ?>
                                    <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= $doc['file_size'] ? number_format($doc['file_size'] / 1024, 1) . ' KB' : '-' ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <?= $doc['created_at'] ? date('d.m.Y', strtotime($doc['created_at'])) : '-' ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php
                                    // Récupérer les tags pour ce document
                                    $docTags = [];
                                    if (!empty($doc['id'])) {
                                        try {
                                            $db = \KDocs\Core\Database::getInstance();
                                            $tagStmt = $db->prepare("SELECT t.name, t.color FROM tags t INNER JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ? LIMIT 3");
                                            $tagStmt->execute([$doc['id']]);
                                            $docTags = $tagStmt->fetchAll();
                                        } catch (\Exception $e) {}
                                    }
                                    ?>
                                    <?php if (!empty($docTags)): ?>
                                    <div class="flex flex-wrap gap-1">
                                        <?php foreach ($docTags as $tag): ?>
                                        <span class="px-2 py-0.5 text-xs rounded-full"
                                              style="background-color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>20; color: <?= htmlspecialchars($tag['color'] ?? '#6b7280') ?>">
                                            <?= htmlspecialchars($tag['name']) ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-gray-400 text-sm">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-medium">
                                    <?php if (!empty($doc['id'])): ?>
                                    <a href="<?= url('/documents/' . $doc['id']) ?>" class="text-blue-600 hover:text-blue-800 mr-3">Voir</a>
                                    <a href="<?= url('/documents/' . $doc['id'] . '/edit') ?>" class="text-gray-600 hover:text-gray-800">Modifier</a>
                                    <?php else: ?>
                                    <span class="text-gray-400">Non indexé</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modal Recherche Avancée -->
<?php include __DIR__ . '/advanced_search.php'; ?>

<!-- Modals Actions Groupées -->
<?php include __DIR__ . '/bulk_action_modals.php'; ?>

    <style>
        /* Grille adaptative pour les miniatures */
        /* Styles déplacés dans la section <style> principale */
        }
        
        .document-card {
            flex: 0 0 auto;
            width: calc((100% - 5rem) / 6);
            min-width: 140px;
            max-width: 180px;
        }
        
        @media (max-width: 1536px) {
            .document-card {
                width: calc((100% - 4rem) / 5);
            }
        }
        
        @media (max-width: 1280px) {
            .document-card {
                width: calc((100% - 3rem) / 4);
            }
        }
        
        @media (max-width: 1024px) {
            .document-card {
                width: calc((100% - 2rem) / 3);
            }
        }
        
        @media (max-width: 768px) {
            .document-card {
                width: calc((100% - 1rem) / 2);
                min-width: 120px;
            }
        }
        
        @media (max-width: 640px) {
            .document-card {
                width: 100%;
                max-width: 200px;
            }
        }
        
        .document-card .aspect-\[3\/4\] {
            aspect-ratio: 3/4;
            width: 100%;
        }
    </style>
    
    <script>
// Drag & Drop - Initialisation sécurisée
(function() {
    function initDragDrop() {
        const dropZone = document.getElementById('drop-zone');
        if (!dropZone) {
            setTimeout(initDragDrop, 100);
            return;
        }
        
        const body = document.body;
        
        ['dragenter', 'dragover'].forEach(event => {
            body.addEventListener(event, (e) => {
                e.preventDefault();
                if (dropZone) {
                    dropZone.classList.remove('hidden');
                }
            });
        });

        ['dragleave', 'drop'].forEach(event => {
            dropZone.addEventListener(event, (e) => {
                e.preventDefault();
                if (dropZone) {
                    dropZone.classList.add('hidden');
                }
            });
        });

        dropZone.addEventListener('drop', async (e) => {
            e.preventDefault();
            if (dropZone) {
                dropZone.classList.add('hidden');
            }
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                await uploadFiles(files);
            }
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDragDrop);
    } else {
        setTimeout(initDragDrop, 50);
    }
})();

async function uploadFiles(files) {
    const formData = new FormData();
    for (let file of files) {
        formData.append('files[]', file);
    }
    
    try {
        const response = await fetch('<?= url('/api/documents/upload') ?>', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`Upload réussi : ${result.results.filter(r => r.success).length} fichier(s)`);
            location.reload();
        } else {
            alert('Erreur lors de l\'upload : ' + (result.error || 'Erreur inconnue'));
        }
    } catch (error) {
        alert('Erreur lors de l\'upload : ' + error.message);
    }
}

function openDocument(id) {
    try {
        if (!id) {
            console.error('ID de document manquant');
            return;
        }
        window.location.href = '<?= url('/documents/') ?>' + id;
    } catch (error) {
        console.error('Erreur dans openDocument:', error);
    }
}

// Tri (Priorité 1.1)
function updateSort(newSort, currentOrder) {
    try {
        if (!newSort) return;
        const url = new URL(window.location.href);
        url.searchParams.set('sort', newSort);
        url.searchParams.set('order', currentOrder || 'desc');
        url.searchParams.set('page', '1'); // Reset à la page 1
        window.location.href = url.toString();
    } catch (error) {
        console.error('Erreur dans updateSort:', error);
    }
}

function toggleOrder() {
    try {
        const url = new URL(window.location.href);
        const currentOrder = url.searchParams.get('order') || 'desc';
        const newOrder = currentOrder.toLowerCase() === 'desc' ? 'asc' : 'desc';
        url.searchParams.set('order', newOrder);
        url.searchParams.set('page', '1'); // Reset à la page 1
        window.location.href = url.toString();
    } catch (error) {
        console.error('Erreur dans toggleOrder:', error);
    }
}

// Vue liste/grille/tableau (Priorité 1.5 + 3.1)
// Calcul dynamique du nombre de colonnes pour le damier
function calculateGridColumns() {
    try {
        const container = document.getElementById('view-grid-container');
        if (!container || container.classList.contains('hidden')) {
            return;
        }
        
        // Utiliser la largeur de la fenêtre pour déterminer le nombre de colonnes
        const windowWidth = window.innerWidth;
        const containerWidth = container.offsetWidth || windowWidth;
        const gap = 12; // 0.75rem = 12px
        const padding = 12; // 0.75rem = 12px
        
        // Déterminer le nombre de colonnes selon la largeur de la fenêtre
        let numColumns;
        if (windowWidth >= 1920) {
            numColumns = 4; // 4 colonnes pour écran 1920px+
        } else if (windowWidth >= 1280) {
            numColumns = 4; // 4 colonnes pour écran moyen-large
        } else if (windowWidth >= 1024) {
            numColumns = 3; // 3 colonnes pour écran moyen
        } else if (windowWidth >= 768) {
            numColumns = 3; // 3 colonnes pour tablette
        } else {
            numColumns = 2; // 2 colonnes pour mobile
        }
        
        // Calculer la largeur des cartes pour qu'elles soient lisibles
        const availableWidth = containerWidth - (padding * 2);
        const cardWidth = Math.max(200, (availableWidth - (gap * (numColumns - 1))) / numColumns);
        
        const cards = container.querySelectorAll('.document-card');
        if (cards.length > 0) {
            cards.forEach(card => {
                card.style.setProperty('width', cardWidth + 'px', 'important');
                card.style.setProperty('max-width', cardWidth + 'px', 'important');
                card.style.setProperty('min-width', '200px', 'important');
                card.style.setProperty('flex-shrink', '0', 'important');
                card.style.setProperty('flex-grow', '0', 'important');
            });
        }
    } catch (error) {
        console.error('Erreur dans calculateGridColumns:', error);
    }
}

// Recalculer lors du redimensionnement
let resizeTimeout;
window.addEventListener('resize', function() {
    try {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            const gridContainer = document.getElementById('view-grid-container');
            if (gridContainer && !gridContainer.classList.contains('hidden')) {
                calculateGridColumns();
            }
        }, 100);
    } catch (error) {
        console.error('Erreur dans le gestionnaire de resize:', error);
    }
});

function setViewMode(mode) {
    try {
        const gridContainer = document.getElementById('view-grid-container');
        const listContainer = document.getElementById('view-list-container');
        const tableContainer = document.getElementById('view-table-container');
        const gridBtn = document.getElementById('view-grid');
        const listBtn = document.getElementById('view-list');
        const tableBtn = document.getElementById('view-table');
        
        if (!gridContainer || !listContainer || !tableContainer) {
            console.warn('Conteneurs de vue non trouvés, réessai dans 100ms');
            setTimeout(() => setViewMode(mode), 100);
            return;
        }
        
        // Masquer tous les conteneurs avec style inline pour forcer
        gridContainer.classList.add('hidden');
        gridContainer.style.display = 'none';
        listContainer.classList.add('hidden');
        listContainer.style.display = 'none';
        tableContainer.classList.add('hidden');
        tableContainer.style.display = 'none';
        
        // Réinitialiser tous les boutons s'ils existent
        if (gridBtn) {
            gridBtn.classList.remove('active', 'bg-blue-100', 'text-blue-700');
            gridBtn.classList.add('hover:bg-gray-50', 'text-gray-600');
        }
        if (listBtn) {
            listBtn.classList.remove('active', 'bg-blue-100', 'text-blue-700');
            listBtn.classList.add('hover:bg-gray-50', 'text-gray-600');
        }
        if (tableBtn) {
            tableBtn.classList.remove('active', 'bg-blue-100', 'text-blue-700');
            tableBtn.classList.add('hover:bg-gray-50', 'text-gray-600');
        }
        
        // Afficher le conteneur sélectionné et activer le bouton
        if (mode === 'grid') {
            gridContainer.classList.remove('hidden');
            gridContainer.style.display = 'flex';
            if (gridBtn) {
                gridBtn.classList.add('active', 'bg-blue-100', 'text-blue-700');
                gridBtn.classList.remove('hover:bg-gray-50', 'text-gray-600');
            }
            
            // Recalculer les colonnes après affichage
            setTimeout(calculateGridColumns, 100);
        } else if (mode === 'list') {
            listContainer.classList.remove('hidden');
            listContainer.style.display = 'block';
            if (listBtn) {
                listBtn.classList.add('active', 'bg-blue-100', 'text-blue-700');
                listBtn.classList.remove('hover:bg-gray-50', 'text-gray-600');
            }
        } else if (mode === 'table') {
            tableContainer.classList.remove('hidden');
            tableContainer.style.display = 'block';
            if (tableBtn) {
                tableBtn.classList.add('active', 'bg-blue-100', 'text-blue-700');
                tableBtn.classList.remove('hover:bg-gray-50', 'text-gray-600');
            }
        }
        
        localStorage.setItem('documentViewMode', mode);
    } catch (error) {
        console.error('Erreur dans setViewMode:', error);
    }
}

// Restaurer la vue sauvegardée au chargement
(function() {
    let initAttempts = 0;
    const maxAttempts = 10;
    
    function initViewMode() {
        try {
            initAttempts++;
            const gridContainer = document.getElementById('view-grid-container');
            const listContainer = document.getElementById('view-list-container');
            const tableContainer = document.getElementById('view-table-container');
            const gridBtn = document.getElementById('view-grid');
            const listBtn = document.getElementById('view-list');
            const tableBtn = document.getElementById('view-table');
            
            if (!gridContainer || !listContainer || !tableContainer) {
                if (initAttempts < maxAttempts) {
                    // Les conteneurs n'existent pas encore, réessayer dans 100ms
                    setTimeout(initViewMode, 100);
                } else {
                    console.warn('Impossible d\'initialiser les vues après', maxAttempts, 'tentatives');
                }
                return;
            }
            
            // D'abord, cacher tous les conteneurs explicitement
            gridContainer.classList.add('hidden');
            gridContainer.style.display = 'none';
            listContainer.classList.add('hidden');
            listContainer.style.display = 'none';
            tableContainer.classList.add('hidden');
            tableContainer.style.display = 'none';
            
            // Ensuite, appliquer la préférence sauvegardée ou utiliser 'grid' par défaut
            let savedViewMode = localStorage.getItem('documentViewMode');
            if (!savedViewMode || savedViewMode === 'table') {
                savedViewMode = 'grid'; // Toujours grid par défaut
                localStorage.setItem('documentViewMode', 'grid');
            }
            setViewMode(savedViewMode);
            
            // Initialiser le calcul des colonnes pour la vue grille
            if (savedViewMode === 'grid') {
                setTimeout(calculateGridColumns, 200);
            }
        } catch (error) {
            console.error('Erreur dans initViewMode:', error);
        }
    }
    
    // Initialiser après le chargement du DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initViewMode);
    } else {
        // DOM déjà chargé, initialiser immédiatement
        setTimeout(initViewMode, 50);
    }
})();

function toggleAll(checkbox) {
    try {
        if (!checkbox) return;
        document.querySelectorAll('.document-checkbox').forEach(cb => {
            if (cb) cb.checked = checkbox.checked;
        });
        updateBulkActions();
    } catch (error) {
        console.error('Erreur dans toggleAll:', error);
    }
}

// Sélection multiple et actions groupées (Priorité 1.3)
let selectedDocuments = new Set();

function updateBulkActions() {
    try {
        const checkboxes = document.querySelectorAll('.document-checkbox:checked');
        selectedDocuments = new Set(Array.from(checkboxes).map(cb => cb ? cb.value : null).filter(v => v !== null));
        
        const bulkActions = document.getElementById('bulk-actions');
        const selectedCount = document.getElementById('selected-count');
        
        if (!bulkActions || !selectedCount) {
            // Éléments pas encore chargés, réessayer plus tard
            setTimeout(updateBulkActions, 100);
            return;
        }
        
        if (selectedDocuments.size > 0) {
            bulkActions.classList.remove('hidden');
            bulkActions.style.display = 'flex';
            selectedCount.textContent = selectedDocuments.size + ' document(s) sélectionné(s)';
        } else {
            bulkActions.classList.add('hidden');
            bulkActions.style.display = 'none';
        }
    } catch (error) {
        console.error('Erreur dans updateBulkActions:', error);
    }
}

// Initialiser la barre d'actions groupées comme cachée au chargement
(function() {
    let initAttempts = 0;
    const maxAttempts = 10;
    
    function initBulkActions() {
        try {
            initAttempts++;
            const bulkActions = document.getElementById('bulk-actions');
            if (bulkActions) {
                bulkActions.classList.add('hidden');
                bulkActions.style.display = 'none';
                
                // Attacher les événements aux checkboxes
                document.querySelectorAll('.document-checkbox').forEach(cb => {
                    if (cb && !cb.hasAttribute('data-listener-attached')) {
                        cb.addEventListener('change', updateBulkActions);
                        cb.setAttribute('data-listener-attached', 'true');
                    }
                });
            } else {
                if (initAttempts < maxAttempts) {
                    setTimeout(initBulkActions, 100);
                } else {
                    console.warn('Impossible d\'initialiser bulk-actions après', maxAttempts, 'tentatives');
                }
            }
        } catch (error) {
            console.error('Erreur dans initBulkActions:', error);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBulkActions);
    } else {
        setTimeout(initBulkActions, 50);
    }
})();

function clearSelection() {
    try {
        document.querySelectorAll('.document-checkbox').forEach(cb => {
            if (cb) cb.checked = false;
        });
        selectedDocuments.clear();
        updateBulkActions();
    } catch (error) {
        console.error('Erreur dans clearSelection:', error);
    }
}

function executeBulkAction() {
    try {
        const actionSelect = document.getElementById('bulk-action-select');
        if (!actionSelect) {
            console.error('Élément bulk-action-select non trouvé');
            return;
        }
        
        const action = actionSelect.value;
        if (!action || selectedDocuments.size === 0) {
            if (typeof showToast !== 'undefined') {
                showToast('Veuillez sélectionner une action et au moins un document', 'warning');
            } else {
                alert('Veuillez sélectionner une action et au moins un document');
            }
            return;
        }
        
        // Ouvrir les modals appropriés au lieu d'utiliser prompt()
        if (action === 'add_tag') {
            if (typeof openBulkTagModal === 'function') {
                openBulkTagModal();
            } else {
                console.error('openBulkTagModal non défini');
            }
            return;
        } else if (action === 'remove_tag') {
            // TODO: Créer modal pour retirer tag
            const tagId = prompt('ID du tag à retirer:');
            if (!tagId) return;
            performBulkAction(action, { tag_id: parseInt(tagId) });
        } else if (action === 'set_type') {
            if (typeof openBulkTypeModal === 'function') {
                openBulkTypeModal();
            } else {
                console.error('openBulkTypeModal non défini');
            }
            return;
        } else if (action === 'set_correspondent') {
            if (typeof openBulkCorrespondentModal === 'function') {
                openBulkCorrespondentModal();
            } else {
                console.error('openBulkCorrespondentModal non défini');
            }
            return;
        } else if (action === 'delete') {
            if (!confirm('Êtes-vous sûr de vouloir supprimer ' + selectedDocuments.size + ' document(s) ?')) {
                return;
            }
            performBulkAction(action, {});
        }
    } catch (error) {
        console.error('Erreur dans executeBulkAction:', error);
        if (typeof showToast !== 'undefined') {
            showToast('Erreur lors de l\'exécution de l\'action', 'error');
        }
    }
}

// Fonction pour exécuter l'action après sélection dans les modals
function performBulkAction(action, additionalData) {
    try {
        if (!action || selectedDocuments.size === 0) {
            if (typeof showToast !== 'undefined') {
                showToast('Aucun document sélectionné', 'warning');
            } else {
                alert('Aucun document sélectionné');
            }
            return;
        }
        
        fetch('<?= url('/api/documents/bulk-action') ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: action,
                document_ids: Array.from(selectedDocuments),
                ...(additionalData || {})
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Réponse HTTP ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                if (typeof showToast !== 'undefined') {
                    showToast(`Action effectuée : ${data.results.success} succès, ${data.results.errors} erreurs`, data.results.errors > 0 ? 'warning' : 'success');
                } else {
                    alert('Action effectuée : ' + data.results.success + ' succès, ' + data.results.errors + ' erreurs');
                }
                clearSelection();
                setTimeout(() => window.location.reload(), 1000);
            } else {
                if (typeof showToast !== 'undefined') {
                    showToast('Erreur : ' + (data.error || 'Action échouée'), 'error');
                } else {
                    alert('Erreur : ' + (data.error || 'Action échouée'));
                }
            }
        })
        .catch(error => {
            console.error('Erreur dans performBulkAction:', error);
            if (typeof showToast !== 'undefined') {
                showToast('Erreur : ' + (error.message || 'Erreur inconnue'), 'error');
            } else {
                alert('Erreur : ' + (error.message || 'Erreur inconnue'));
            }
        });
    } catch (error) {
        console.error('Erreur dans performBulkAction:', error);
        if (typeof showToast !== 'undefined') {
            showToast('Erreur lors de l\'exécution de l\'action', 'error');
        }
    }
}

// Fonction de scan manuel (disponible via console pour debug)
// L'indexation est automatique, cette fonction est conservée pour les cas avancés
window.forceScan = async function() {
    if (!confirm('Forcer un scan immédiat du filesystem ? (L\'indexation est normalement automatique)')) {
        return;
    }
    
    try {
        const response = await fetch('<?= url('/api/scanner/scan') ?>', { 
            method: 'POST' 
        });
        const data = await response.json();
        
        if (data.success) {
            alert(`Scan terminé:\n- ${data.stats.folders} dossiers\n- ${data.stats.files} fichiers\n- ${data.stats.new} nouveaux\n- ${data.stats.updated} mis à jour\n- ${data.thumbnails_generated} miniatures générées`);
            location.reload();
        } else {
            alert('Erreur lors du scan : ' + (data.error || 'Erreur inconnue'));
        }
    } catch (error) {
        alert('Erreur lors du scan : ' + error.message);
    }
}

// Fonction pour ouvrir la recherche avancée
function openAdvancedSearch() {
    try {
        const modal = document.getElementById('advanced-search-modal');
        if (!modal) {
            console.error('Modal de recherche avancée non trouvé');
            if (typeof showToast !== 'undefined') {
                showToast('Erreur : Modal de recherche avancée non disponible', 'error');
            } else {
                alert('Erreur : Modal de recherche avancée non disponible');
            }
            return;
        }
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        
        // Afficher/masquer les champs "date_to" et "amount_to" selon l'opérateur
        const dateOperator = document.querySelector('[name="date_operator"]');
        const amountOperator = document.querySelector('[name="amount_operator"]');
        
        if (dateOperator) {
            dateOperator.addEventListener('change', function() {
                const dateToContainer = document.getElementById('date-to-container');
                if (dateToContainer) {
                    if (this.value === 'between') {
                        dateToContainer.classList.remove('hidden');
                    } else {
                        dateToContainer.classList.add('hidden');
                    }
                }
            });
        }
        
        if (amountOperator) {
            amountOperator.addEventListener('change', function() {
                const amountToContainer = document.getElementById('amount-to-container');
                if (amountToContainer) {
                    if (this.value === 'between') {
                        amountToContainer.classList.remove('hidden');
                    } else {
                        amountToContainer.classList.add('hidden');
                    }
                }
            });
        }
    } catch (error) {
        console.error('Erreur dans openAdvancedSearch:', error);
    }
}

// Fonction pour fermer la recherche avancée
function closeAdvancedSearch() {
    try {
        const modal = document.getElementById('advanced-search-modal');
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    } catch (error) {
        console.error('Erreur dans closeAdvancedSearch:', error);
    }
}

// Fonctions wrapper pour les modals bulk actions (si non définies)
if (typeof openBulkTagModal === 'undefined') {
    window.openBulkTagModal = function() {
        const tagId = prompt('ID du tag à ajouter:');
        if (tagId) {
            performBulkAction('add_tag', { tag_id: parseInt(tagId) });
        }
    };
}

if (typeof openBulkTypeModal === 'undefined') {
    window.openBulkTypeModal = function() {
        const typeId = prompt('ID du type à définir:');
        if (typeId) {
            performBulkAction('set_type', { type_id: parseInt(typeId) });
        }
    };
}

if (typeof openBulkCorrespondentModal === 'undefined') {
    window.openBulkCorrespondentModal = function() {
        const correspondentId = prompt('ID du correspondant à définir:');
        if (correspondentId) {
            performBulkAction('set_correspondent', { correspondent_id: parseInt(correspondentId) });
        }
    };
}

// Recherches sauvegardées (Priorité 3.2)
function showSavedSearches() {
    try {
        fetch('<?= url('/api/saved-searches') ?>')
            .then(r => r.json())
            .then(data => {
                if (data.success && data.searches && data.searches.length > 0) {
                    const searches = data.searches.map(s => {
                        const filters = JSON.stringify(s.filters || {}).replace(/"/g, '&quot;');
                        return `<div class="p-2 hover:bg-gray-100 cursor-pointer" onclick="loadSavedSearch(${s.id}, '${(s.query || '').replace(/'/g, "\\'")}', ${filters})">${(s.name || 'Sans nom').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>`;
                    }).join('');
                    const modal = document.createElement('div');
                    modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center';
                    modal.innerHTML = `
                        <div class="bg-white rounded-lg p-6 max-w-md w-full">
                            <h3 class="text-lg font-semibold mb-4">Recherches sauvegardées</h3>
                            <div class="space-y-2 max-h-96 overflow-y-auto">${searches}</div>
                            <div class="mt-4 flex justify-end gap-2">
                                <button onclick="saveCurrentSearch()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Sauvegarder cette recherche</button>
                                <button onclick="this.closest('.fixed').remove()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Fermer</button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal);
                } else {
                    if (typeof showToast !== 'undefined') {
                        showToast('Aucune recherche sauvegardée', 'info');
                    } else {
                        alert('Aucune recherche sauvegardée');
                    }
                }
            })
            .catch(error => {
                console.error('Erreur lors du chargement des recherches sauvegardées:', error);
                if (typeof showToast !== 'undefined') {
                    showToast('Erreur lors du chargement des recherches', 'error');
                } else {
                    alert('Erreur lors du chargement des recherches');
                }
            });
    } catch (error) {
        console.error('Erreur dans showSavedSearches:', error);
    }
}

function loadSavedSearch(id, query, filters) {
    try {
        const url = new URL(window.location.origin + '<?= url('/documents') ?>');
        if (query) url.searchParams.set('search', query);
        if (filters && typeof filters === 'object') {
            Object.keys(filters).forEach(key => {
                if (filters[key]) url.searchParams.set(key, filters[key]);
            });
        }
        window.location.href = url.toString();
    } catch (error) {
        console.error('Erreur dans loadSavedSearch:', error);
    }
}

function saveCurrentSearch() {
    try {
        const name = prompt('Nom de la recherche:');
        if (!name) return;
        
        const url = new URL(window.location.href);
        const query = url.searchParams.get('search') || '';
        const filters = {
            logical_folder: url.searchParams.get('logical_folder'),
            folder: url.searchParams.get('folder'),
            type: url.searchParams.get('type'),
            correspondent: url.searchParams.get('correspondent'),
            tag: url.searchParams.get('tag')
        };
        
        fetch('<?= url('/api/saved-searches') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, query, filters })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (typeof showToast !== 'undefined') {
                    showToast('Recherche sauvegardée', 'success');
                } else {
                    alert('Recherche sauvegardée');
                }
                const modal = document.querySelector('.fixed');
                if (modal) {
                    modal.remove();
                }
            } else {
                if (typeof showToast !== 'undefined') {
                    showToast('Erreur lors de la sauvegarde: ' + (data.error || 'Erreur inconnue'), 'error');
                } else {
                    alert('Erreur lors de la sauvegarde: ' + (data.error || 'Erreur inconnue'));
                }
            }
        })
        .catch(error => {
            console.error('Erreur lors de la sauvegarde:', error);
            if (typeof showToast !== 'undefined') {
                showToast('Erreur lors de la sauvegarde', 'error');
            } else {
                alert('Erreur lors de la sauvegarde');
            }
        });
    } catch (error) {
        console.error('Erreur dans saveCurrentSearch:', error);
    }
}
</script>

<style>
.view-toggle.active {
    background-color: #dbeafe;
    font-weight: 600;
}

/* Grille adaptative pour les miniatures - Damier flex */
#view-grid-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    justify-content: flex-start;
    align-items: flex-start;
    width: 100%;
    max-width: 100%;
    padding: 1rem;
    box-sizing: border-box;
    overflow: visible;
}

.document-card {
    flex: 0 0 auto;
    box-sizing: border-box;
    overflow: hidden;
}

/* Forcer le calcul dynamique selon la largeur réelle disponible */
/* Largeur disponible = viewport - sidebar (256px) - padding container */
@media (min-width: 1024px) {
    .document-card {
        width: calc((100% - 4rem) / 8) !important;
        max-width: 100px !important;
        min-width: 85px !important;
    }
}

@media (min-width: 1280px) {
    .document-card {
        width: calc((100% - 4.5rem) / 9) !important;
        max-width: 105px !important;
        min-width: 90px !important;
    }
}

@media (min-width: 1536px) {
    .document-card {
        width: calc((100% - 5rem) / 10) !important;
        max-width: 110px !important;
        min-width: 95px !important;
    }
}

/* Responsive breakpoints - Fallback CSS si JavaScript ne fonctionne pas */
/* Le calcul dynamique JavaScript gère les colonnes, ces styles sont des fallbacks */
/* Très grand écran : 4 colonnes */
@media (min-width: 1920px) {
    .document-card {
        width: calc((100% - 3rem) / 4);
        min-width: 250px;
    }
}

/* Grand écran : 4 colonnes */
@media (min-width: 1280px) and (max-width: 1919px) {
    .document-card {
        width: calc((100% - 3rem) / 4);
        min-width: 220px;
    }
}

/* Écran moyen : 3 colonnes */
@media (min-width: 1024px) and (max-width: 1279px) {
    .document-card {
        width: calc((100% - 2rem) / 3);
        min-width: 200px;
    }
}

/* Tablette : 3 colonnes */
@media (min-width: 768px) and (max-width: 1023px) {
    .document-card {
        width: calc((100% - 2rem) / 3);
        min-width: 180px;
    }
}

/* Mobile large : 2 colonnes */
@media (min-width: 640px) and (max-width: 767px) {
    .document-card {
        width: calc((100% - 1rem) / 2);
        min-width: 150px;
    }
}

/* Mobile : 2 colonnes */
@media (max-width: 639px) {
    .document-card {
        width: calc((100% - 1rem) / 2);
        min-width: 140px;
    }
}

/* Assurer que les miniatures gardent leur ratio */
.document-card .aspect-\[3\/4\] {
    aspect-ratio: 3/4;
    width: 100%;
}
</style>
