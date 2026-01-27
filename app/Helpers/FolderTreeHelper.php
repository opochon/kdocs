<?php
/**
 * K-Docs - Helper pour générer l'arborescence des dossiers
 * Version avec indicateurs d'indexation visuels
 */

namespace KDocs\Helpers;

use KDocs\Core\Database;

class FolderTreeHelper
{
    private string $basePath;
    private array $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
    private array $ignoreFolders = ['.git', 'node_modules', '__MACOSX', 'Thumbs.db'];
    private string $baseUrl;
    private ?string $currentFolderId;
    private ?string $currentFolderPath;
    private int $maxDepth;
    private array $dbCounts = []; // Cache des comptages DB
    
    public function __construct(
        string $basePath, 
        string $baseUrl, 
        ?string $currentFolderId = null, 
        int $maxDepth = 10, 
        ?string $currentFolderPath = null
    ) {
        $this->basePath = rtrim($basePath, '/\\');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->currentFolderId = $currentFolderId;
        $this->currentFolderPath = $currentFolderPath ? trim($currentFolderPath, '/') : null;
        $this->maxDepth = $maxDepth;
        
        // Pré-charger les comptages DB pour tous les dossiers (une seule requête)
        $this->preloadDbCounts();
    }
    
    /**
     * Pré-charge les comptages de documents par dossier depuis la DB
     * OPTIMISÉ : Utilise GROUP BY SQL au lieu de charger tous les documents
     */
    private function preloadDbCounts(): void
    {
        try {
            $db = Database::getInstance();
            
            // OPTIMISATION : Utiliser GROUP BY SQL directement au lieu de charger tous les documents
            // Extrait le dossier parent avec SUBSTRING_INDEX et compte directement en SQL
            // Note: Pour "a/b/c/file.pdf", on veut "a/b/c" comme folder_path
            // Pour "dossier/file.pdf", on veut "dossier"
            $stmt = $db->query("
                SELECT 
                    CASE 
                        WHEN relative_path IS NULL OR relative_path = '' OR relative_path NOT LIKE '%/%' 
                        THEN ''
                        WHEN relative_path LIKE '%/%/%' 
                        THEN SUBSTRING(relative_path, 1, 
                            LENGTH(relative_path) - LENGTH(SUBSTRING_INDEX(relative_path, '/', -1)) - 1)
                        ELSE SUBSTRING_INDEX(relative_path, '/', 1)
                    END as folder_path,
                    COUNT(*) as doc_count
                FROM documents 
                WHERE deleted_at IS NULL 
                AND relative_path IS NOT NULL
                AND relative_path != ''
                GROUP BY folder_path
            ");
            
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Remplir directement le tableau dbCounts depuis les résultats SQL
            foreach ($rows as $row) {
                $folder = $row['folder_path'] ?? '';
                // Normaliser le séparateur (s'assurer qu'on utilise / comme séparateur)
                $folder = str_replace('\\', '/', $folder);
                $this->dbCounts[$folder] = (int)$row['doc_count'];
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs, on aura juste pas de comptage DB
            error_log("FolderTreeHelper::preloadDbCounts error: " . $e->getMessage());
        }
    }
    
    /**
     * Génère le HTML complet de l'arborescence
     * OPTIMISÉ : Lazy loading - charge récursivement seulement la racine et le chemin actif
     */
    public function render(): string
    {
        $html = '<nav id="filesystem-tree" class="px-1 py-1">';
        
        // Toujours charger la racine récursivement (forceRecursive = true)
        // Mais renderFolder() ne chargera récursivement que les dossiers dans le chemin actif
        $html .= $this->renderFolder('', 'Racine', 0, true);
        
        $html .= '</nav>';
        
        // Ajouter le JS minimal pour toggle + polling
        $html .= $this->renderJavaScript();
        
        return $html;
    }
    
    /**
     * Rend un dossier et ses enfants récursivement
     * MODIFIÉ : Charge TOUJOURS les sous-dossiers pour que le toggle fonctionne
     */
    private function renderFolder(string $relativePath, string $name, int $depth, bool $forceRecursive = false): string
    {
        if ($depth > $this->maxDepth) {
            return '';
        }
        
        $fullPath = $this->basePath . ($relativePath ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');
        
        if (!is_dir($fullPath)) {
            return '';
        }
        
        $folderId = md5($relativePath ?: '/');
        $isActive = ($this->currentFolderId === $folderId);
        $indent = $depth * 12;
        
        // Scanner le contenu du dossier
        $items = @scandir($fullPath);
        if ($items === false) {
            return '';
        }
        
        $subfolders = [];
        $fileCount = 0;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') {
                continue;
            }
            if (in_array($item, $this->ignoreFolders)) {
                continue;
            }
            
            $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($itemPath)) {
                $subfolders[] = $item;
            } elseif (is_file($itemPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, $this->allowedExtensions)) {
                    $fileCount++;
                }
            }
        }
        
        $hasChildren = !empty($subfolders);
        sort($subfolders, SORT_NATURAL | SORT_FLAG_CASE);
        
        // === DÉTECTION DE L'ÉTAT D'INDEXATION ===
        $isIndexing = file_exists($fullPath . DIRECTORY_SEPARATOR . '.indexing');
        $dbCount = $this->dbCounts[$relativePath] ?? 0;
        $needsSync = ($fileCount > 0 && $fileCount !== $dbCount);
        $isIndexed = ($fileCount > 0 && $fileCount === $dbCount);
        
        // Générer l'affichage du compteur selon l'état
        $countDisplay = $this->renderCountDisplay($fileCount, $dbCount, $isIndexing, $needsSync);
        
        // Classes CSS
        $activeClass = $isActive ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50';
        
        // URL avec path inclus
        $url = $this->baseUrl . '/documents?folder=' . urlencode($folderId);
        if ($relativePath) {
            $url .= '&path=' . urlencode($relativePath);
        }
        
        // Data attributes pour le JS (polling/refresh)
        $dataAttrs = 'data-folder-id="' . $folderId . '" data-folder-path="' . htmlspecialchars($relativePath) . '"';
        if ($isIndexing) {
            $dataAttrs .= ' data-indexing="true"';
        }
        
        $html = '<div class="folder-item" data-depth="' . $depth . '" ' . $dataAttrs . '>';
        
        // Ligne du dossier
        $html .= '<div class="flex items-center px-2 py-1 text-sm rounded cursor-pointer ' . $activeClass . '" style="padding-left: ' . (12 + $indent) . 'px;">';
        
        // Flèche d'expansion (seulement si sous-dossiers)
        $html .= '<span class="folder-expander w-4 h-4 mr-1 flex items-center justify-center flex-shrink-0">';
        if ($hasChildren) {
            $isExpanded = $this->shouldExpand($relativePath);
            $rotateClass = $isExpanded ? 'rotate-90' : '';
            $html .= '<svg class="w-3 h-3 text-gray-400 folder-arrow transition-transform ' . $rotateClass . '" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
            $html .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>';
            $html .= '</svg>';
        }
        $html .= '</span>';
        
        // Icône dossier
        $html .= '<svg class="w-3.5 h-3.5 mr-1.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
        $html .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path>';
        $html .= '</svg>';
        
        // Nom du dossier (lien) - AJAX enabled
        $html .= '<a href="' . htmlspecialchars($url) . '" class="flex-1 truncate folder-link" data-ajax-load="true" data-path="' . htmlspecialchars($relativePath) . '">' . htmlspecialchars($name) . '</a>';
        
        // Compteur avec indicateur d'état
        $html .= '<span class="folder-count text-xs ml-1 flex-shrink-0">' . $countDisplay . '</span>';
        
        $html .= '</div>';
        
        // Sous-dossiers - TOUJOURS charger récursivement pour que le toggle fonctionne
        if ($hasChildren) {
            $isExpanded = $this->shouldExpand($relativePath);
            $displayStyle = $isExpanded ? '' : 'display: none;';
            
            $html .= '<div class="folder-children" style="' . $displayStyle . '">';
            foreach ($subfolders as $subfolder) {
                $subPath = $relativePath ? $relativePath . '/' . $subfolder : $subfolder;
                $html .= $this->renderFolder($subPath, $subfolder, $depth + 1, false);
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Génère l'affichage du compteur selon l'état
     */
    private function renderCountDisplay(int $fileCount, int $dbCount, bool $isIndexing, bool $needsSync): string
    {
        if ($isIndexing) {
            // Spinner animé pendant l'indexation
            return '<span class="indexing-spinner text-orange-500" title="Indexation en cours...">
                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </span>';
        }
        
        if ($needsSync && $fileCount > 0) {
            // Fichiers non synchronisés (orange avec warning)
            return '<span class="text-orange-600" title="' . $fileCount . ' fichiers, ' . $dbCount . ' indexés">' 
                 . $fileCount . '</span>'
                 . '<span class="text-orange-400 ml-0.5" title="Synchronisation nécessaire">⚠</span>';
        }
        
        if ($fileCount > 0) {
            // Tout est synchronisé (gris normal)
            return '<span class="text-gray-400">' . $fileCount . '</span>';
        }
        
        // Dossier vide
        return '<span class="text-gray-300">-</span>';
    }
    
    /**
     * Détermine si un dossier doit être ouvert
     */
    private function shouldExpand(string $path): bool
    {
        // Toujours ouvrir la racine
        if ($path === '') {
            return true;
        }
        
        // Si un dossier est sélectionné, ouvrir tous ses ancêtres
        if ($this->currentFolderPath !== null) {
            if ($this->currentFolderPath === $path) {
                return true;
            }
            
            $pathWithSlash = $path . '/';
            if (strpos($this->currentFolderPath, $pathWithSlash) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * JavaScript pour toggle, menu contextuel et polling d'indexation
     */
    private function renderJavaScript(): string
    {
        $baseUrl = htmlspecialchars($this->baseUrl);
        
        return <<<JS
<!-- Menu contextuel dossiers -->
<div id="folder-context-menu" class="hidden fixed bg-white border border-gray-200 rounded-lg shadow-lg py-1 z-50 min-w-48">
    <button data-action="rename" class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
        Renommer
    </button>
    <button data-action="move" class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
        Déplacer
    </button>
    <div class="border-t border-gray-100 my-1"></div>
    <button data-action="delete" class="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
        Supprimer
    </button>
</div>

<!-- Modal Renommer -->
<div id="rename-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-96 p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Renommer le dossier</h3>
        <input type="text" id="rename-input" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Nouveau nom">
        <p class="text-xs text-gray-500 mt-2">Les chemins des documents seront mis à jour automatiquement.</p>
        <div class="flex justify-end gap-2 mt-4">
            <button onclick="closeRenameModal()" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Annuler</button>
            <button onclick="confirmRename()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Renommer</button>
        </div>
    </div>
</div>

<!-- Modal Déplacer -->
<div id="move-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-96 p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Déplacer le dossier</h3>
        <p class="text-sm text-gray-600 mb-2">Sélectionnez la destination :</p>
        <div id="move-tree" class="border border-gray-200 rounded-lg max-h-64 overflow-y-auto p-2 bg-gray-50"></div>
        <p class="text-xs text-gray-500 mt-2">Les chemins des documents seront mis à jour automatiquement.</p>
        <div class="flex justify-end gap-2 mt-4">
            <button onclick="closeMoveModal()" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Annuler</button>
            <button onclick="confirmMove()" id="move-confirm-btn" disabled class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">Déplacer</button>
        </div>
    </div>
</div>

<!-- Modal Supprimer -->
<div id="delete-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-96 p-6">
        <h3 class="text-lg font-medium text-red-600 mb-4 flex items-center gap-2">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            Supprimer le dossier
        </h3>
        <p class="text-sm text-gray-600 mb-2">Êtes-vous sûr de vouloir supprimer ce dossier ?</p>
        <p class="text-sm font-medium text-gray-900 mb-2" id="delete-folder-name"></p>
        <p class="text-xs text-gray-500">Le dossier et son contenu seront déplacés vers la corbeille. Cette action est réversible.</p>
        <div class="flex justify-end gap-2 mt-4">
            <button onclick="closeDeleteModal()" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Annuler</button>
            <button onclick="confirmDelete()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Supprimer</button>
        </div>
    </div>
</div>

<script>
(function() {
    const tree = document.getElementById('filesystem-tree');
    if (!tree) return;
    
    const BASE_URL = '{$baseUrl}';
    let contextMenu = document.getElementById('folder-context-menu');
    let currentFolderPath = null;
    let currentFolderName = null;
    let selectedMoveTarget = null;
    
    // === CLIC GAUCHE : Toggle + Navigation ===
    tree.addEventListener('click', function(e) {
        // Ignorer si c'est un lien direct (navigation AJAX)
        const link = e.target.closest('.folder-link');
        if (link) {
            // Laisser le handler AJAX global gérer la navigation
            return;
        }
        
        // Clic sur la ligne du dossier (pas le lien) = toggle
        const folderRow = e.target.closest('.folder-item > div');
        if (folderRow) {
            e.preventDefault();
            e.stopPropagation();
            
            const folderItem = folderRow.closest('.folder-item');
            const children = folderItem.querySelector(':scope > .folder-children');
            const arrow = folderRow.querySelector('.folder-arrow');
            
            if (children && arrow) {
                const isHidden = children.style.display === 'none';
                children.style.display = isHidden ? 'block' : 'none';
                arrow.classList.toggle('rotate-90', isHidden);
            }
        }
    });
    
    // === CLIC DROIT : Menu contextuel ===
    tree.addEventListener('contextmenu', function(e) {
        const folderItem = e.target.closest('.folder-item');
        if (!folderItem) return;
        
        e.preventDefault();
        
        currentFolderPath = folderItem.dataset.folderPath || '';
        currentFolderName = folderItem.querySelector('.folder-link')?.textContent || 'Dossier';
        
        // Ne pas permettre la suppression de la racine
        const deleteBtn = contextMenu.querySelector('[data-action="delete"]');
        const moveBtn = contextMenu.querySelector('[data-action="move"]');
        const renameBtn = contextMenu.querySelector('[data-action="rename"]');
        
        if (currentFolderPath === '') {
            deleteBtn.style.display = 'none';
            moveBtn.style.display = 'none';
            renameBtn.style.display = 'none';
        } else {
            deleteBtn.style.display = 'flex';
            moveBtn.style.display = 'flex';
            renameBtn.style.display = 'flex';
        }
        
        // Positionner le menu
        contextMenu.style.left = e.pageX + 'px';
        contextMenu.style.top = e.pageY + 'px';
        contextMenu.classList.remove('hidden');
    });
    
    // Fermer le menu contextuel au clic ailleurs
    document.addEventListener('click', function(e) {
        if (!contextMenu.contains(e.target)) {
            contextMenu.classList.add('hidden');
        }
    });
    
    // Actions du menu contextuel
    contextMenu.addEventListener('click', function(e) {
        const btn = e.target.closest('button');
        if (!btn) return;
        
        const action = btn.dataset.action;
        contextMenu.classList.add('hidden');
        
        switch(action) {
            case 'rename':
                openRenameModal();
                break;
            case 'move':
                openMoveModal();
                break;
            case 'delete':
                openDeleteModal();
                break;
        }
    });
    
    // === MODAL RENOMMER ===
    window.openRenameModal = function() {
        document.getElementById('rename-input').value = currentFolderName;
        document.getElementById('rename-modal').classList.remove('hidden');
        document.getElementById('rename-input').focus();
        document.getElementById('rename-input').select();
    };
    
    window.closeRenameModal = function() {
        document.getElementById('rename-modal').classList.add('hidden');
    };
    
    window.confirmRename = function() {
        const newName = document.getElementById('rename-input').value.trim();
        if (!newName || newName === currentFolderName) {
            closeRenameModal();
            return;
        }
        
        // Validation du nom
        if (/[\\/:*?"<>|]/.test(newName)) {
            alert('Le nom contient des caractères invalides.');
            return;
        }
        
        fetch(BASE_URL + '/api/folders/rename', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: currentFolderPath, newName: newName })
        })
        .then(r => r.json())
        .then(data => {
            closeRenameModal();
            if (data.success) {
                showNotification('Dossier renommé avec succès', 'success');
                // Mise à jour dynamique de l'arborescence
                updateFolderInTree(currentFolderPath, data.new_path, newName);
            } else {
                showNotification('Erreur: ' + (data.error || 'Inconnue'), 'error');
            }
        })
        .catch(err => {
            closeRenameModal();
            showNotification('Erreur de connexion', 'error');
        });
    };
    
    // === MODAL DÉPLACER ===
    window.openMoveModal = function() {
        selectedMoveTarget = null;
        document.getElementById('move-confirm-btn').disabled = true;
        
        // Charger l'arborescence pour la sélection
        fetch(BASE_URL + '/api/folders/tree')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderMoveTree(data.folders, document.getElementById('move-tree'));
                }
            });
        
        document.getElementById('move-modal').classList.remove('hidden');
    };
    
    window.closeMoveModal = function() {
        document.getElementById('move-modal').classList.add('hidden');
    };
    
    function renderMoveTree(folders, container, depth = 0) {
        container.innerHTML = '';
        
        // Option racine
        const rootDiv = document.createElement('div');
        rootDiv.className = 'move-target px-2 py-1 rounded cursor-pointer hover:bg-blue-100 flex items-center gap-1';
        rootDiv.dataset.path = '';
        rootDiv.innerHTML = '<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg> Racine';
        rootDiv.onclick = () => selectMoveTarget('');
        container.appendChild(rootDiv);
        
        // Dossiers
        renderMoveFolders(folders, container, 0);
    }
    
    function renderMoveFolders(folders, container, depth) {
        folders.forEach(folder => {
            // Ne pas afficher le dossier qu'on déplace ni ses enfants
            if (folder.path === currentFolderPath || folder.path.startsWith(currentFolderPath + '/')) {
                return;
            }
            
            const div = document.createElement('div');
            div.className = 'move-target px-2 py-1 rounded cursor-pointer hover:bg-blue-100 flex items-center gap-1';
            div.style.paddingLeft = (8 + depth * 16) + 'px';
            div.dataset.path = folder.path;
            div.innerHTML = '<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg> ' + folder.name;
            div.onclick = () => selectMoveTarget(folder.path);
            container.appendChild(div);
            
            if (folder.children && folder.children.length > 0) {
                renderMoveFolders(folder.children, container, depth + 1);
            }
        });
    }
    
    function selectMoveTarget(path) {
        selectedMoveTarget = path;
        document.querySelectorAll('.move-target').forEach(el => {
            el.classList.remove('bg-blue-200');
            if (el.dataset.path === path) {
                el.classList.add('bg-blue-200');
            }
        });
        document.getElementById('move-confirm-btn').disabled = false;
    }
    
    window.confirmMove = function() {
        if (selectedMoveTarget === null) return;
        
        fetch(BASE_URL + '/api/folders/move', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: currentFolderPath, destination: selectedMoveTarget })
        })
        .then(r => r.json())
        .then(data => {
            closeMoveModal();
            if (data.success) {
                showNotification('Dossier déplacé avec succès', 'success');
                // Mise à jour dynamique de l'arborescence
                moveFolderInTree(currentFolderPath, data.new_path, selectedMoveTarget);
            } else {
                showNotification('Erreur: ' + (data.error || 'Inconnue'), 'error');
            }
        })
        .catch(err => {
            closeMoveModal();
            showNotification('Erreur de connexion', 'error');
        });
    };
    
    // === MODAL SUPPRIMER ===
    window.openDeleteModal = function() {
        document.getElementById('delete-folder-name').textContent = currentFolderPath;
        document.getElementById('delete-modal').classList.remove('hidden');
    };
    
    window.closeDeleteModal = function() {
        document.getElementById('delete-modal').classList.add('hidden');
    };
    
    window.confirmDelete = function() {
        fetch(BASE_URL + '/api/folders/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ path: currentFolderPath })
        })
        .then(r => r.json())
        .then(data => {
            closeDeleteModal();
            if (data.success) {
                showNotification('Dossier déplacé vers la corbeille', 'success');
                // Suppression dynamique de l'arborescence
                removeFolderFromTree(currentFolderPath);
            } else {
                showNotification('Erreur: ' + (data.error || 'Inconnue'), 'error');
            }
        })
        .catch(err => {
            closeDeleteModal();
            showNotification('Erreur de connexion', 'error');
        });
    };
    
    // === NOTIFICATIONS ===
    function showNotification(message, type) {
        if (typeof window.showNotification === 'function') {
            window.showNotification(message, type);
            return;
        }
        
        const colors = { success: 'bg-green-500', error: 'bg-red-500', info: 'bg-blue-500' };
        const notif = document.createElement('div');
        notif.className = 'fixed top-4 right-4 px-4 py-2 rounded text-white text-sm shadow-lg z-50 ' + (colors[type] || colors.info);
        notif.textContent = message;
        document.body.appendChild(notif);
        setTimeout(() => notif.remove(), 4000);
    }
    
    // === POLLING INDEXATION ===
    let pollInterval = null;
    
    function checkIndexingStatus() {
        const indexingItems = tree.querySelectorAll('[data-indexing="true"]');
        
        if (indexingItems.length === 0) {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            return;
        }
        
        fetch(BASE_URL + '/api/folders/crawl-status')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                
                const activePaths = (data.queues || []).map(q => q.path || '');
                
                indexingItems.forEach(item => {
                    const path = item.dataset.folderPath || '';
                    const countEl = item.querySelector('.folder-count');
                    
                    if (!activePaths.includes(path)) {
                        item.removeAttribute('data-indexing');
                        if (countEl) {
                            const spinner = countEl.querySelector('.indexing-spinner');
                            if (spinner) {
                                spinner.outerHTML = '<span class="text-green-500">✓</span>';
                                setTimeout(() => location.reload(), 2000);
                            }
                        }
                    }
                });
            })
            .catch(() => {});
    }
    
    const hasIndexing = tree.querySelectorAll('[data-indexing="true"]').length > 0;
    if (hasIndexing) {
        pollInterval = setInterval(checkIndexingStatus, 5000);
        checkIndexingStatus();
    }
    
    // === MISE À JOUR DYNAMIQUE DE L'ARBORESCENCE ===
    
    /**
     * Recharge l'arborescence via AJAX (méthode fiable)
     * Conserve les états d'expansion actuels
     */
    function reloadFolderTree(highlightPath = null) {
        // Sauvegarder les chemins des dossiers ouverts
        const expandedPaths = [];
        tree.querySelectorAll('.folder-children').forEach(children => {
            if (children.style.display !== 'none') {
                const parent = children.closest('.folder-item');
                if (parent) {
                    expandedPaths.push(parent.dataset.folderPath || '');
                }
            }
        });
        
        // Récupérer les paramètres actuels
        const urlParams = new URLSearchParams(window.location.search);
        const currentFolder = urlParams.get('folder') || '';
        const currentPath = urlParams.get('path') || '';
        
        // Charger le nouveau HTML
        fetch(BASE_URL + '/api/folders/tree-html?folder=' + encodeURIComponent(currentFolder) + '&path=' + encodeURIComponent(currentPath))
            .then(r => r.text())
            .then(html => {
                // Créer un conteneur temporaire pour parser le HTML
                const temp = document.createElement('div');
                temp.innerHTML = html;
                
                // Trouver le nouvel arbre
                const newTree = temp.querySelector('#filesystem-tree');
                if (!newTree) return;
                
                // Restaurer les états d'expansion
                expandedPaths.forEach(path => {
                    const folder = newTree.querySelector('[data-folder-path="' + path + '"]');
                    if (folder) {
                        const children = folder.querySelector(':scope > .folder-children');
                        const arrow = folder.querySelector('.folder-arrow');
                        if (children) {
                            children.style.display = 'block';
                        }
                        if (arrow) {
                            arrow.classList.add('rotate-90');
                        }
                    }
                });
                
                // Remplacer le contenu de l'arbre
                tree.innerHTML = newTree.innerHTML;
                
                // Highlight le dossier modifié si spécifié
                if (highlightPath !== null) {
                    const highlightFolder = tree.querySelector('[data-folder-path="' + highlightPath + '"]');
                    if (highlightFolder) {
                        const row = highlightFolder.querySelector(':scope > div');
                        if (row) {
                            row.style.backgroundColor = 'rgba(34, 197, 94, 0.2)';
                            setTimeout(() => {
                                row.style.backgroundColor = '';
                            }, 2000);
                        }
                        
                        // S'assurer que le dossier parent est ouvert
                        let parent = highlightFolder.parentElement?.closest('.folder-item');
                        while (parent) {
                            const children = parent.querySelector(':scope > .folder-children');
                            const arrow = parent.querySelector('.folder-arrow');
                            if (children) children.style.display = 'block';
                            if (arrow) arrow.classList.add('rotate-90');
                            parent = parent.parentElement?.closest('.folder-item');
                        }
                    }
                }
            })
            .catch(err => {
                console.error('Erreur rechargement arborescence:', err);
            });
    }
    
    /**
     * Met à jour l'arborescence après renommage
     */
    function updateFolderInTree(oldPath, newPath, newName) {
        reloadFolderTree(newPath);
    }
    
    /**
     * Met à jour l'arborescence après déplacement
     */
    function moveFolderInTree(oldPath, newPath, targetPath) {
        reloadFolderTree(newPath);
    }
    
    /**
     * Supprime un dossier de l'arborescence
     */
    function removeFolderFromTree(path) {
        const folderItem = tree.querySelector('[data-folder-path="' + path + '"]');
        if (folderItem) {
            // Animation de disparition
            folderItem.style.transition = 'opacity 0.3s, max-height 0.3s';
            folderItem.style.opacity = '0';
            folderItem.style.maxHeight = folderItem.offsetHeight + 'px';
            
            setTimeout(() => {
                folderItem.style.maxHeight = '0';
                folderItem.style.overflow = 'hidden';
            }, 50);
            
            setTimeout(() => {
                // Recharger l'arborescence
                reloadFolderTree();
                
                // Si on était dans le dossier supprimé, naviguer vers le parent
                const currentPath = new URLSearchParams(window.location.search).get('path') || '';
                if (currentPath === path || currentPath.startsWith(path + '/')) {
                    const parentPath = path.includes('/') ? path.substring(0, path.lastIndexOf('/')) : '';
                    // Naviguer vers le parent (reload nécessaire pour mettre à jour la liste des documents)
                    window.location.href = BASE_URL + '/documents' + (parentPath ? '?path=' + encodeURIComponent(parentPath) : '');
                }
            }, 350);
        } else {
            reloadFolderTree();
        }
    }
})();
</script>
<style>
.folder-arrow { transition: transform 0.2s; }
.rotate-90 { transform: rotate(90deg); }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.animate-spin { animation: spin 1s linear infinite; }
#folder-context-menu { min-width: 160px; }
</style>
JS;
    }
}
