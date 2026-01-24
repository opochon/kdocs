<?php
/**
 * K-Docs - Helper pour générer l'arborescence des dossiers
 * Inspiré de Single File PHP File Browser
 * 
 * Principe : UNE seule passe récursive côté serveur
 * Pas d'AJAX, pas de recherche de path, tout est pré-calculé
 */

namespace KDocs\Helpers;

class FolderTreeHelper
{
    private string $basePath;
    private array $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
    private array $ignoreFolders = ['.git', 'node_modules', '__MACOSX', 'Thumbs.db'];
    private string $baseUrl;
    private ?string $currentFolderId;
    private ?string $currentFolderPath;
    private int $maxDepth;
    
    public function __construct(string $basePath, string $baseUrl, ?string $currentFolderId = null, int $maxDepth = 10, ?string $currentFolderPath = null)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->currentFolderId = $currentFolderId;
        $this->currentFolderPath = $currentFolderPath ? trim($currentFolderPath, '/') : null;
        $this->maxDepth = $maxDepth;
    }
    
    /**
     * Génère le HTML complet de l'arborescence
     * UNE seule fonction récursive, tout est fait côté serveur
     */
    public function render(): string
    {
        $html = '<nav id="filesystem-tree" class="px-1 py-1">';
        $html .= $this->renderFolder('', 'Racine', 0);
        $html .= '</nav>';
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
        
        // Générer le HTML
        $activeClass = $isActive ? 'bg-gray-50 text-gray-900 font-medium' : 'text-gray-600 hover:bg-gray-50';
        $countDisplay = $fileCount > 0 ? '<span class="text-gray-400 text-xs ml-1">' . $fileCount . '</span>' : '';
        
        // URL avec path inclus
        $url = $this->baseUrl . '/documents?folder=' . urlencode($folderId);
        if ($relativePath) {
            $url .= '&path=' . urlencode($relativePath);
        }
        
        $html = '<div class="folder-item" data-depth="' . $depth . '">';
        
        // Ligne du dossier
        $html .= '<div class="flex items-center px-2 py-1 text-sm rounded cursor-pointer ' . $activeClass . '" style="padding-left: ' . (12 + $indent) . 'px;">';
        
        // Flèche d'expansion (seulement si sous-dossiers)
        $html .= '<span class="folder-expander w-4 h-4 mr-1 flex items-center justify-center flex-shrink-0">';
        if ($hasChildren) {
            $html .= '<svg class="w-3 h-3 text-gray-400 folder-arrow transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
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
        
        // Compteur
        $html .= $countDisplay;
        
        $html .= '</div>';
        
        // Sous-dossiers (toujours rendus, mais cachés par défaut sauf si actif ou parent d'actif)
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
     * Détermine si un dossier doit être ouvert (s'il est parent du dossier actif)
     */
    private function shouldExpand(string $path): bool
    {
        // Toujours ouvrir la racine
        if ($path === '') {
            return true;
        }
        
        // Si un dossier est sélectionné, ouvrir tous ses ancêtres
        if ($this->currentFolderPath !== null) {
            // Vérifier si ce path est un préfixe du path actif
            if ($this->currentFolderPath === $path) {
                return true; // C'est le dossier actif lui-même
            }
            
            // Vérifier si c'est un parent (ancêtre)
            $pathWithSlash = $path . '/';
            if (strpos($this->currentFolderPath, $pathWithSlash) === 0) {
                return true; // C'est un parent du dossier actif
            }
        }
        
        return false;
    }
}
