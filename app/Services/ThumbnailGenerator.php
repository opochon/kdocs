<?php
/**
 * K-Docs - Service de génération de miniatures
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class ThumbnailGenerator
{
    private string $thumbnailPath;
    private string $ghostscriptPath;
    private string $imageMagickPath;
    
    public function __construct()
    {
        $config = Config::load();
        $this->thumbnailPath = $config['storage']['thumbnails'] ?? __DIR__ . '/../../storage/thumbnails';
        $this->ghostscriptPath = $config['tools']['ghostscript'] ?? 'gs';
        $this->imageMagickPath = $config['tools']['imagemagick'] ?? 'convert';
        
        // Créer le dossier si nécessaire
        if (!is_dir($this->thumbnailPath)) {
            @mkdir($this->thumbnailPath, 0755, true);
        }
    }
    
    /**
     * Génère une miniature pour un document
     */
    public function generate(string $sourcePath, int $documentId): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }
        
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $thumbFilename = $documentId . '_thumb.png';
        $thumbPath = $this->thumbnailPath . DIRECTORY_SEPARATOR . $thumbFilename;
        
        $success = false;
        
        if ($ext === 'pdf') {
            $success = $this->generateFromPdf($sourcePath, $thumbPath);
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'tiff', 'tif'])) {
            $success = $this->generateFromImage($sourcePath, $thumbPath);
        }
        
        return $success ? $thumbFilename : null;
    }
    
    /**
     * Génère miniature depuis PDF (première page)
     */
    private function generateFromPdf(string $source, string $dest): bool
    {
        // Méthode 1: Ghostscript
        $cmd = sprintf(
            '%s -dNOPAUSE -dBATCH -sDEVICE=png16m -dFirstPage=1 -dLastPage=1 -r72 -dPDFFitPage -g300x400 -sOutputFile=%s %s 2>&1',
            escapeshellarg($this->ghostscriptPath),
            escapeshellarg($dest),
            escapeshellarg($source)
        );
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($dest)) {
            return true;
        }
        
        // Méthode 2: ImageMagick (fallback)
        // ImageMagick 7 utilise "magick" au lieu de "convert"
        // Note: ImageMagick nécessite Ghostscript pour traiter les PDF
        $cmd = sprintf(
            '%s -density 72 %s[0] -resize 300x400 -background white -flatten %s 2>&1',
            escapeshellarg($this->imageMagickPath),
            escapeshellarg($source),
            escapeshellarg($dest)
        );
        exec($cmd, $output, $returnCode);
        
        // Logger les erreurs pour debug
        if ($returnCode !== 0) {
            error_log("ImageMagick error (code $returnCode): " . implode("\n", $output));
        }
        
        if ($returnCode === 0 && file_exists($dest)) {
            return true;
        }
        
        // Si échec avec magick.exe, essayer convert.exe (ancienne version)
        if (strpos($this->imageMagickPath, 'magick.exe') !== false) {
            $convertPath = str_replace('magick.exe', 'convert.exe', $this->imageMagickPath);
            if (file_exists($convertPath)) {
                $cmd = sprintf(
                    '%s -density 72 %s[0] -resize 300x400 -background white -flatten %s 2>&1',
                    escapeshellarg($convertPath),
                    escapeshellarg($source),
                    escapeshellarg($dest)
                );
                exec($cmd, $output, $returnCode);
                return $returnCode === 0 && file_exists($dest);
            }
        }
        
        return false;
    }
    
    /**
     * Génère miniature depuis image
     */
    private function generateFromImage(string $source, string $dest): bool
    {
        // Utilise GD (natif PHP)
        $info = @getimagesize($source);
        if (!$info) return false;
        
        $srcWidth = $info[0];
        $srcHeight = $info[1];
        $type = $info[2];
        
        // Calcul dimensions (max 300x400)
        $maxWidth = 300;
        $maxHeight = 400;
        $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
        $newWidth = (int)($srcWidth * $ratio);
        $newHeight = (int)($srcHeight * $ratio);
        
        // Charger l'image source
        $srcImg = null;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $srcImg = @imagecreatefromjpeg($source);
                break;
            case IMAGETYPE_PNG:
                $srcImg = @imagecreatefrompng($source);
                break;
            case IMAGETYPE_GIF:
                $srcImg = @imagecreatefromgif($source);
                break;
            default:
                return false;
        }
        
        if (!$srcImg) {
            return false;
        }
        
        // Créer la miniature
        $thumbImg = imagecreatetruecolor($newWidth, $newHeight);
        
        // Préserver la transparence pour PNG
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($thumbImg, false);
            imagesavealpha($thumbImg, true);
            $transparent = imagecolorallocatealpha($thumbImg, 255, 255, 255, 127);
            imagefill($thumbImg, 0, 0, $transparent);
        }
        
        imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        
        // Sauvegarder
        $result = imagepng($thumbImg, $dest);
        
        imagedestroy($srcImg);
        imagedestroy($thumbImg);
        
        return $result;
    }
}
