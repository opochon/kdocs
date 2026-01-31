<?php
/**
 * K-Docs - Service de génération de miniatures
 *
 * Supporte:
 * - PDF (via Ghostscript ou ImageMagick)
 * - Images (JPG, PNG, GIF, TIFF, WebP, BMP via GD)
 * - Documents Office (via LibreOffice headless si installé)
 * - Placeholder stylisé pour les formats non supportés
 */

namespace KDocs\Services;

use KDocs\Contracts\ThumbnailGeneratorInterface;
use KDocs\Core\Config;
use KDocs\Helpers\SystemHelper;

class ThumbnailGenerator implements ThumbnailGeneratorInterface
{
    private string $thumbnailPath;
    private string $ghostscriptPath;
    private string $imageMagickPath;
    private string $libreOfficePath;
    private string $tempPath;

    // Extensions supportées par type
    private array $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'tiff', 'tif', 'webp', 'bmp'];
    private array $officeExtensions = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];
    private array $pdfExtensions = ['pdf'];

    public function __construct()
    {
        $config = Config::load();
        $this->thumbnailPath = $config['storage']['thumbnails'] ?? __DIR__ . '/../../storage/thumbnails';
        $this->ghostscriptPath = $config['tools']['ghostscript'] ?? $this->findGhostscript();
        $this->imageMagickPath = $config['tools']['imagemagick'] ?? 'convert';
        $this->libreOfficePath = $config['tools']['libreoffice'] ?? $this->findLibreOffice();
        $this->tempPath = sys_get_temp_dir();

        // Créer le dossier si nécessaire
        if (!is_dir($this->thumbnailPath)) {
            @mkdir($this->thumbnailPath, 0755, true);
        }
    }

    /**
     * Trouve Ghostscript (cross-platform)
     */
    private function findGhostscript(): string
    {
        $found = SystemHelper::findGhostscript();
        return $found ?? 'gs';
    }

    /**
     * Trouve LibreOffice (cross-platform)
     */
    private function findLibreOffice(): string
    {
        $found = SystemHelper::findLibreOffice();
        return $found ?? (SystemHelper::isWindows() ? 'soffice.exe' : 'libreoffice');
    }

    /**
     * Vérifie si LibreOffice est disponible
     */
    public function isLibreOfficeAvailable(): bool
    {
        return file_exists($this->libreOfficePath);
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

        if (in_array($ext, $this->pdfExtensions)) {
            $success = $this->generateFromPdf($sourcePath, $thumbPath);
        } elseif (in_array($ext, $this->imageExtensions)) {
            $success = $this->generateFromImage($sourcePath, $thumbPath);
        } elseif (in_array($ext, $this->officeExtensions)) {
            $success = $this->generateFromOffice($sourcePath, $thumbPath, $ext);
        }

        // Si aucune méthode n'a fonctionné, générer un placeholder
        if (!$success) {
            $success = $this->generatePlaceholder($thumbPath, $ext);
        }

        return $success ? $thumbFilename : null;
    }

    /**
     * Génère miniature depuis PDF (première page)
     */
    private function generateFromPdf(string $source, string $dest): bool
    {
        // Méthode 1: Ghostscript
        if (file_exists($this->ghostscriptPath)) {
            $cmd = sprintf(
                '"%s" -dNOPAUSE -dBATCH -sDEVICE=png16m -dFirstPage=1 -dLastPage=1 -r72 -dPDFFitPage -g300x400 -sOutputFile="%s" "%s" 2>&1',
                $this->ghostscriptPath,
                $dest,
                $source
            );
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($dest)) {
                return true;
            }
        }

        // Méthode 2: ImageMagick (fallback)
        $cmd = sprintf(
            '%s -density 72 %s[0] -resize 300x400 -background white -flatten %s 2>&1',
            escapeshellarg($this->imageMagickPath),
            escapeshellarg($source),
            escapeshellarg($dest)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && file_exists($dest)) {
            return true;
        }

        return false;
    }

    /**
     * Génère miniature depuis image
     */
    private function generateFromImage(string $source, string $dest): bool
    {
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        // Pour WebP et BMP, essayer d'abord avec GD si supporté
        $info = @getimagesize($source);
        if (!$info) {
            // Essayer de charger manuellement pour les formats spéciaux
            if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
                $srcImg = @imagecreatefromwebp($source);
                if ($srcImg) {
                    $srcWidth = imagesx($srcImg);
                    $srcHeight = imagesy($srcImg);
                    return $this->resizeAndSave($srcImg, $srcWidth, $srcHeight, $dest);
                }
            }
            if ($ext === 'bmp' && function_exists('imagecreatefrombmp')) {
                $srcImg = @imagecreatefrombmp($source);
                if ($srcImg) {
                    $srcWidth = imagesx($srcImg);
                    $srcHeight = imagesy($srcImg);
                    return $this->resizeAndSave($srcImg, $srcWidth, $srcHeight, $dest);
                }
            }
            return false;
        }

        $srcWidth = $info[0];
        $srcHeight = $info[1];
        $type = $info[2];

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
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $srcImg = @imagecreatefromwebp($source);
                }
                break;
            case IMAGETYPE_BMP:
                if (function_exists('imagecreatefrombmp')) {
                    $srcImg = @imagecreatefrombmp($source);
                }
                break;
            case IMAGETYPE_TIFF_II:
            case IMAGETYPE_TIFF_MM:
                // TIFF nécessite ImageMagick
                return $this->generateFromImageMagick($source, $dest);
            default:
                return false;
        }

        if (!$srcImg) {
            // Fallback sur ImageMagick
            return $this->generateFromImageMagick($source, $dest);
        }

        return $this->resizeAndSave($srcImg, $srcWidth, $srcHeight, $dest);
    }

    /**
     * Redimensionne et sauvegarde une image
     */
    private function resizeAndSave($srcImg, int $srcWidth, int $srcHeight, string $dest): bool
    {
        // Calcul dimensions (max 300x400)
        $maxWidth = 300;
        $maxHeight = 400;
        $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
        $newWidth = (int)($srcWidth * $ratio);
        $newHeight = (int)($srcHeight * $ratio);

        // Créer la miniature
        $thumbImg = imagecreatetruecolor($newWidth, $newHeight);

        // Fond blanc
        $white = imagecolorallocate($thumbImg, 255, 255, 255);
        imagefill($thumbImg, 0, 0, $white);

        imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

        // Sauvegarder
        $result = imagepng($thumbImg, $dest);

        imagedestroy($srcImg);
        imagedestroy($thumbImg);

        return $result;
    }

    /**
     * Génère miniature via ImageMagick
     */
    private function generateFromImageMagick(string $source, string $dest): bool
    {
        $cmd = sprintf(
            '%s %s -resize 300x400 -background white -flatten %s 2>&1',
            escapeshellarg($this->imageMagickPath),
            escapeshellarg($source),
            escapeshellarg($dest)
        );
        exec($cmd, $output, $returnCode);

        return $returnCode === 0 && file_exists($dest);
    }

    /**
     * Génère miniature depuis document Office via LibreOffice
     */
    private function generateFromOffice(string $source, string $dest, string $ext): bool
    {
        if (!file_exists($this->libreOfficePath)) {
            // LibreOffice non disponible, utiliser le placeholder
            return false;
        }

        // Créer un dossier temporaire unique pour éviter les conflits
        $tempDir = $this->tempPath . DIRECTORY_SEPARATOR . 'kdocs_thumb_' . uniqid();
        @mkdir($tempDir, 0755, true);

        try {
            // Convertir en PDF avec LibreOffice
            $cmd = sprintf(
                '"%s" --headless --convert-to pdf --outdir "%s" "%s" 2>&1',
                $this->libreOfficePath,
                $tempDir,
                $source
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                error_log("LibreOffice conversion failed: " . implode("\n", $output));
                return false;
            }

            // Trouver le PDF généré
            $baseName = pathinfo($source, PATHINFO_FILENAME);
            $pdfPath = $tempDir . DIRECTORY_SEPARATOR . $baseName . '.pdf';

            if (!file_exists($pdfPath)) {
                // Chercher tout PDF dans le dossier
                $pdfs = glob($tempDir . '/*.pdf');
                if (!empty($pdfs)) {
                    $pdfPath = $pdfs[0];
                } else {
                    error_log("PDF not found after LibreOffice conversion in $tempDir");
                    return false;
                }
            }

            // Générer la miniature depuis le PDF
            $success = $this->generateFromPdf($pdfPath, $dest);

            // Nettoyer
            @unlink($pdfPath);
            @rmdir($tempDir);

            return $success;

        } catch (\Exception $e) {
            error_log("Office thumbnail generation error: " . $e->getMessage());
            // Nettoyer en cas d'erreur
            @array_map('unlink', glob($tempDir . '/*'));
            @rmdir($tempDir);
            return false;
        }
    }

    /**
     * Génère un placeholder stylisé pour les formats non supportés
     */
    private function generatePlaceholder(string $dest, string $ext): bool
    {
        $width = 300;
        $height = 400;

        $img = imagecreatetruecolor($width, $height);

        // Couleurs selon le type de fichier
        $colors = $this->getColorForExtension($ext);
        $bgColor = imagecolorallocate($img, $colors['bg'][0], $colors['bg'][1], $colors['bg'][2]);
        $fgColor = imagecolorallocate($img, $colors['fg'][0], $colors['fg'][1], $colors['fg'][2]);
        $textColor = imagecolorallocate($img, $colors['text'][0], $colors['text'][1], $colors['text'][2]);
        $white = imagecolorallocate($img, 255, 255, 255);

        // Fond
        imagefill($img, 0, 0, $bgColor);

        // Icône de document stylisée (forme de page avec coin plié)
        $iconX = 75;
        $iconY = 80;
        $iconW = 150;
        $iconH = 180;
        $foldSize = 40;

        // Page principale (blanc)
        $points = [
            $iconX, $iconY,                          // Top-left
            $iconX + $iconW - $foldSize, $iconY,     // Top-right before fold
            $iconX + $iconW, $iconY + $foldSize,     // Fold corner
            $iconX + $iconW, $iconY + $iconH,        // Bottom-right
            $iconX, $iconY + $iconH,                 // Bottom-left
        ];
        imagefilledpolygon($img, $points, $white);

        // Coin plié (légèrement plus foncé)
        $foldColor = imagecolorallocate($img, 230, 230, 230);
        $foldPoints = [
            $iconX + $iconW - $foldSize, $iconY,
            $iconX + $iconW, $iconY + $foldSize,
            $iconX + $iconW - $foldSize, $iconY + $foldSize,
        ];
        imagefilledpolygon($img, $foldPoints, $foldColor);

        // Lignes de texte simulées
        $lineColor = imagecolorallocate($img, 200, 200, 200);
        for ($i = 0; $i < 6; $i++) {
            $lineY = $iconY + 60 + ($i * 18);
            $lineWidth = ($i % 2 == 0) ? 100 : 80;
            imagefilledrectangle($img, $iconX + 20, $lineY, $iconX + 20 + $lineWidth, $lineY + 8, $lineColor);
        }

        // Badge d'extension en bas
        $badgeH = 40;
        $badgeY = $height - 70;
        imagefilledrectangle($img, 0, $badgeY, $width, $badgeY + $badgeH, $fgColor);

        // Texte de l'extension
        $extText = strtoupper($ext);
        $fontSize = 5; // Taille de police GD (1-5)

        // Centrer le texte
        $textWidth = imagefontwidth($fontSize) * strlen($extText);
        $textX = ($width - $textWidth) / 2;
        $textY = $badgeY + ($badgeH - imagefontheight($fontSize)) / 2;

        imagestring($img, $fontSize, (int)$textX, (int)$textY, $extText, $white);

        // Sauvegarder
        $result = imagepng($img, $dest);
        imagedestroy($img);

        return $result;
    }

    /**
     * Retourne les couleurs selon l'extension
     */
    private function getColorForExtension(string $ext): array
    {
        $colorSchemes = [
            // Documents Word
            'doc' => ['bg' => [235, 245, 255], 'fg' => [41, 98, 255], 'text' => [255, 255, 255]],
            'docx' => ['bg' => [235, 245, 255], 'fg' => [41, 98, 255], 'text' => [255, 255, 255]],
            'odt' => ['bg' => [235, 245, 255], 'fg' => [41, 98, 255], 'text' => [255, 255, 255]],
            'rtf' => ['bg' => [235, 245, 255], 'fg' => [41, 98, 255], 'text' => [255, 255, 255]],

            // Tableurs Excel
            'xls' => ['bg' => [232, 245, 233], 'fg' => [33, 115, 70], 'text' => [255, 255, 255]],
            'xlsx' => ['bg' => [232, 245, 233], 'fg' => [33, 115, 70], 'text' => [255, 255, 255]],
            'ods' => ['bg' => [232, 245, 233], 'fg' => [33, 115, 70], 'text' => [255, 255, 255]],
            'csv' => ['bg' => [232, 245, 233], 'fg' => [33, 115, 70], 'text' => [255, 255, 255]],

            // Présentations PowerPoint
            'ppt' => ['bg' => [255, 243, 224], 'fg' => [255, 87, 34], 'text' => [255, 255, 255]],
            'pptx' => ['bg' => [255, 243, 224], 'fg' => [255, 87, 34], 'text' => [255, 255, 255]],
            'odp' => ['bg' => [255, 243, 224], 'fg' => [255, 87, 34], 'text' => [255, 255, 255]],

            // PDF
            'pdf' => ['bg' => [255, 235, 238], 'fg' => [211, 47, 47], 'text' => [255, 255, 255]],

            // Archives
            'zip' => ['bg' => [243, 229, 245], 'fg' => [142, 36, 170], 'text' => [255, 255, 255]],
            'rar' => ['bg' => [243, 229, 245], 'fg' => [142, 36, 170], 'text' => [255, 255, 255]],
            '7z' => ['bg' => [243, 229, 245], 'fg' => [142, 36, 170], 'text' => [255, 255, 255]],

            // Texte
            'txt' => ['bg' => [245, 245, 245], 'fg' => [97, 97, 97], 'text' => [255, 255, 255]],
            'md' => ['bg' => [245, 245, 245], 'fg' => [97, 97, 97], 'text' => [255, 255, 255]],

            // Par défaut
            'default' => ['bg' => [240, 240, 240], 'fg' => [100, 100, 100], 'text' => [255, 255, 255]],
        ];

        return $colorSchemes[$ext] ?? $colorSchemes['default'];
    }

    /**
     * Régénère toutes les miniatures manquantes
     */
    public function regenerateMissing(int $limit = 50): array
    {
        $db = \KDocs\Core\Database::getInstance();

        $stmt = $db->prepare("
            SELECT id, file_path, mime_type
            FROM documents
            WHERE (thumbnail_path IS NULL OR thumbnail_path = '')
              AND file_path IS NOT NULL
              AND deleted_at IS NULL
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $documents = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $results = ['generated' => 0, 'failed' => 0, 'total' => count($documents)];

        foreach ($documents as $doc) {
            if (!file_exists($doc['file_path'])) {
                $results['failed']++;
                continue;
            }

            $thumbFilename = $this->generate($doc['file_path'], $doc['id']);

            if ($thumbFilename) {
                $updateStmt = $db->prepare("UPDATE documents SET thumbnail_path = ? WHERE id = ?");
                $updateStmt->execute([$thumbFilename, $doc['id']]);
                $results['generated']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Vérifie les outils disponibles
     */
    public function getAvailableTools(): array
    {
        return [
            'ghostscript' => file_exists($this->ghostscriptPath),
            'ghostscript_path' => $this->ghostscriptPath,
            'imagemagick' => $this->checkImageMagick(),
            'imagemagick_path' => $this->imageMagickPath,
            'libreoffice' => file_exists($this->libreOfficePath),
            'libreoffice_path' => $this->libreOfficePath,
            'gd' => extension_loaded('gd'),
            'gd_webp' => function_exists('imagecreatefromwebp'),
            'gd_bmp' => function_exists('imagecreatefrombmp'),
        ];
    }

    /**
     * Vérifie si ImageMagick est disponible
     */
    private function checkImageMagick(): bool
    {
        exec($this->imageMagickPath . ' -version 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }
}
