<?php
/**
 * K-Docs - Stockage KDrive d'Infomaniak via WebDAV
 * 
 * Configuration requise :
 * - Drive ID (extrait de l'URL kDrive : /drive/123456/)
 * - Username (email Infomaniak)
 * - Password (mot de passe d'application si 2FA activé)
 * 
 * URL WebDAV : https://{DriveID}.connect.kdrive.infomaniak.com
 */

namespace KDocs\Services\Storage;

use KDocs\Core\Config;

class KDriveStorage implements StorageInterface
{
    private string $webdavUrl;
    private string $username;
    private string $password;
    private string $basePath;
    private array $allowedExtensions;
    private array $ignoreFolders;
    private ?string $cacheDir = null;
    
    public function __construct()
    {
        $config = Config::load();
        $kdriveConfig = $config['kdrive'] ?? [];
        
        $driveId = $kdriveConfig['drive_id'] ?? '';
        $this->username = $kdriveConfig['username'] ?? '';
        $this->password = $kdriveConfig['password'] ?? '';
        
        if (empty($driveId) || empty($this->username) || empty($this->password)) {
            throw new \RuntimeException("Configuration KDrive incomplète. Veuillez configurer drive_id, username et password.");
        }
        
        // URL WebDAV : https://{DriveID}.connect.kdrive.infomaniak.com
        $this->webdavUrl = "https://{$driveId}.connect.kdrive.infomaniak.com";
        
        // Chemin de base dans KDrive (peut être vide pour racine, ou un dossier spécifique)
        $this->basePath = trim($kdriveConfig['base_path'] ?? '', '/');
        
        $storageConfig = $config['storage'] ?? [];
        $this->allowedExtensions = $storageConfig['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'doc', 'docx'];
        $this->ignoreFolders = $storageConfig['ignore_folders'] ?? ['.git', 'node_modules', 'vendor', '__MACOSX', 'Thumbs.db'];
        
        // Dossier de cache pour les fichiers téléchargés temporairement
        $this->cacheDir = $config['storage']['temp'] ?? __DIR__ . '/../../../storage/temp/kdrive_cache';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Effectue une requête WebDAV PROPFIND pour lister le contenu d'un dossier
     */
    private function propfind(string $path): ?array
    {
        $url = $this->webdavUrl . '/' . ltrim($path, '/');
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_HTTPHEADER => [
                'Depth: 1',
                'Content-Type: text/xml'
            ],
            CURLOPT_POSTFIELDS => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/><d:getcontentlength/><d:getcontenttype/><d:getlastmodified/></d:prop></d:propfind>',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("KDrive WebDAV error: $curlError");
            return null;
        }
        
        if ($httpCode !== 207 && $httpCode !== 200) {
            error_log("KDrive WebDAV HTTP $httpCode: $response");
            return null;
        }
        
        return $this->parsePropfindResponse($response);
    }
    
    /**
     * Parse la réponse XML PROPFIND
     */
    private function parsePropfindResponse(string $xml): array
    {
        $folders = [];
        $files = [];
        
        try {
            $dom = new \DOMDocument();
            @$dom->loadXML($xml);
            
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            
            $responses = $xpath->query('//d:response');
            
            foreach ($responses as $response) {
                $href = $xpath->evaluate('string(d:href)', $response);
                if (empty($href)) continue;
                
                // Ignorer le dossier parent
                if (substr($href, -2) === '/.' || substr($href, -3) === '/..') {
                    continue;
                }
                
                $displayName = $xpath->evaluate('string(d:propstat/d:prop/d:displayname)', $response);
                $contentLength = $xpath->evaluate('string(d:propstat/d:prop/d:getcontentlength)', $response);
                $contentType = $xpath->evaluate('string(d:propstat/d:prop/d:getcontenttype)', $response);
                $lastModified = $xpath->evaluate('string(d:propstat/d:prop/d:getlastmodified)', $response);
                
                // Extraire le chemin relatif
                $relativePath = str_replace($this->webdavUrl . '/', '', $href);
                if ($this->basePath) {
                    $relativePath = str_replace($this->basePath . '/', '', $relativePath);
                }
                $relativePath = trim($relativePath, '/');
                
                // Ignorer les dossiers système
                $name = basename($relativePath);
                if (in_array($name, $this->ignoreFolders)) {
                    continue;
                }
                
                // Vérifier si c'est une collection (dossier)
                $resourceType = $xpath->query('d:propstat/d:prop/d:resourcetype/d:collection', $response);
                $isCollection = $resourceType->length > 0 || empty($contentLength);
                
                if ($isCollection) {
                    // C'est un dossier
                    $folders[] = [
                        'name' => $displayName ?: $name,
                        'path' => $relativePath,
                        'full_path' => $href,
                        'modified' => $lastModified ? strtotime($lastModified) : null,
                        'file_count' => 0, // Nécessiterait une requête supplémentaire
                    ];
                } else {
                    // C'est un fichier
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($ext, $this->allowedExtensions)) {
                        $files[] = [
                            'name' => $displayName ?: $name,
                            'path' => $relativePath,
                            'full_path' => $href,
                            'size' => (int)$contentLength,
                            'modified' => $lastModified ? strtotime($lastModified) : null,
                            'extension' => $ext,
                            'mime_type' => $contentType ?: 'application/octet-stream',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("KDrive parse error: " . $e->getMessage());
            return ['folders' => [], 'files' => [], 'error' => $e->getMessage()];
        }
        
        usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        
        return ['folders' => $folders, 'files' => $files];
    }
    
    public function readDirectory(string $relativePath = '', bool $includeSubfolders = false): array
    {
        $webdavPath = $this->basePath;
        if ($relativePath) {
            $webdavPath = $webdavPath ? $webdavPath . '/' . $relativePath : $relativePath;
        }
        $webdavPath = trim($webdavPath, '/');
        
        $result = $this->propfind($webdavPath);
        if (!$result) {
            return ['folders' => [], 'files' => [], 'error' => "Impossible de lire le dossier KDrive"];
        }
        
        if ($includeSubfolders) {
            // Récursif : traiter chaque sous-dossier
            foreach ($result['folders'] as &$folder) {
                $subContent = $this->readDirectory($folder['path'], true);
                $folder['file_count'] = count($subContent['files']);
                $folder['subfolders'] = $subContent['folders'];
            }
        }
        
        return $result;
    }
    
    public function getFileInfo(string $relativePath): ?array
    {
        $webdavPath = $this->basePath ? $this->basePath . '/' . $relativePath : $relativePath;
        $webdavPath = trim($webdavPath, '/');
        
        $url = $this->webdavUrl . '/' . $webdavPath;
        
        // Utiliser HEAD pour obtenir les infos sans télécharger
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        // Extraire Last-Modified depuis les headers
        preg_match('/Last-Modified:\s*(.+)/i', $response, $matches);
        $lastModified = isset($matches[1]) ? strtotime($matches[1]) : null;
        
        return [
            'name' => basename($relativePath),
            'path' => $relativePath,
            'full_path' => $url,
            'size' => (int)$contentLength,
            'modified' => $lastModified,
            'extension' => strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)),
            'mime_type' => $contentType ?: 'application/octet-stream',
            'checksum' => null, // Nécessiterait le téléchargement complet
        ];
    }
    
    public function downloadFile(string $relativePath, string $localPath): bool
    {
        $webdavPath = $this->basePath ? $this->basePath . '/' . $relativePath : $relativePath;
        $webdavPath = trim($webdavPath, '/');
        
        $url = $this->webdavUrl . '/' . $webdavPath;
        
        $ch = curl_init($url);
        $fp = fopen($localPath, 'w');
        
        if (!$fp) {
            return false;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_TIMEOUT => 300, // 5 minutes pour les gros fichiers
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        
        if (!$success || $httpCode !== 200) {
            @unlink($localPath);
            return false;
        }
        
        return true;
    }
    
    public function checkFileModified(string $relativePath, ?string $knownChecksum = null, ?int $knownModified = null): bool
    {
        $fileInfo = $this->getFileInfo($relativePath);
        if (!$fileInfo) {
            return false;
        }
        
        // Pour KDrive, on compare principalement la date de modification
        // car le checksum nécessiterait le téléchargement complet
        if ($knownModified && $fileInfo['modified'] && $fileInfo['modified'] > $knownModified) {
            return true;
        }
        
        return false;
    }
    
    public function getBasePath(): string
    {
        return $this->webdavUrl . ($this->basePath ? '/' . $this->basePath : '');
    }
}
