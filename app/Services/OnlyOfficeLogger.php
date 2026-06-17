<?php
/**
 * K-Docs - OnlyOffice Logger
 * Logging dédié pour le diagnostic OnlyOffice
 */

namespace KDocs\Services;

use KDocs\Core\Config;

class OnlyOfficeLogger
{
    private static ?self $instance = null;
    private string $logFile;
    private bool $enabled;

    private function __construct()
    {
        $this->enabled = Config::get('onlyoffice.debug_log', false);
        $this->logFile = Config::basePath() . '/storage/logs/onlyoffice.log';

        // Créer le dossier logs si nécessaire
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log un message
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled && $level !== 'ERROR') {
            return; // Toujours loguer les erreurs
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $logLine = "[$timestamp] [$level] $message$contextStr\n";

        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log une requête HTTP entrante
     */
    public function logRequest(string $action, int $documentId, array $params = []): void
    {
        $this->info("Request: $action", array_merge([
            'document_id' => $documentId,
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ], $params));
    }

    /**
     * Log une réponse HTTP
     */
    public function logResponse(string $action, int $documentId, int $statusCode, array $data = []): void
    {
        $level = $statusCode >= 400 ? 'ERROR' : 'INFO';
        $this->log($level, "Response: $action", [
            'document_id' => $documentId,
            'status_code' => $statusCode,
            'data' => $data,
        ]);
    }

    /**
     * Log un callback OnlyOffice
     */
    public function logCallback(int $documentId, int $status, ?string $downloadUrl, array $body): void
    {
        $statusNames = [
            0 => 'NOT_FOUND',
            1 => 'EDITING',
            2 => 'READY_TO_SAVE',
            3 => 'SAVE_ERROR',
            4 => 'CLOSED_NO_CHANGES',
            6 => 'FORCE_SAVE',
            7 => 'FORCE_SAVE_ERROR',
        ];

        $statusName = $statusNames[$status] ?? "UNKNOWN($status)";

        $this->info("Callback received", [
            'document_id' => $documentId,
            'status' => $status,
            'status_name' => $statusName,
            'download_url' => $downloadUrl,
            'users' => $body['users'] ?? [],
            'key' => $body['key'] ?? null,
        ]);
    }

    /**
     * Log une tentative de téléchargement de fichier
     */
    public function logDownload(int $documentId, string $filePath, bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->info("File download", [
                'document_id' => $documentId,
                'file_path' => $filePath,
                'file_size' => file_exists($filePath) ? filesize($filePath) : 0,
            ]);
        } else {
            $this->error("File download failed", [
                'document_id' => $documentId,
                'file_path' => $filePath,
                'error' => $error,
            ]);
        }
    }

    /**
     * Log un test de connectivité
     */
    public function logConnectivityTest(string $url, bool $success, array $details = []): void
    {
        $level = $success ? 'INFO' : 'ERROR';
        $this->log($level, "Connectivity test", array_merge([
            'url' => $url,
            'success' => $success,
        ], $details));
    }

    /**
     * Retourne les dernières lignes du log
     */
    public function getRecentLogs(int $lines = 100): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $content = file_get_contents($this->logFile);
        $allLines = explode("\n", trim($content));

        return array_slice($allLines, -$lines);
    }

    /**
     * Efface le fichier de log
     */
    public function clear(): void
    {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }

    /**
     * Active/désactive le logging
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Vérifie si le logging est activé
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
