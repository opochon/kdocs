<?php
/**
 * K-Docs - Logger de débogage pour capturer les erreurs runtime
 */

namespace KDocs\Core;

class DebugLogger
{
    private static string $logPath = __DIR__ . '/../../.cursor/debug.log';
    
    /**
     * Log un événement de débogage
     */
    public static function log(string $location, string $message, array $data = [], ?string $hypothesisId = null): void
    {
        $logEntry = [
            'id' => 'log_' . time() . '_' . uniqid(),
            'timestamp' => (int)(microtime(true) * 1000),
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'sessionId' => 'debug-session',
            'runId' => $_GET['runId'] ?? 'run1',
            'hypothesisId' => $hypothesisId
        ];
        
        // #region agent log
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents(self::$logPath, $logLine, FILE_APPEND | LOCK_EX);
        // #endregion
    }
    
    /**
     * Log une exception
     */
    public static function logException(\Throwable $e, string $location, ?string $hypothesisId = null): void
    {
        self::log($location, 'Exception caught', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ], $hypothesisId);
    }
}
