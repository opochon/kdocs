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

        // Méthode 1: OnlyOffice (préféré)
        if ($this->onlyOfficeService->isAvailable()) {
            $result = $this->generateViaOnlyOffice($sourcePath, $documentId, $width, $height);
            if ($result) {
                return $result;
            }
            error_log("OfficeThumbnailService: OnlyOffice conversion failed, trying fallback");
        }

        // Méthode 2: Fallback PDF tools (si disponibles)
        return $this->generateViaFallback($sourcePath, $documentId, $width, $height);
    }

    /**
     * Génère une miniature via OnlyOffice Conversion API
     */
    private function generateViaOnlyOffice(string $sourcePath, int $documentId, int $width, int $height): ?string
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
}
