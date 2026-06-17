<?php
/**
 * K-DOCS - Error Tracker
 * Centralise toutes les erreurs avec contexte
 * 
 * Usage:
 *   ErrorTracker::capture($exception);
 *   ErrorTracker::log('error', 'Message', ['context' => 'data']);
 */

namespace KDocs\Core;

class ErrorTracker
{
    private static string $logFile = '';
    private static bool $initialized = false;
    
    /**
     * Initialise le tracker
     */
    public static function init(): void
    {
        if (self::$initialized) return;
        
        self::$logFile = dirname(__DIR__, 2) . '/storage/logs/errors.log';
        
        // Créer le dossier logs si nécessaire
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Enregistrer les handlers
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
        
        self::$initialized = true;
    }
    
    /**
     * Capture une exception
     */
    public static function capture(\Throwable $e, array $context = []): void
    {
        self::init();
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'ERROR',
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::formatTrace($e->getTrace()),
            'context' => $context,
            'request' => self::getRequestContext(),
        ];
        
        self::write($entry);
    }
    
    /**
     * Log un message
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::init();
        
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
            'request' => self::getRequestContext(),
        ];
        
        self::write($entry);
    }
    
    /**
     * Handler d'exception global
     */
    public static function handleException(\Throwable $e): void
    {
        self::capture($e);
        
        // En production, afficher une erreur générique
        if (!($_ENV['APP_DEBUG'] ?? true)) {
            http_response_code(500);
            echo "Une erreur est survenue. Veuillez réessayer.";
            exit(1);
        }
        
        // En dev, afficher les détails
        throw $e;
    }
    
    /**
     * Handler d'erreur PHP
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Ignorer les erreurs supprimées avec @
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $levels = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_NOTICE => 'NOTICE',
            E_DEPRECATED => 'DEPRECATED',
        ];
        
        $level = $levels[$errno] ?? 'ERROR';
        
        self::log($level, $errstr, [
            'file' => $errfile,
            'line' => $errline,
            'errno' => $errno,
        ]);
        
        // Convertir en exception pour les erreurs fatales
        if (in_array($errno, [E_ERROR, E_USER_ERROR])) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }
        
        return false; // Laisser le handler PHP par défaut aussi
    }
    
    /**
     * Handler de shutdown (erreurs fatales)
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::log('FATAL', $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type'],
            ]);
        }
    }
    
    /**
     * Récupère les erreurs récentes
     */
    public static function getRecent(int $limit = 50): array
    {
        self::init();
        
        if (!file_exists(self::$logFile)) {
            return [];
        }
        
        $lines = file(self::$logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -$limit);
        
        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $entries[] = $entry;
            }
        }
        
        return array_reverse($entries);
    }
    
    /**
     * Compte les erreurs par niveau
     */
    public static function stats(): array
    {
        $recent = self::getRecent(1000);
        
        $stats = [
            'total' => count($recent),
            'by_level' => [],
            'last_error' => $recent[0] ?? null,
        ];
        
        foreach ($recent as $entry) {
            $level = $entry['level'] ?? 'UNKNOWN';
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
        }
        
        return $stats;
    }
    
    /**
     * Nettoie les vieux logs
     */
    public static function cleanup(int $daysToKeep = 30): int
    {
        self::init();
        
        if (!file_exists(self::$logFile)) {
            return 0;
        }
        
        $cutoff = strtotime("-{$daysToKeep} days");
        $lines = file(self::$logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $kept = [];
        $removed = 0;
        
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['timestamp'])) {
                $ts = strtotime($entry['timestamp']);
                if ($ts >= $cutoff) {
                    $kept[] = $line;
                } else {
                    $removed++;
                }
            }
        }
        
        file_put_contents(self::$logFile, implode("\n", $kept) . "\n");
        
        return $removed;
    }
    
    private static function write(array $entry): void
    {
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(self::$logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    
    private static function formatTrace(array $trace): array
    {
        $formatted = [];
        foreach (array_slice($trace, 0, 10) as $frame) {
            $formatted[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            ];
        }
        return $formatted;
    }
    
    private static function getRequestContext(): array
    {
        if (php_sapi_name() === 'cli') {
            return ['cli' => true, 'argv' => $_SERVER['argv'] ?? []];
        }
        
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        ];
    }
}
