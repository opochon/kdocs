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
        $tempDir = $this->tempDir . '/' . uniqid('pdf_');
        @mkdir($tempDir, 0755, true);
        $pdfCmd = escapeshellarg($pdfPath);
        $tempCmd = escapeshellarg($tempDir);
        exec("pdftoppm -png -r 300 $pdfCmd $tempCmd/page 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            exec("magick convert -density 300 $pdfCmd $tempCmd/page.png 2>&1", $output, $returnCode);
        }
        $textParts = [];
        foreach (glob($tempDir . '/page*.png') as $pageFile) {
            $pageText = $this->extractTextFromImage($pageFile);
            if ($pageText) $textParts[] = $pageText;
        }
        $this->deleteDirectory($tempDir);
        return !empty($textParts) ? implode("\n\n", $textParts) : null;
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