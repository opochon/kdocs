<?php
namespace KDocs\Services;

use KDocs\Core\Config;
use KDocs\Helpers\SystemHelper;

class OCRService
{
    private string $tesseractPath;
    private string $tempDir;
    
    public function __construct()
    {
        $config = Config::load();
        $this->tesseractPath = $config['ocr']['tesseract_path'] ?? 'tesseract';
        
        // Vérifier si le chemin configuré existe, sinon essayer dans PATH
        if ($this->tesseractPath !== 'tesseract' && !file_exists($this->tesseractPath)) {
            if (SystemHelper::commandExists('tesseract')) {
                $this->tesseractPath = 'tesseract';
            }
        }
        
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
        // Vérifier si Tesseract est disponible
        if ($this->tesseractPath !== 'tesseract' && !file_exists($this->tesseractPath)) {
            error_log("Tesseract non disponible à: {$this->tesseractPath}");
            return null;
        }
        
        $outputFile = $this->tempDir . '/' . uniqid('ocr_');
        // Utiliser escapeshellarg pour gérer les espaces dans les chemins Windows
        $tesseractCmd = escapeshellarg($this->tesseractPath);
        $imageCmd = escapeshellarg($imagePath);
        $outputCmd = escapeshellarg($outputFile);
        $command = "$tesseractCmd $imageCmd $outputCmd -l fra+eng 2>&1";
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            error_log("Erreur Tesseract (code $returnCode): " . implode("\n", $output));
        }
        
        if ($returnCode === 0 && file_exists($outputFile . '.txt')) {
            $text = file_get_contents($outputFile . '.txt');
            @unlink($outputFile . '.txt');
            
            // Fix encodage: Tesseract peut retourner ISO-8859-1 au lieu de UTF-8
            if ($text && !mb_check_encoding($text, 'UTF-8')) {
                $detected = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                if ($detected && $detected !== 'UTF-8') {
                    $text = mb_convert_encoding($text, 'UTF-8', $detected);
                    error_log("OCR: Converti de $detected vers UTF-8");
                }
            }
            
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
        $config = Config::load();
        $configPath = $config['tools']['pdftotext'] ?? null;
        $pdftotextPath = SystemHelper::findExecutable('pdftotext',
            $configPath ? [$configPath, ...SystemHelper::getDefaultPaths('pdftotext')] : SystemHelper::getDefaultPaths('pdftotext')
        );
        
        if ($pdftotextPath) {
            $pdftotextCmd = escapeshellarg($pdftotextPath);
            exec("$pdftotextCmd -layout $pdfCmd $outputCmd 2>&1", $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile)) {
                $text = file_get_contents($outputFile);
                @unlink($outputFile);
                $text = trim($text);
                if (!empty($text)) {
                    error_log("OCR réussi avec pdftotext: " . strlen($text) . " caractères extraits");
                    return $text;
                } else {
                    error_log("pdftotext a réussi mais texte vide");
                }
            } else {
                error_log("Erreur pdftotext (code $returnCode): " . implode("\n", $output));
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
        $configPdftoppm = $config['tools']['pdftoppm'] ?? null;
        $pdftoppmPath = SystemHelper::findExecutable('pdftoppm',
            $configPdftoppm ? [$configPdftoppm, ...SystemHelper::getDefaultPaths('pdftoppm')] : SystemHelper::getDefaultPaths('pdftoppm')
        );
        
        if ($pdftoppmPath) {
            $pdftoppmCmd = escapeshellarg($pdftoppmPath);
            exec("$pdftoppmCmd -png -r 200 $pdfCmd $tempCmd/page 2>&1", $output, $returnCode);
            if ($returnCode === 0) {
                $conversionSuccess = true;
            } else {
                error_log("Erreur pdftoppm (code $returnCode): " . implode("\n", $output));
            }
        }
        
        // Fallback ImageMagick si pdftoppm n'est pas disponible ou a échoué
        if (!$conversionSuccess) {
            $configImageMagick = $config['tools']['imagemagick'] ?? null;
            $imageMagickPath = SystemHelper::findExecutable('magick',
                $configImageMagick ? [$configImageMagick, ...SystemHelper::getDefaultPaths('imagemagick')] : SystemHelper::getDefaultPaths('imagemagick')
            );

            if ($imageMagickPath) {
                $imageMagickCmd = escapeshellarg($imageMagickPath);
            } else {
                $imageMagickCmd = null;
            }
            
            if ($imageMagickCmd) {
                $magickCmd = is_string($imageMagickCmd) && strpos($imageMagickCmd, ' ') !== false ? $imageMagickCmd : escapeshellarg($imageMagickCmd);
                exec("$magickCmd convert -density 200 $pdfCmd $tempCmd/page-%02d.png 2>&1", $output, $returnCode);
                if ($returnCode === 0) {
                    $conversionSuccess = true;
                } else {
                    error_log("Erreur ImageMagick (code $returnCode): " . implode("\n", $output));
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