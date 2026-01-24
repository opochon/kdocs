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
     * UNE seule requête pour tous les dossiers
     */
    private function preloadDbCounts(): void
    {
        try {
            $db = Database::getInstance();
            
            // Récupérer tous les documents avec leur relative_path
            // NOTE: On compte TOUS les documents (y compris pending) pour avoir les vrais compteurs
            $stmt = $db->query("
                SELECT relative_path
                FROM documents 
                WHERE deleted_at IS NULL 
                AND relative_path IS NOT NULL
                AND relative_path != ''
            ");
            
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Compter par dossier parent
            foreach ($rows as $row) {
                $path = $row['relative_path'] ?? '';
                
                // Normaliser le chemin (s'assurer qu'on utilise / comme séparateur)
                $path = str_replace('\\', '/', $path);
                
                // Extraire le dossier parent du fichier
                if ($path === '' || strpos($path, '/') === false) {
                    // Fichier à la racine (pas de / dans le chemin)
                    $folder = '';
                } else {
                    // Le dossier est tout sauf le nom de fichier
                    $folder = dirname($path);
                    // dirname() peut retourner '.' pour les fichiers dans un sous-dossier direct
                    // ou le chemin complet sans le nom de fichier
                    if ($folder === '.' || $folder === $path) {
                        // Si dirname retourne le même chemin, c'est qu'il n'y a qu'un niveau
                        // Exemple: "dossier/fichier.pdf" -> dirname = "dossier"
                        $parts = explode('/', $path);
                        if (count($parts) > 1) {
                            $folder = $parts[0]; // Premier niveau seulement
                        } else {
                            $folder = '';
                        }
                    }
                }
                
                // Normaliser le dossier (s'assurer qu'on utilise / comme séparateur)
                $folder = str_replace('\\', '/', $folder);
                
                if (!isset($this->dbCounts[$folder])) {
                    $this->dbCounts[$folder] = 0;
                }
                $this->dbCounts[$folder]++;
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs, on aura juste pas de comptage DB
            error_log("FolderTreeHelper::preloadDbCounts error: " . $e->getMessage());
        }
    }
    
    /**
     * Génère le HTML complet de l'arborescence
     */
    public function render(): string
    {
        $html = '<nav id="filesystem-tree" class="px-1 py-1">';
        $html .= $this->renderFolder('', 'Racine', 0);
        $html .= '</nav>';
        
        // Ajouter le JS minimal pour toggle + polling
        $html .= $this->renderJavaScript();
        
        return $html;
    }
    
    /**
     * Rend un dossier et ses enfants récursivement
     */
    private function renderFolder(string $relativePath, string $name, int $depth): string
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
        
        // Nom du dossier (lien)
        $html .= '<a href="' . htmlspecialchars($url) . '" class="flex-1 truncate folder-link">' . htmlspecialchars($name) . '</a>';
        
        // Compteur avec indicateur d'état
        $html .= '<span class="folder-count text-xs ml-1 flex-shrink-0">' . $countDisplay . '</span>';
        
        $html .= '</div>';
        
        // Sous-dossiers
        if ($hasChildren) {
            $isExpanded = $this->shouldExpand($relativePath);
            $displayStyle = $isExpanded ? '' : 'display: none;';
            
            $html .= '<div class="folder-children" style="' . $displayStyle . '">';
            foreach ($subfolders as $subfolder) {
                $subPath = $relativePath ? $relativePath . '/' . $subfolder : $subfolder;
                $html .= $this->renderFolder($subPath, $subfolder, $depth + 1);
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
     * JavaScript minimal pour toggle et polling d'indexation
     */
    private function renderJavaScript(): string
    {
        $baseUrl = htmlspecialchars($this->baseUrl);
        
        return <<<JS
<script>
(function() {
    const tree = document.getElementById('filesystem-tree');
    if (!tree) return;
    
    const BASE_URL = '{$baseUrl}';
    
    // Toggle des dossiers (clic sur flèche)
    tree.addEventListener('click', function(e) {
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
                arrow.classList.toggle('rotate-90', isHidden);
            }
        }
    });
    
    // Polling pour mettre à jour les indicateurs d'indexation
    let pollInterval = null;
    
    function checkIndexingStatus() {
        const indexingItems = tree.querySelectorAll('[data-indexing="true"]');
        
        if (indexingItems.length === 0) {
            // Plus d'indexation en cours, arrêter le polling
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            return;
        }
        
        // Vérifier le statut via API
        fetch(BASE_URL + '/api/folders/crawl-status')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                
                const activePaths = (data.queues || []).map(q => q.path || '');
                
                indexingItems.forEach(item => {
                    const path = item.dataset.folderPath || '';
                    const countEl = item.querySelector('.folder-count');
                    
                    if (!activePaths.includes(path)) {
                        // Indexation terminée pour ce dossier
                        item.removeAttribute('data-indexing');
                        
                        // Remplacer le spinner par le compteur normal
                        if (countEl) {
                            const spinner = countEl.querySelector('.indexing-spinner');
                            if (spinner) {
                                // Afficher temporairement un checkmark vert
                                spinner.outerHTML = '<span class="text-green-500">✓</span>';
                                
                                // Après 2s, recharger pour avoir les vrais chiffres
                                setTimeout(() => location.reload(), 2000);
                            }
                        }
                    }
                });
            })
            .catch(() => {});
    }
    
    // Démarrer le polling si des indexations sont en cours
    const hasIndexing = tree.querySelectorAll('[data-indexing="true"]').length > 0;
    if (hasIndexing) {
        pollInterval = setInterval(checkIndexingStatus, 5000);
        checkIndexingStatus(); // Premier check immédiat
    }
})();
</script>
<style>
.folder-arrow { transition: transform 0.2s; }
.rotate-90 { transform: rotate(90deg); }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.animate-spin { animation: spin 1s linear infinite; }
</style>
JS;
    }
}
