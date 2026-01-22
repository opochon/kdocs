<?php
namespace KDocs\Services;
use KDocs\Core\Config;

class OCRService
{
    private string $tesseractPath;
    private string $tempDir;
    
    public function __construct()
    {
        $config = Config::load();
        $this->tesseractPath = $config['ocr']['tesseract_path'] ?? 'tesseract';
        $this->tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../storage/temp';
        if (!is_dir($this->tempDir)) @mkdir($this->tempDir, 0755, true);
    }
    
    public function extractText(string $filePath): ?string
    {
        if (!file_exists($filePath)) return null;
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'pdf') return $this->extractTextFromPDF($filePath);
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'tiff', 'tif'])) return $this->extractTextFromImage($filePath);
        return null;
    }
    
    private function extractTextFromImage(string $imagePath): ?string
    {
        $outputFile = $this->tempDir . '/' . uniqid('ocr_');
        // Utiliser escapeshellarg pour gérer les espaces dans les chemins Windows
        $tesseractCmd = escapeshellarg($this->tesseractPath);
        $imageCmd = escapeshellarg($imagePath);
        $outputCmd = escapeshellarg($outputFile);
        $command = "$tesseractCmd $imageCmd $outputCmd -l fra+eng 2>&1";
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && file_exists($outputFile . '.txt')) {
            $text = file_get_contents($outputFile . '.txt');
            @unlink($outputFile . '.txt');
            return trim($text);
        }
        return null;
    }
    
    private function extractTextFromPDF(string $pdfPath): ?string
    {
        // Méthode 1: Essayer pdftotext (le plus rapide et efficace)
        $outputFile = $this->tempDir . '/' . uniqid('pdf_text_') . '.txt';
        $pdfCmd = escapeshellarg($pdfPath);
        $outputCmd = escapeshellarg($outputFile);
        
        // Vérifier si pdftotext est disponible
        exec("where pdftotext 2>&1", $whereOutput, $whereCode);
        $pdftotextAvailable = ($whereCode === 0);
        
        if ($pdftotextAvailable) {
            exec("pdftotext -layout $pdfCmd $outputCmd 2>&1", $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile)) {
                $text = file_get_contents($outputFile);
                @unlink($outputFile);
                $text = trim($text);
                if (!empty($text)) {
                    return $text;
                }
            }
        } else {
            error_log("pdftotext non disponible, utilisation du fallback OCR");
        }
        
        // Méthode 2: Fallback sur conversion image + OCR (plus lent mais fonctionne toujours)
        $tempDir = $this->tempDir . '/' . uniqid('pdf_');
        @mkdir($tempDir, 0755, true);
        $tempCmd = escapeshellarg($tempDir);
        
        $conversionSuccess = false;
        
        // Essayer pdftoppm d'abord
        exec("where pdftoppm 2>&1", $pdftoppmCheck, $pdftoppmCheckCode);
        if ($pdftoppmCheckCode === 0) {
            exec("pdftoppm -png -r 200 $pdfCmd $tempCmd/page 2>&1", $output, $returnCode);
            if ($returnCode === 0) {
                $conversionSuccess = true;
            }
        }
        
        // Fallback ImageMagick si pdftoppm n'est pas disponible ou a échoué
        if (!$conversionSuccess) {
            $config = Config::load();
            $imageMagickPath = $config['tools']['imagemagick'] ?? 'magick';
            $imageMagickCmd = escapeshellarg($imageMagickPath);
            
            // Vérifier si ImageMagick est disponible
            exec("where $imageMagickCmd 2>&1", $imCheck, $imCheckCode);
            if ($imCheckCode === 0) {
                exec("$imageMagickCmd convert -density 200 $pdfCmd $tempCmd/page.png 2>&1", $output, $returnCode);
                if ($returnCode === 0) {
                    $conversionSuccess = true;
                }
            } else {
                error_log("ImageMagick non disponible, impossible de convertir le PDF en images");
            }
        }
        
        if (!$conversionSuccess) {
            error_log("Aucun outil de conversion PDF disponible (pdftoppm ou ImageMagick requis)");
            $this->deleteDirectory($tempDir);
            return null;
        }
        
        $textParts = [];
        $pageFiles = array_merge(
            glob($tempDir . '/page*.png'),
            glob($tempDir . '/page-*.png')
        );
        
        // Limiter à 5 pages pour la performance
        $pageFiles = array_slice($pageFiles, 0, 5);
        
        if (empty($pageFiles)) {
            error_log("Aucune page convertie depuis le PDF");
            $this->deleteDirectory($tempDir);
            return null;
        }
        
        foreach ($pageFiles as $pageFile) {
            $pageText = $this->extractTextFromImage($pageFile);
            if ($pageText) {
                $textParts[] = $pageText;
            }
        }
        
        $this->deleteDirectory($tempDir);
        
        if (empty($textParts)) {
            error_log("Aucun texte extrait par OCR depuis les images du PDF");
            return null;
        }
        
        return implode("\n\n", $textParts);
    }
    
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}