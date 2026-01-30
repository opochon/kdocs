<?php
namespace KDocs\Services;

use KDocs\Contracts\OCRServiceInterface;
use KDocs\Core\Config;
use KDocs\Helpers\SystemHelper;

class OCRService implements OCRServiceInterface
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
        if (in_array($ext, ['docx', 'doc', 'odt', 'rtf'])) return $this->extractTextFromWord($filePath);
        if (in_array($ext, ['xlsx', 'xls', 'ods', 'csv'])) return $this->extractTextFromSpreadsheet($filePath);
        if (in_array($ext, ['pptx', 'ppt', 'odp'])) return $this->extractTextFromPresentation($filePath);
        if ($ext === 'txt') return file_get_contents($filePath);
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

        // Déterminer les langues disponibles
        $langs = $this->getAvailableLangs();

        // Forcer UTF-8 avec l'option -c preserve_interword_spaces=1
        $command = "$tesseractCmd $imageCmd $outputCmd -l $langs --psm 3 -c preserve_interword_spaces=1 2>&1";
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            error_log("Erreur Tesseract (code $returnCode): " . implode("\n", $output));
        }

        if ($returnCode === 0 && file_exists($outputFile . '.txt')) {
            $text = file_get_contents($outputFile . '.txt');
            @unlink($outputFile . '.txt');

            // Conversion forcée vers UTF-8
            $text = $this->forceUtf8($text);

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
                // Conversion forcée vers UTF-8
                $text = $this->forceUtf8(trim($text));
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

    /**
     * Extrait le texte d'un document Word (DOCX, DOC, ODT, RTF)
     */
    private function extractTextFromWord(string $filePath): ?string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        try {
            // DOCX - utiliser PhpWord
            if ($ext === 'docx') {
                if (!class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
                    error_log("PhpWord non disponible - installer avec: composer require phpoffice/phpword");
                    return null;
                }

                $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
                $text = '';

                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        $text .= $this->extractTextFromElement($element) . "\n";
                    }
                }

                $text = $this->forceUtf8(trim($text));
                if (!empty($text)) {
                    error_log("Texte extrait de DOCX: " . strlen($text) . " caractères");
                    return $text;
                }
            }

            // DOC (ancien format) - essayer avec PhpWord ou extraction basique
            if ($ext === 'doc') {
                // PhpWord peut lire certains DOC via MsDoc reader
                try {
                    $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath, 'MsDoc');
                    $text = '';
                    foreach ($phpWord->getSections() as $section) {
                        foreach ($section->getElements() as $element) {
                            $text .= $this->extractTextFromElement($element) . "\n";
                        }
                    }
                    if (!empty(trim($text))) {
                        return $this->forceUtf8(trim($text));
                    }
                } catch (\Exception $e) {
                    error_log("Lecture DOC via PhpWord échouée: " . $e->getMessage());
                }

                // Fallback: extraction basique du texte brut
                return $this->extractTextFromBinaryDoc($filePath);
            }

            // ODT - utiliser PhpWord
            if ($ext === 'odt') {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath, 'ODText');
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        $text .= $this->extractTextFromElement($element) . "\n";
                    }
                }
                return $this->forceUtf8(trim($text));
            }

            // RTF - utiliser PhpWord
            if ($ext === 'rtf') {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath, 'RTF');
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        $text .= $this->extractTextFromElement($element) . "\n";
                    }
                }
                return $this->forceUtf8(trim($text));
            }

        } catch (\Exception $e) {
            error_log("Erreur extraction texte Word ($ext): " . $e->getMessage());
        }

        return null;
    }

    /**
     * Extrait le texte récursivement d'un élément PhpWord
     */
    private function extractTextFromElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $elementText = $element->getText();
            if (is_string($elementText)) {
                $text .= $elementText;
            } elseif (is_array($elementText)) {
                foreach ($elementText as $item) {
                    if (is_string($item)) {
                        $text .= $item;
                    } elseif (is_object($item) && method_exists($item, 'getText')) {
                        $text .= $item->getText();
                    }
                }
            }
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child) . ' ';
            }
        }

        return $text;
    }

    /**
     * Extraction basique de texte depuis un fichier DOC binaire
     */
    private function extractTextFromBinaryDoc(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) return null;

        // Essayer d'extraire le texte ASCII visible
        $text = '';
        $inText = false;
        $buffer = '';

        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];
            $ord = ord($char);

            // Caractères imprimables ASCII + accents courants
            if (($ord >= 32 && $ord <= 126) || ($ord >= 192 && $ord <= 255) || $ord === 10 || $ord === 13) {
                $buffer .= $char;
                $inText = true;
            } else {
                if ($inText && strlen($buffer) > 3) {
                    $text .= $buffer . ' ';
                }
                $buffer = '';
                $inText = false;
            }
        }

        if (strlen($buffer) > 3) {
            $text .= $buffer;
        }

        // Nettoyer
        $text = preg_replace('/\s+/', ' ', $text);
        $text = $this->forceUtf8(trim($text));

        return !empty($text) ? $text : null;
    }

    /**
     * Extrait le texte d'un tableur (XLSX, XLS, ODS, CSV)
     */
    private function extractTextFromSpreadsheet(string $filePath): ?string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // CSV - lecture simple
        if ($ext === 'csv') {
            $content = file_get_contents($filePath);
            return $this->forceUtf8($content);
        }

        // XLSX - extraction via ZIP (sans dépendance supplémentaire)
        if ($ext === 'xlsx') {
            return $this->extractTextFromXlsx($filePath);
        }

        return null;
    }

    /**
     * Extrait le texte d'un fichier XLSX (format ZIP avec XML)
     */
    private function extractTextFromXlsx(string $filePath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $text = '';

        // Lire les chaînes partagées
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml) {
            $xml = @simplexml_load_string($sharedStringsXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string)$si->t;
                }
            }
        }

        // Lire les feuilles
        for ($i = 1; $i <= 10; $i++) {
            $sheetXml = $zip->getFromName("xl/worksheets/sheet$i.xml");
            if (!$sheetXml) break;

            $xml = @simplexml_load_string($sheetXml);
            if (!$xml) continue;

            foreach ($xml->sheetData->row as $row) {
                $rowText = [];
                foreach ($row->c as $cell) {
                    $value = '';
                    if (isset($cell['t']) && (string)$cell['t'] === 's') {
                        // Référence aux chaînes partagées
                        $idx = (int)$cell->v;
                        $value = $sharedStrings[$idx] ?? '';
                    } else {
                        $value = (string)$cell->v;
                    }
                    if (!empty($value)) {
                        $rowText[] = $value;
                    }
                }
                if (!empty($rowText)) {
                    $text .= implode(' | ', $rowText) . "\n";
                }
            }
        }

        $zip->close();

        return $this->forceUtf8(trim($text));
    }

    /**
     * Extrait le texte d'une présentation (PPTX, PPT, ODP)
     */
    private function extractTextFromPresentation(string $filePath): ?string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // PPTX - extraction via ZIP
        if ($ext === 'pptx') {
            return $this->extractTextFromPptx($filePath);
        }

        return null;
    }

    /**
     * Extrait le texte d'un fichier PPTX (format ZIP avec XML)
     */
    private function extractTextFromPptx(string $filePath): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $text = '';

        // Parcourir les slides
        for ($i = 1; $i <= 100; $i++) {
            $slideXml = $zip->getFromName("ppt/slides/slide$i.xml");
            if (!$slideXml) break;

            // Extraire le texte des balises <a:t>
            preg_match_all('/<a:t>([^<]*)<\/a:t>/i', $slideXml, $matches);
            if (!empty($matches[1])) {
                $text .= "--- Slide $i ---\n";
                $text .= implode(' ', $matches[1]) . "\n\n";
            }
        }

        $zip->close();

        return $this->forceUtf8(trim($text));
    }

    /**
     * Récupère les langues Tesseract disponibles
     */
    private function getAvailableLangs(): string
    {
        static $langs = null;

        if ($langs === null) {
            $tesseractCmd = escapeshellarg($this->tesseractPath);
            exec("$tesseractCmd --list-langs 2>&1", $output, $returnCode);

            $available = [];
            foreach ($output as $line) {
                $line = trim($line);
                if (in_array($line, ['fra', 'eng', 'deu', 'ita'])) {
                    $available[] = $line;
                }
            }

            // Prioriser fra+eng si disponibles
            if (in_array('fra', $available) && in_array('eng', $available)) {
                $langs = 'fra+eng';
            } elseif (in_array('fra', $available)) {
                $langs = 'fra';
            } elseif (in_array('eng', $available)) {
                $langs = 'eng';
            } else {
                $langs = 'eng'; // Fallback
            }
        }

        return $langs;
    }

    /**
     * Force la conversion d'un texte en UTF-8
     * Gère les cas où Tesseract/pdftotext retournent des encodages non-UTF-8
     */
    private function forceUtf8(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Si déjà UTF-8 valide, retourner tel quel
        if (mb_check_encoding($text, 'UTF-8')) {
            // Vérifier quand même les caractères mal encodés courants
            if (strpos($text, "\xC3\x83") === false && strpos($text, "\xC2") === false) {
                return $text;
            }
        }

        // Essayer de détecter l'encodage source
        $encodings = ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'CP850', 'ASCII'];
        $detected = mb_detect_encoding($text, $encodings, true);

        if ($detected && $detected !== 'UTF-8') {
            $converted = mb_convert_encoding($text, 'UTF-8', $detected);
            error_log("OCR: Converti de $detected vers UTF-8");
            return $converted;
        }

        // Fallback: essayer iconv avec translitération
        $converted = @iconv('Windows-1252', 'UTF-8//TRANSLIT//IGNORE', $text);
        if ($converted !== false && !empty($converted)) {
            error_log("OCR: Converti via iconv Windows-1252 vers UTF-8");
            return $converted;
        }

        // Dernier recours: supprimer les caractères non-UTF-8
        $cleaned = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if ($cleaned !== $text) {
            error_log("OCR: Nettoyage caractères non-UTF-8");
        }

        return $cleaned;
    }
}