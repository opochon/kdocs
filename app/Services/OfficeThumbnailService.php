<?php
/**
 * K-Docs - Office Thumbnail Service
 * Génération de miniatures pour fichiers Office via OnlyOffice ou fallback PDF tools
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class OfficeThumbnailService
{
    private string $thumbnailsPath;
    private string $tempPath;
    private ?OnlyOfficeService $onlyOfficeService = null;

    private array $supportedExtensions = [
        'docx', 'doc', 'odt', 'rtf',
        'xlsx', 'xls', 'ods',
        'pptx', 'ppt', 'odp'
    ];

    public function __construct()
    {
        $this->thumbnailsPath = Config::get('storage.thumbnails', __DIR__ . '/../../storage/thumbnails');
        $this->tempPath = Config::get('storage.temp', __DIR__ . '/../../storage/temp');
        $this->onlyOfficeService = new OnlyOfficeService();

        // Créer les dossiers si nécessaires
        if (!is_dir($this->thumbnailsPath)) {
            mkdir($this->thumbnailsPath, 0755, true);
        }
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }

    /**
     * Vérifie si le service est disponible pour les fichiers Office
     * Note: Ghostscript seul ne peut pas convertir les fichiers Office
     */
    public function isAvailable(): bool
    {
        // OnlyOffice est la seule option pour convertir les fichiers Office
        if ($this->onlyOfficeService->isAvailable()) {
            return true;
        }

        // LibreOffice comme fallback
        $libreOffice = Config::get('tools.libreoffice', '');
        if (!empty($libreOffice) && file_exists($libreOffice)) {
            return true;
        }

        return false;
    }

    /**
     * Vérifie pourquoi le service n'est pas disponible (pour diagnostic)
     */
    public function getDiagnosticInfo(): array
    {
        $info = [
            'onlyoffice_enabled' => $this->onlyOfficeService->isEnabled(),
            'onlyoffice_available' => $this->onlyOfficeService->isAvailable(),
            'onlyoffice_url' => $this->onlyOfficeService->getServerUrl(),
            'libreoffice_path' => Config::get('tools.libreoffice', ''),
            'libreoffice_exists' => false,
            'can_generate_thumbnails' => false,
        ];

        $libreOffice = Config::get('tools.libreoffice', '');
        if (!empty($libreOffice)) {
            $info['libreoffice_exists'] = file_exists($libreOffice);
        }

        $info['can_generate_thumbnails'] = $info['onlyoffice_available'] || $info['libreoffice_exists'];

        return $info;
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
        if (!file_exists($sourcePath)) {
            error_log("OfficeThumbnailService: Source file not found: " . $sourcePath);
            return null;
        }

        if (!$this->isSupported($sourcePath)) {
            error_log("OfficeThumbnailService: Unsupported format: " . $sourcePath);
            return null;
        }

        // Méthode 1: Extraire miniature intégrée dans le fichier Office (rapide)
        $result = $this->extractEmbeddedThumbnail($sourcePath, $documentId, $width, $height);
        if ($result) {
            return $result;
        }

        // Méthode 2: OnlyOffice (préféré si disponible)
        if ($this->onlyOfficeService->isAvailable()) {
            $result = $this->generateViaOnlyOffice($sourcePath, $documentId, $width, $height);
            if ($result) {
                return $result;
            }
            error_log("OfficeThumbnailService: OnlyOffice conversion failed, trying fallback");
        }

        // Méthode 3: Fallback PDF tools via LibreOffice (si disponibles)
        $result = $this->generateViaFallback($sourcePath, $documentId, $width, $height);
        if ($result) {
            return $result;
        }

        // Méthode 4: Générer une icône de type fichier
        return $this->generateFileTypeIcon($sourcePath, $documentId, $width, $height);
    }

    /**
     * Génère une miniature via OnlyOffice Conversion API
     * Essaie d'abord l'upload direct (multipart), puis fallback sur URL
     */
    private function generateViaOnlyOffice(string $sourcePath, int $documentId, int $width, int $height): ?string
    {
        // Méthode 1: Upload direct via multipart (ne nécessite pas de callback URL)
        $result = $this->generateViaOnlyOfficeUpload($sourcePath, $documentId, $width, $height);
        if ($result) {
            return $result;
        }

        // Méthode 2: Via URL de callback (fallback)
        return $this->generateViaOnlyOfficeUrl($sourcePath, $documentId, $width, $height);
    }

    /**
     * Génère une miniature via OnlyOffice avec upload direct du fichier
     */
    private function generateViaOnlyOfficeUpload(string $sourcePath, int $documentId, int $width, int $height): ?string
    {
        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $serverUrl = $this->onlyOfficeService->getServerUrl();

        // OnlyOffice Document Server supporte l'upload via /ConvertService.ashx avec multipart
        // On doit d'abord uploader le fichier, puis demander la conversion

        // Utiliser cURL pour l'upload multipart
        $ch = curl_init();

        // Créer le payload JSON pour la conversion
        $conversionPayload = [
            'async' => false,
            'filetype' => $ext,
            'key' => 'thumb_' . $documentId . '_' . time() . '_' . mt_rand(1000, 9999),
            'outputtype' => 'png',
            'thumbnail' => [
                'aspect' => 2, // 2 = fit (conserve proportions)
                'first' => true,
                'height' => $height,
                'width' => $width
            ]
        ];

        // Ajouter JWT si configuré
        $config = Config::load();
        $jwtSecret = $config['onlyoffice']['jwt_secret'] ?? '';
        $headers = [];

        if (!empty($jwtSecret)) {
            $token = $this->generateJWT($conversionPayload, $jwtSecret);
            $conversionPayload['token'] = $token;
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        // Lire le fichier
        $fileContent = file_get_contents($sourcePath);
        if ($fileContent === false) {
            error_log("OfficeThumbnailService: Cannot read file: " . $sourcePath);
            return null;
        }

        // Encoder en base64 pour l'inclure dans le JSON (méthode alternative)
        // Mais OnlyOffice préfère l'upload direct...

        // Essayons la méthode avec fichier temporaire accessible via data URI
        // OnlyOffice accepte aussi les data URIs pour les petits fichiers

        // Pour les fichiers > quelques MB, on doit utiliser l'upload
        $fileSize = filesize($sourcePath);

        // Si fichier < 5MB, essayer avec data URI
        if ($fileSize < 5 * 1024 * 1024) {
            $base64Content = base64_encode($fileContent);
            $dataUri = 'data:application/octet-stream;base64,' . $base64Content;

            // Malheureusement OnlyOffice ne supporte pas les data URI...
            // On doit donc utiliser un serveur temporaire ou héberger le fichier
        }

        // Alternative: Copier le fichier dans un dossier web accessible temporairement
        $tempWebPath = $this->tempPath . '/onlyoffice_' . $documentId . '_' . time() . '.' . $ext;
        if (!copy($sourcePath, $tempWebPath)) {
            error_log("OfficeThumbnailService: Cannot copy to temp: " . $tempWebPath);
            return null;
        }

        // Construire l'URL temporaire accessible par Docker
        // Essayer plusieurs méthodes
        $possibleUrls = [];

        // 1. Via host.docker.internal
        $appUrl = $config['onlyoffice']['callback_url'] ?? $config['app']['url'] ?? '';
        $appUrl = rtrim($appUrl, '/');
        $tempFileName = basename($tempWebPath);

        // Le fichier temp est dans storage/temp, on doit le rendre accessible
        // Créer un lien symbolique ou copier dans public
        $publicTempDir = dirname(__DIR__, 2) . '/public/temp';
        if (!is_dir($publicTempDir)) {
            @mkdir($publicTempDir, 0755, true);
        }
        $publicTempPath = $publicTempDir . '/' . $tempFileName;
        @copy($tempWebPath, $publicTempPath);

        // URL accessible
        $tempUrl = $appUrl . '/temp/' . $tempFileName;
        $conversionPayload['url'] = $tempUrl;

        // Recalculer JWT avec l'URL
        if (!empty($jwtSecret)) {
            $token = $this->generateJWT($conversionPayload, $jwtSecret);
            $conversionPayload['token'] = $token;
            $headers = ['Authorization: Bearer ' . $token];
        }

        $convertUrl = $serverUrl . '/ConvertService.ashx';

        curl_setopt_array($ch, [
            CURLOPT_URL => $convertUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($conversionPayload),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Nettoyer les fichiers temporaires
        @unlink($tempWebPath);
        @unlink($publicTempPath);

        if ($response === false) {
            error_log("OfficeThumbnailService: cURL error: " . $curlError);
            return null;
        }

        // Parser la réponse (peut être JSON ou XML)
        $result = json_decode($response, true);
        if (!$result) {
            // Essayer XML
            if (preg_match('/<FileUrl>(.+?)<\/FileUrl>/i', $response, $matches)) {
                $result = ['fileUrl' => html_entity_decode($matches[1])];
            } elseif (preg_match('/<Error>(.+?)<\/Error>/i', $response, $matches)) {
                error_log("OfficeThumbnailService: OnlyOffice XML error: " . $matches[1]);
                return null;
            }
        }

        if (isset($result['error']) && $result['error'] !== 0) {
            error_log("OfficeThumbnailService: OnlyOffice error code: " . $result['error'] . " - Response: " . $response);
            return null;
        }

        $fileUrl = $result['fileUrl'] ?? $result['FileUrl'] ?? null;
        if ($fileUrl) {
            // Télécharger l'image générée
            $imageData = @file_get_contents($fileUrl);
            if ($imageData !== false && strlen($imageData) > 100) {
                file_put_contents($thumbnailPath, $imageData);
                error_log("OfficeThumbnailService: Thumbnail generated successfully via upload method");
                return $thumbnailPath;
            }
        }

        error_log("OfficeThumbnailService: Upload method failed, response: " . substr($response, 0, 500));
        return null;
    }

    /**
     * Génère une miniature via OnlyOffice avec URL de callback (méthode originale)
     */
    private function generateViaOnlyOfficeUrl(string $sourcePath, int $documentId, int $width, int $height): ?string
    {
        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';

        // OnlyOffice nécessite que le fichier soit accessible via HTTP
        // On utilise l'API publique avec token de sécurité
        $config = Config::load();
        $callbackUrl = $config['onlyoffice']['callback_url'] ?? $config['onlyoffice']['app_url'] ?? '';
        $callbackUrl = rtrim($callbackUrl, '/');

        // Générer token de sécurité
        $accessToken = \KDocs\Controllers\Api\OnlyOfficeApiController::generateAccessToken($documentId);
        $fileUrl = $callbackUrl . '/api/onlyoffice/public/download/' . $documentId . '/' . $accessToken;

        // Extension du fichier source
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        // Requête de conversion OnlyOffice
        $serverUrl = $this->onlyOfficeService->getServerUrl();
        $convertUrl = $serverUrl . '/ConvertService.ashx';

        $payload = [
            'async' => false,
            'filetype' => $ext,
            'key' => 'thumb_' . $documentId . '_' . time(),
            'outputtype' => 'png',
            'thumbnail' => [
                'aspect' => 0, // 0 = stretch, 1 = crop, 2 = fit
                'first' => true,
                'height' => $height,
                'width' => $width
            ],
            'url' => $fileUrl
        ];

        // Ajouter token JWT si configuré
        $jwtSecret = $config['onlyoffice']['jwt_secret'] ?? '';
        $headers = ['Content-Type: application/json'];

        if (!empty($jwtSecret)) {
            $token = $this->generateJWT($payload, $jwtSecret);
            $payload['token'] = $token;
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload),
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);

        try {
            $response = @file_get_contents($convertUrl, false, $context);

            if ($response === false) {
                error_log("OfficeThumbnailService: OnlyOffice conversion request failed");
                return null;
            }

            $result = json_decode($response, true);

            if (isset($result['error'])) {
                error_log("OfficeThumbnailService: OnlyOffice error: " . ($result['error'] ?? 'unknown'));
                return null;
            }

            if (isset($result['fileUrl'])) {
                // Télécharger l'image générée
                $imageData = @file_get_contents($result['fileUrl']);
                if ($imageData !== false) {
                    file_put_contents($thumbnailPath, $imageData);
                    return $thumbnailPath;
                }
            }

            // Certaines versions retournent endConvert + fileUrl
            if (isset($result['endConvert']) && $result['endConvert'] === true && isset($result['fileUrl'])) {
                $imageData = @file_get_contents($result['fileUrl']);
                if ($imageData !== false) {
                    file_put_contents($thumbnailPath, $imageData);
                    return $thumbnailPath;
                }
            }

            error_log("OfficeThumbnailService: Unexpected OnlyOffice response: " . $response);
            return null;

        } catch (\Exception $e) {
            error_log("OfficeThumbnailService: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Génère un token JWT simple
     */
    private function generateJWT(array $payload, string $secret): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);

        $base64Header = $this->base64UrlEncode($header);
        $base64Payload = $this->base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);
        $base64Signature = $this->base64UrlEncode($signature);

        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Fallback: génère miniature via LibreOffice + Ghostscript
     * 1. Convertit Office -> PDF avec LibreOffice
     * 2. Convertit PDF -> PNG avec Ghostscript
     */
    private function generateViaFallback(string $sourcePath, int $documentId, int $width, int $height): ?string
    {
        $libreOfficePath = Config::get('tools.libreoffice', '');
        $ghostscriptPath = Config::get('tools.ghostscript', '');

        // LibreOffice est requis pour convertir Office -> PDF
        if (empty($libreOfficePath) || !file_exists($libreOfficePath)) {
            error_log("OfficeThumbnailService: LibreOffice not available for fallback");
            return null;
        }

        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';
        $tempPdfPath = $this->tempPath . '/' . $documentId . '_temp.pdf';

        try {
            // Étape 1: Convertir en PDF avec LibreOffice
            $outputDir = dirname($tempPdfPath);
            $cmd = sprintf(
                '"%s" --headless --convert-to pdf --outdir "%s" "%s"',
                $libreOfficePath,
                $outputDir,
                $sourcePath
            );

            exec($cmd . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                error_log("OfficeThumbnailService: LibreOffice conversion failed: " . implode("\n", $output));
                return null;
            }

            // Le fichier PDF a le même nom que le source mais avec .pdf
            $baseName = pathinfo(basename($sourcePath), PATHINFO_FILENAME);
            $generatedPdf = $outputDir . '/' . $baseName . '.pdf';

            if (!file_exists($generatedPdf)) {
                error_log("OfficeThumbnailService: PDF not generated at $generatedPdf");
                return null;
            }

            // Renommer vers notre chemin temp
            rename($generatedPdf, $tempPdfPath);

            // Étape 2: Convertir PDF -> PNG avec Ghostscript ou pdftoppm
            if (!empty($ghostscriptPath) && file_exists($ghostscriptPath)) {
                $cmd = sprintf(
                    '"%s" -dNOPAUSE -dBATCH -sDEVICE=png16m -r72 -dFirstPage=1 -dLastPage=1 -sOutputFile="%s" "%s"',
                    $ghostscriptPath,
                    $thumbnailPath,
                    $tempPdfPath
                );
                exec($cmd . ' 2>&1', $output2, $returnCode2);
            } else {
                // Fallback: pdftoppm
                $pdftoppm = Config::get('tools.pdftoppm', '');
                if (!empty($pdftoppm) && file_exists($pdftoppm)) {
                    $tempPngBase = $this->tempPath . '/' . $documentId . '_temp';
                    $cmd = sprintf(
                        '"%s" -png -f 1 -l 1 -scale-to %d "%s" "%s"',
                        $pdftoppm,
                        max($width, $height),
                        $tempPdfPath,
                        $tempPngBase
                    );
                    exec($cmd . ' 2>&1', $output2, $returnCode2);

                    // pdftoppm ajoute un suffixe -1.png
                    $generatedPng = $tempPngBase . '-1.png';
                    if (file_exists($generatedPng)) {
                        rename($generatedPng, $thumbnailPath);
                    }
                } else {
                    error_log("OfficeThumbnailService: No PDF to image converter available");
                    @unlink($tempPdfPath);
                    return null;
                }
            }

            // Nettoyage
            @unlink($tempPdfPath);

            if (file_exists($thumbnailPath)) {
                return $thumbnailPath;
            }

            error_log("OfficeThumbnailService: Thumbnail not generated");
            return null;

        } catch (\Exception $e) {
            error_log("OfficeThumbnailService fallback error: " . $e->getMessage());
            @unlink($tempPdfPath);
            return null;
        }
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

    /**
     * Extrait la miniature intégrée dans les fichiers Office (DOCX, XLSX, PPTX sont des ZIP)
     */
    private function extractEmbeddedThumbnail(string $sourcePath, int $documentId, int $width, int $height): ?string
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        // Seuls les formats Office Open XML ont des miniatures intégrées
        if (!in_array($ext, ['docx', 'xlsx', 'pptx'])) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($sourcePath) !== true) {
            return null;
        }

        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';
        $tempPath = $this->tempPath . '/' . $documentId . '_temp_thumb';
        $thumbnailFound = false;

        // Chercher la miniature intégrée (docProps/thumbnail.*)
        $embeddedPaths = [
            'docProps/thumbnail.jpeg',
            'docProps/thumbnail.png',
            'docProps/thumbnail.wmf',
            'docProps/thumbnail.emf',
        ];

        foreach ($embeddedPaths as $path) {
            $content = $zip->getFromName($path);
            if ($content !== false && strlen($content) > 100) {
                $tempFile = $tempPath . '.' . pathinfo($path, PATHINFO_EXTENSION);
                file_put_contents($tempFile, $content);

                // Convertir en PNG si nécessaire
                if ($this->convertToThumbnail($tempFile, $thumbnailPath, $width, $height)) {
                    @unlink($tempFile);
                    $thumbnailFound = true;
                    break;
                }
                @unlink($tempFile);
            }
        }

        // Si pas de miniature intégrée, essayer avec la première image du document
        if (!$thumbnailFound) {
            $firstImage = $this->findFirstImageInZip($zip);
            if ($firstImage) {
                $content = $zip->getFromName($firstImage);
                if ($content !== false && strlen($content) > 1000) {
                    $tempFile = $tempPath . '.' . pathinfo($firstImage, PATHINFO_EXTENSION);
                    file_put_contents($tempFile, $content);

                    if ($this->convertToThumbnail($tempFile, $thumbnailPath, $width, $height)) {
                        @unlink($tempFile);
                        $thumbnailFound = true;
                    }
                    @unlink($tempFile);
                }
            }
        }

        $zip->close();

        return $thumbnailFound && file_exists($thumbnailPath) ? $thumbnailPath : null;
    }

    /**
     * Trouve la meilleure image dans un fichier ZIP Office
     * Préfère les images plus grandes (vraies images, pas des icônes)
     */
    private function findFirstImageInZip(\ZipArchive $zip): ?string
    {
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            // Chercher dans word/media/, xl/media/, ppt/media/
            if (preg_match('/^(word|xl|ppt)\/media\/image\d+\.(png|jpg|jpeg)$/i', $name)) {
                $stat = $zip->statIndex($i);
                $size = $stat['size'] ?? 0;
                // Ignorer les images trop petites (< 5KB = probablement des icônes)
                if ($size > 5000) {
                    $candidates[] = ['name' => $name, 'size' => $size];
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Trier par taille décroissante (préférer les grandes images)
        usort($candidates, fn($a, $b) => $b['size'] <=> $a['size']);

        // Prendre la plus grande image, mais pas trop grande (limiter à 500KB)
        foreach ($candidates as $c) {
            if ($c['size'] < 500000) {
                return $c['name'];
            }
        }

        // Sinon prendre la première grande image
        return $candidates[0]['name'];
    }

    /**
     * Convertit une image en miniature PNG redimensionnée
     */
    private function convertToThumbnail(string $sourcePath, string $destPath, int $width, int $height): bool
    {
        if (!file_exists($sourcePath)) {
            return false;
        }

        // Essayer avec GD (toujours disponible en PHP)
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            return false;
        }

        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];

        // Charger l'image source
        $sourceImage = match($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            default => false
        };

        if ($sourceImage === false) {
            return false;
        }

        // Calculer les dimensions en préservant le ratio
        $ratio = min($width / $sourceWidth, $height / $sourceHeight);
        $newWidth = (int)($sourceWidth * $ratio);
        $newHeight = (int)($sourceHeight * $ratio);

        // Créer la miniature
        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

        // Préserver la transparence pour PNG
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
        imagefilledrectangle($thumbnail, 0, 0, $newWidth, $newHeight, $transparent);
        imagealphablending($thumbnail, true);

        // Redimensionner
        imagecopyresampled($thumbnail, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);

        // Sauvegarder en PNG
        $result = imagepng($thumbnail, $destPath, 8);

        imagedestroy($sourceImage);
        imagedestroy($thumbnail);

        return $result;
    }

    /**
     * Génère une icône de type fichier comme fallback final
     */
    private function generateFileTypeIcon(string $sourcePath, int $documentId, int $width, int $height): ?string
    {
        $ext = strtoupper(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $thumbnailPath = $this->thumbnailsPath . '/' . $documentId . '_thumb.png';

        // Couleurs par type de fichier
        $colors = [
            'DOCX' => ['bg' => [41, 98, 255], 'fg' => [255, 255, 255]],   // Bleu Word
            'DOC'  => ['bg' => [41, 98, 255], 'fg' => [255, 255, 255]],
            'ODT'  => ['bg' => [41, 98, 255], 'fg' => [255, 255, 255]],
            'RTF'  => ['bg' => [41, 98, 255], 'fg' => [255, 255, 255]],
            'XLSX' => ['bg' => [33, 115, 70], 'fg' => [255, 255, 255]],   // Vert Excel
            'XLS'  => ['bg' => [33, 115, 70], 'fg' => [255, 255, 255]],
            'ODS'  => ['bg' => [33, 115, 70], 'fg' => [255, 255, 255]],
            'CSV'  => ['bg' => [33, 115, 70], 'fg' => [255, 255, 255]],
            'PPTX' => ['bg' => [209, 71, 38], 'fg' => [255, 255, 255]],   // Orange PowerPoint
            'PPT'  => ['bg' => [209, 71, 38], 'fg' => [255, 255, 255]],
            'ODP'  => ['bg' => [209, 71, 38], 'fg' => [255, 255, 255]],
        ];

        $color = $colors[$ext] ?? ['bg' => [128, 128, 128], 'fg' => [255, 255, 255]];

        // Créer l'image
        $image = imagecreatetruecolor($width, $height);

        // Fond blanc
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);

        // Rectangle coloré au centre (style icône)
        $bgColor = imagecolorallocate($image, $color['bg'][0], $color['bg'][1], $color['bg'][2]);
        $fgColor = imagecolorallocate($image, $color['fg'][0], $color['fg'][1], $color['fg'][2]);

        $padding = 20;
        $iconWidth = $width - (2 * $padding);
        $iconHeight = $height - (2 * $padding);

        // Rectangle arrondi simulé (rectangle simple pour compatibilité)
        imagefilledrectangle($image, $padding, $padding, $width - $padding, $height - $padding, $bgColor);

        // Texte de l'extension au centre
        $fontSize = 5; // Taille de police GD intégrée (1-5)
        $textWidth = imagefontwidth($fontSize) * strlen($ext);
        $textHeight = imagefontheight($fontSize);
        $textX = ($width - $textWidth) / 2;
        $textY = ($height - $textHeight) / 2;

        imagestring($image, $fontSize, (int)$textX, (int)$textY, $ext, $fgColor);

        // Bordure légère
        $borderColor = imagecolorallocate($image, 200, 200, 200);
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

        // Sauvegarder
        $result = imagepng($image, $thumbnailPath);
        imagedestroy($image);

        return $result ? $thumbnailPath : null;
    }
}
