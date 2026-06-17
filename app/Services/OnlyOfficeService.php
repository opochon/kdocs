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
    private OnlyOfficeLogger $logger;

    private array $supportedFormats = [
        'docx', 'doc', 'odt', 'rtf', 'txt',
        'xlsx', 'xls', 'ods', 'csv',
        'pptx', 'ppt', 'odp',
        'pdf'
    ];

    public function __construct()
    {
        $this->config = Config::get('onlyoffice', []);
        $this->logger = OnlyOfficeLogger::getInstance();
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
            $this->logger->warning('Health check skipped: no server URL configured');
            return false;
        }

        $healthUrl = $serverUrl . '/healthcheck';
        $sslVerify = $this->config['ssl_verify'] ?? false;
        $timeout = $this->config['timeout'] ?? 10;

        $this->logger->debug('Health check starting', [
            'url' => $healthUrl,
            'ssl_verify' => $sslVerify,
            'timeout' => $timeout,
        ]);

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $sslVerify,
                'verify_peer_name' => $sslVerify,
            ]
        ]);

        try {
            $response = @file_get_contents($healthUrl, false, $context);
            $isHealthy = $response !== false && (trim($response) === 'true' || strpos($response, 'true') !== false);

            $this->logger->logConnectivityTest($healthUrl, $isHealthy, [
                'response' => substr($response ?: '', 0, 100),
            ]);

            return $isHealthy;
        } catch (\Exception $e) {
            $this->logger->error('Health check failed', [
                'url' => $healthUrl,
                'error' => $e->getMessage(),
            ]);
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
        // URL pour le navigateur (download)
        $appUrl = rtrim($this->config['app_url'] ?? Config::get('app.url', 'http://localhost/kdocs'), '/');
        // URL pour Docker (callback) - host.docker.internal sur Windows/Mac
        $callbackBaseUrl = rtrim($this->config['callback_url'] ?? $appUrl, '/');

        // Générer un token de sécurité pour les routes publiques
        $accessToken = \KDocs\Controllers\Api\OnlyOfficeApiController::generateAccessToken($document['id']);

        // Utiliser les routes publiques avec token pour Docker
        $fileUrl = $callbackBaseUrl . '/api/onlyoffice/public/download/' . $document['id'] . '/' . $accessToken;
        $callbackUrl = $callbackBaseUrl . '/api/onlyoffice/public/callback/' . $document['id'] . '/' . $accessToken;

        $config = [
            'document' => [
                'fileType' => pathinfo($document['filename'] ?? $document['original_filename'], PATHINFO_EXTENSION),
                'key' => $this->generateKey($document),
                'title' => $document['title'] ?? $document['original_filename'] ?? basename($document['filename']),
                'url' => $fileUrl,
                'permissions' => [
                    'chat' => false, // Déplacé de customization (déprécié)
                    'comment' => true,
                    'download' => true,
                    'edit' => $editMode,
                    'print' => true,
                ],
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
                    'comments' => true,
                    'compactHeader' => true,
                    'compactToolbar' => false,
                    'feedback' => false,
                    'forcesave' => true,
                    'help' => false,
                    'hideRightMenu' => true,
                    'features' => [
                        'tabStyle' => 'fill', // Remplace toolbarNoTabs (déprécié)
                        'tabBackground' => '#f1f1f1',
                    ],
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
     * Note: Inclut l'heure (arrondie 10 min) pour forcer le rafraîchissement du cache
     */
    private function generateKey(array $document): string
    {
        $timeSlot = floor(time() / 600);
        $data = ($document['id'] ?? 0) . '_' . ($document['checksum'] ?? $document['updated_at'] ?? '') . '_' . $timeSlot;
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

    /**
     * Retourne l'URL de callback (pour Docker)
     */
    public function getCallbackUrl(): string
    {
        return rtrim($this->config['callback_url'] ?? $this->config['app_url'] ?? '', '/');
    }

    /**
     * Test de connectivité complet
     * Vérifie que OnlyOffice peut atteindre le callback URL
     */
    public function testConnectivity(): array
    {
        $results = [
            'server_health' => false,
            'callback_reachable' => null, // null = non testé depuis Docker, true/false depuis le serveur
            'server_url' => $this->getServerUrl(),
            'callback_url' => $this->getCallbackUrl(),
            'ssl_verify' => $this->config['ssl_verify'] ?? false,
            'errors' => [],
            'warnings' => [],
        ];

        // Test 1: OnlyOffice server health
        $results['server_health'] = $this->checkServerHealth();
        if (!$results['server_health']) {
            $results['errors'][] = "OnlyOffice server non accessible à {$results['server_url']}";
        }

        // Test 2: Vérifier que l'URL de callback est configurée
        if (empty($results['callback_url'])) {
            $results['errors'][] = "URL de callback non configurée";
        } else {
            // On ne peut pas tester depuis PHP si Docker peut atteindre l'URL
            // Mais on peut vérifier que c'est une IP/hostname accessible
            $parsed = parse_url($results['callback_url']);
            $host = $parsed['host'] ?? '';

            if ($host === 'localhost' || $host === '127.0.0.1') {
                $results['warnings'][] = "callback_url utilise localhost - Docker ne pourra pas y accéder. Utilisez host.docker.internal ou l'IP locale.";
            }

            // Vérifier si on peut atteindre notre propre callback (test local)
            $testUrl = $results['callback_url'] . '/api/onlyoffice/status';
            $sslVerify = $this->config['ssl_verify'] ?? false;

            $ch = curl_init($testUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400) {
                $results['callback_reachable'] = true;
            } else {
                $results['callback_reachable'] = false;
                $results['errors'][] = "Callback URL non accessible (HTTP $httpCode): $error";
            }
        }

        // Log le résultat
        $this->logger->info('Connectivity test completed', $results);

        return $results;
    }

    /**
     * Retourne les logs récents
     */
    public function getRecentLogs(int $lines = 50): array
    {
        return $this->logger->getRecentLogs($lines);
    }

    /**
     * Efface les logs
     */
    public function clearLogs(): void
    {
        $this->logger->clear();
    }

    /**
     * Retourne la configuration actuelle (sans les secrets)
     */
    public function getDebugConfig(): array
    {
        return [
            'enabled' => $this->config['enabled'] ?? false,
            'server_url' => $this->config['server_url'] ?? '',
            'app_url' => $this->config['app_url'] ?? '',
            'callback_url' => $this->config['callback_url'] ?? '',
            'jwt_configured' => !empty($this->config['jwt_secret']),
            'ssl_verify' => $this->config['ssl_verify'] ?? false,
            'debug_log' => $this->config['debug_log'] ?? false,
            'timeout' => $this->config['timeout'] ?? 10,
        ];
    }
}
