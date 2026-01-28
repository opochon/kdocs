<?php
/**
 * K-Docs - OnlyOffice Service
 * Intégration avec OnlyOffice Document Server pour la prévisualisation Office
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class OnlyOfficeService
{
    private array $config;
    private static ?bool $serverAvailable = null;
    private static int $lastCheck = 0;
    private const CHECK_INTERVAL = 300; // 5 minutes cache

    private array $supportedFormats = [
        'docx', 'doc', 'odt', 'rtf', 'txt',
        'xlsx', 'xls', 'ods', 'csv',
        'pptx', 'ppt', 'odp',
        'pdf'
    ];

    public function __construct()
    {
        $this->config = Config::get('onlyoffice', []);
    }

    /**
     * Vérifie si OnlyOffice est activé dans la config
     */
    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? false) && !empty($this->config['server_url']);
    }

    /**
     * Vérifie si le serveur OnlyOffice est réellement accessible
     * Résultat mis en cache pendant CHECK_INTERVAL secondes
     */
    public function isAvailable(): bool
    {
        // Si pas activé, pas la peine de vérifier
        if (!$this->isEnabled()) {
            return false;
        }

        // Utiliser le cache si récent
        $now = time();
        if (self::$serverAvailable !== null && ($now - self::$lastCheck) < self::CHECK_INTERVAL) {
            return self::$serverAvailable;
        }

        // Vérifier la connectivité
        self::$lastCheck = $now;
        self::$serverAvailable = $this->checkServerHealth();

        return self::$serverAvailable;
    }

    /**
     * Vérifie la santé du serveur OnlyOffice (healthcheck endpoint)
     */
    private function checkServerHealth(): bool
    {
        $serverUrl = $this->getServerUrl();
        if (empty($serverUrl)) {
            return false;
        }

        $healthUrl = $serverUrl . '/healthcheck';

        $context = stream_context_create([
            'http' => [
                'timeout' => 3, // Timeout court pour ne pas bloquer
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        try {
            $response = @file_get_contents($healthUrl, false, $context);
            // OnlyOffice healthcheck retourne "true" si OK
            return $response !== false && (trim($response) === 'true' || strpos($response, 'true') !== false);
        } catch (\Exception $e) {
            error_log("OnlyOffice health check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Réinitialise le cache de disponibilité (utile après config change)
     */
    public static function resetAvailabilityCache(): void
    {
        self::$serverAvailable = null;
        self::$lastCheck = 0;
    }

    /**
     * Vérifie si le format de fichier est supporté
     */
    public function isSupported(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $this->supportedFormats);
    }

    /**
     * Retourne le type de document (word, cell, slide)
     */
    public function getDocumentType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $wordFormats = ['docx', 'doc', 'odt', 'rtf', 'txt'];
        $cellFormats = ['xlsx', 'xls', 'ods', 'csv'];
        $slideFormats = ['pptx', 'ppt', 'odp'];

        if (in_array($ext, $wordFormats)) return 'word';
        if (in_array($ext, $cellFormats)) return 'cell';
        if (in_array($ext, $slideFormats)) return 'slide';

        return 'word'; // default pour PDF et autres
    }

    /**
     * Génère la configuration pour l'éditeur OnlyOffice
     */
    public function generateConfig(array $document, int $userId, string $userName = '', bool $editMode = false): array
    {
        $basePath = Config::basePath();
        $appUrl = rtrim($this->config['app_url'] ?? Config::get('app.url', 'http://localhost/kdocs'), '/');

        $fileUrl = $appUrl . '/api/onlyoffice/download/' . $document['id'];
        $callbackUrl = $appUrl . '/api/onlyoffice/callback/' . $document['id'];

        $config = [
            'document' => [
                'fileType' => pathinfo($document['filename'] ?? $document['original_filename'], PATHINFO_EXTENSION),
                'key' => $this->generateKey($document),
                'title' => $document['title'] ?? $document['original_filename'] ?? basename($document['filename']),
                'url' => $fileUrl,
            ],
            'documentType' => $this->getDocumentType($document['filename'] ?? $document['original_filename']),
            'editorConfig' => [
                'mode' => $editMode ? 'edit' : 'view',
                'callbackUrl' => $editMode ? $callbackUrl : null,
                'lang' => 'fr',
                'user' => [
                    'id' => (string)$userId,
                    'name' => $userName ?: 'Utilisateur ' . $userId,
                ],
                'customization' => [
                    'autosave' => true,
                    'chat' => false,
                    'comments' => true,
                    'compactHeader' => true,
                    'compactToolbar' => false,
                    'feedback' => false,
                    'forcesave' => true,
                    'help' => false,
                    'hideRightMenu' => true,
                    'toolbarNoTabs' => true,
                    'logo' => [
                        'image' => $appUrl . '/public/images/logo.png',
                        'imageDark' => $appUrl . '/public/images/logo-dark.png',
                    ],
                ],
            ],
            'height' => '100%',
            'width' => '100%',
        ];

        // Signer avec JWT si configuré
        if (!empty($this->config['jwt_secret'])) {
            $config['token'] = $this->generateToken($config);
        }

        return $config;
    }

    /**
     * Génère une clé unique pour le document (utilisée pour le cache OnlyOffice)
     */
    private function generateKey(array $document): string
    {
        $data = ($document['id'] ?? 0) . '_' . ($document['checksum'] ?? $document['updated_at'] ?? $document['created_at'] ?? time());
        return substr(md5($data), 0, 20);
    }

    /**
     * Génère un token JWT pour la configuration
     */
    private function generateToken(array $payload): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, $this->config['jwt_secret'], true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
    }

    /**
     * Encode en base64 URL-safe
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Vérifie un token JWT entrant (callback d'OnlyOffice)
     */
    public function verifyToken(string $token): ?array
    {
        if (empty($this->config['jwt_secret'])) {
            return null; // Pas de vérification si pas de secret
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, $this->config['jwt_secret'], true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return json_decode($this->base64UrlDecode($payload), true);
    }

    /**
     * Décode depuis base64 URL-safe
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Retourne l'URL du serveur OnlyOffice
     */
    public function getServerUrl(): string
    {
        return rtrim($this->config['server_url'] ?? '', '/');
    }

    /**
     * Retourne la liste des formats supportés
     */
    public function getSupportedFormats(): array
    {
        return $this->supportedFormats;
    }
}
