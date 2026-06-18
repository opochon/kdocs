<?php
/**
 * K-Docs - Logger de débogage
 * Actif uniquement si GEDV1_DEBUG_SESSION est défini (session audit agent).
 */

namespace KDocs\Core;

class DebugLogger
{
    private static ?string $logPath = null;

    private static function logPath(): ?string
    {
        if (self::$logPath !== null) {
            return self::$logPath === '' ? null : self::$logPath;
        }

        $session = getenv('GEDV1_DEBUG_SESSION') ?: ($_ENV['GEDV1_DEBUG_SESSION'] ?? '');
        if ($session === '') {
            self::$logPath = '';
            return null;
        }

        // Log NDJSON Cursor debug (session 4af063)
        $candidates = [
            'F:/DATA/DEVELOPPEMENT/htmleditor_v3/htmleditor/debug-' . $session . '.log',
            dirname(__DIR__, 3) . '/htmleditor/debug-' . $session . '.log',
        ];
        foreach ($candidates as $path) {
            if (is_dir(dirname($path))) {
                self::$logPath = $path;
                return $path;
            }
        }

        self::$logPath = '';
        return null;
    }

    private static function write(array $payload): void
    {
        $path = self::logPath();
        if ($path === null) {
            return;
        }

        $session = getenv('GEDV1_DEBUG_SESSION') ?: ($_ENV['GEDV1_DEBUG_SESSION'] ?? '4af063');
        $payload['sessionId'] = $session;
        $payload['timestamp'] = (int) round(microtime(true) * 1000);
        if (!isset($payload['id'])) {
            $payload['id'] = 'log_' . $payload['timestamp'] . '_' . substr(md5(json_encode($payload)), 0, 8);
        }

        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function log(string $location, string $message, array $data = [], ?string $hypothesisId = null): void
    {
        self::write([
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'hypothesisId' => $hypothesisId,
            'runId' => $data['runId'] ?? 'runtime',
        ]);
    }

    public static function logException(\Throwable $e, string $location, ?string $hypothesisId = null): void
    {
        self::write([
            'location' => $location,
            'message' => 'exception',
            'data' => [
                'type' => get_class($e),
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ],
            'hypothesisId' => $hypothesisId,
            'runId' => 'runtime',
        ]);
    }
}
