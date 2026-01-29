<?php
/**
 * K-Docs - Office Thumbnail Service
 * Génération de miniatures pour les fichiers Office via LibreOffice
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class OfficeThumbnailService
{
    private string $libreOfficePath;
    private string $thumbnailsPath;
    private string $tempPath;
    private array $supportedExtensions = [
        'docx', 'doc', 'odt', 'rtf',
        'xlsx', 'xls', 'ods',
        'pptx', 'ppt', 'odp'
    ];

    public function __construct()
    {
        $this->libreOfficePath = Config::get('tools.libreoffice', '');
        $this->thumbnailsPath = Config::get('storage.thumbnails', __DIR__ . '/../../storage/thumbnails');
        $this->tempPath = Config::get('storage.temp', __DIR__ . '/../../storage/temp');

        // Créer les dossiers si nécessaires
        if (!is_dir($this->thumbnailsPath)) {
            mkdir($this->thumbnailsPath, 0755, true);
        }
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }

    /**
     * Vérifie si LibreOffice est disponible
     */
    public function isAvailable(): bool
    {
        if (empty($this->libreOfficePath)) {
            return false;
        }
        return file_exists($this->libreOfficePath);
    }

    /**
     * Vérifie si l'extension est supportée
     */
    public function isSupported(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $this->supportedExtensions);
    }

    /**
     * Génère une miniature pour un fichier Office
     * @return string|null Chemin vers la miniature générée ou null si échec
     */
    public function generateThumbnail(string $sourcePath, int $documentId, int $width = 300, int $height = 400): ?string
    {
        if (!$this->isAvailable()) {
            error_log("OfficeThumbnailService: LibreOffice not available at: " . $this->libreOfficePath);
            return null;
        }

        if (!file_exists($sourcePath)) {
            error_log("OfficeThumbnailService: Source file not found: " . $sourcePath);
            return null;
        }

        if (!$this->isSupported($sourcePath)) {
            error_log("OfficeThumbnailService: Unsupported format: " . $sourcePath);
            return null;
        }

        // Dossier temporaire unique pour cette conversion
        $tempDir = $this->tempPath . '/thumb_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            error_log("OfficeThumbnailService: Cannot create temp dir: " . $tempDir);
            return null;
        }

        try {
            // Étape 1: Convertir en PDF avec LibreOffice
            $pdfPath = $this->convertToPdf($sourcePath, $tempDir);
            if (!$pdfPath) {
                throw new \Exception("PDF conversion failed");
            }

            // Étape 2: Convertir la première page du PDF en image
            $thumbnailPath = $this->pdfToImage($pdfPath, $documentId, $width, $height);
            if (!$thumbnailPath) {
                throw new \Exception("PDF to image conversion failed");
            }

            return $thumbnailPath;

        } catch (\Exception $e) {
            error_log("OfficeThumbnailService: " . $e->getMessage());
            return null;
        } finally {
            // Nettoyer le dossier temporaire
            $this->cleanupTempDir($tempDir);
        }
    }

    /**
     * Convertit un fichier Office en PDF via LibreOffice
     */
    private function convertToPdf(string $sourcePath, string $outputDir): ?string
    {
        // Échapper les chemins pour la ligne de commande
        $escapedSource = escapeshellarg($sourcePath);
        $escapedOutputDir = escapeshellarg($outputDir);
        $escapedLibreOffice = escapeshellarg($this->libreOfficePath);

        // Commande LibreOffice pour conversion en PDF
        // --headless : pas d'interface graphique
        // --convert-to pdf : format de sortie
        // --outdir : dossier de destination
        $command = sprintf(
            '%s --headless --convert-to pdf --outdir %s %s 2>&1',
            $escapedLibreOffice,
            $escapedOutputDir,
            $escapedSource
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            error_log("LibreOffice conversion failed: " . implode("\n", $output));
            return null;
        }

        // Trouver le fichier PDF généré
        $baseName = pathinfo($sourcePath, PATHINFO_FILENAME);
        $pdfPath = $outputDir . '/' . $baseName . '.pdf';

        if (!file_exists($pdfPath)) {
            // Parfois LibreOffice ajoute des caractères, chercher le PDF
            $pdfs = glob($outputDir . '/*.pdf');
            if (!empty($pdfs)) {
                $pdfPath = $pdfs[0];
            } else {
                error_log("PDF not found in output dir: " . $outputDir);
                return null;
            }
        }

        return $pdfPath;
    }

    /**
     * Convertit la première page d'un PDF en image
     */
    private function pdfToImage(string $pdfPath, int $documentId, int $width, int $height): ?string
    {
        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';

        // Essayer avec pdftoppm (poppler-utils)
        $pdftoppmPath = Config::get('tools.pdftoppm', 'pdftoppm');
        if ($this->tryPdftoppm($pdftoppmPath, $pdfPath, $thumbnailPath, $width, $height)) {
            return $thumbnailPath;
        }

        // Essayer avec ImageMagick
        $imageMagickPath = Config::get('tools.imagemagick', 'magick');
        if ($this->tryImageMagick($imageMagickPath, $pdfPath, $thumbnailPath, $width, $height)) {
            return $thumbnailPath;
        }

        // Essayer avec Ghostscript
        $ghostscriptPath = Config::get('tools.ghostscript', 'gs');
        if ($this->tryGhostscript($ghostscriptPath, $pdfPath, $thumbnailPath, $width, $height)) {
            return $thumbnailPath;
        }

        return null;
    }

    /**
     * Conversion via pdftoppm (Poppler)
     */
    private function tryPdftoppm(string $toolPath, string $pdfPath, string $outputPath, int $width, int $height): bool
    {
        if (!file_exists($toolPath) && PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $basePath = pathinfo($outputPath, PATHINFO_DIRNAME) . '/' . pathinfo($outputPath, PATHINFO_FILENAME);
        $escapedPdf = escapeshellarg($pdfPath);
        $escapedBase = escapeshellarg($basePath);

        // pdftoppm génère des fichiers avec numéro de page
        $command = sprintf(
            '%s -png -f 1 -l 1 -scale-to %d %s %s 2>&1',
            escapeshellarg($toolPath),
            max($width, $height),
            $escapedPdf,
            $escapedBase
        );

        exec($command, $output, $returnCode);

        // pdftoppm ajoute -1 au nom du fichier
        $generatedPath = $basePath . '-1.png';
        if ($returnCode === 0 && file_exists($generatedPath)) {
            rename($generatedPath, $outputPath);
            return true;
        }

        return false;
    }

    /**
     * Conversion via ImageMagick
     */
    private function tryImageMagick(string $toolPath, string $pdfPath, string $outputPath, int $width, int $height): bool
    {
        if (!file_exists($toolPath) && PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $escapedPdf = escapeshellarg($pdfPath . '[0]'); // [0] = première page
        $escapedOutput = escapeshellarg($outputPath);

        $command = sprintf(
            '%s -density 150 -thumbnail %dx%d -background white -flatten %s %s 2>&1',
            escapeshellarg($toolPath),
            $width,
            $height,
            $escapedPdf,
            $escapedOutput
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0 && file_exists($outputPath);
    }

    /**
     * Conversion via Ghostscript
     */
    private function tryGhostscript(string $toolPath, string $pdfPath, string $outputPath, int $width, int $height): bool
    {
        if (!file_exists($toolPath) && PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $escapedPdf = escapeshellarg($pdfPath);
        $escapedOutput = escapeshellarg($outputPath);

        // Calculer la résolution pour atteindre la taille souhaitée (approximatif)
        $resolution = 72; // PDF = 72 DPI par défaut

        $command = sprintf(
            '%s -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=1 -sDEVICE=png16m -r%d -dPDFFitPage -g%dx%d -sOutputFile=%s %s 2>&1',
            escapeshellarg($toolPath),
            $resolution,
            $width,
            $height,
            $escapedOutput,
            $escapedPdf
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0 && file_exists($outputPath);
    }

    /**
     * Nettoie un dossier temporaire
     */
    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /**
     * Récupère le chemin de la miniature pour un document
     * Génère la miniature si elle n'existe pas
     */
    public function getThumbnailPath(int $documentId, string $sourcePath): ?string
    {
        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';

        // Si la miniature existe déjà et est récente
        if (file_exists($thumbnailPath)) {
            $thumbTime = filemtime($thumbnailPath);
            $sourceTime = file_exists($sourcePath) ? filemtime($sourcePath) : 0;

            // Regénérer si le fichier source est plus récent
            if ($thumbTime >= $sourceTime) {
                return $thumbnailPath;
            }
        }

        // Générer la miniature
        return $this->generateThumbnail($sourcePath, $documentId);
    }

    /**
     * Vérifie si une miniature existe pour un document
     */
    public function hasThumbnail(int $documentId): bool
    {
        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';
        return file_exists($thumbnailPath);
    }

    /**
     * Supprime la miniature d'un document
     */
    public function deleteThumbnail(int $documentId): bool
    {
        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';
        if (file_exists($thumbnailPath)) {
            return @unlink($thumbnailPath);
        }
        return true;
    }
}
