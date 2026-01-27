<?php
/**
 * K-Docs - Middleware d'indexation automatique (pseudo-cron)
 *
 * Verifie a chaque requete si l'indexation automatique doit etre lancee
 * Lance le worker en arriere-plan sans bloquer la requete
 */

namespace KDocs\Middleware;

use KDocs\Core\Database;
use KDocs\Services\FilesystemIndexer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AutoIndexMiddleware implements MiddlewareInterface
{
    private static bool $checked = false;

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Eviter les verifications multiples dans la meme requete
        if (!self::$checked) {
            self::$checked = true;
            $this->checkAutoIndex();
        }

        return $handler->handle($request);
    }

    /**
     * Verifie et lance l'indexation automatique si necessaire
     */
    private function checkAutoIndex(): void
    {
        try {
            // Verifier rapidement si l'auto-indexation est activee
            $db = Database::getInstance();

            $stmt = $db->query("
                SELECT `key`, value FROM settings
                WHERE `key` IN ('indexing_auto_enabled', 'indexing_interval_minutes', 'filesystem_last_index')
            ");
            $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            // Si pas active, sortir immediatement
            if (($settings['indexing_auto_enabled'] ?? '0') !== '1') {
                return;
            }

            $intervalMinutes = (int)($settings['indexing_interval_minutes'] ?? 60);
            $lastRun = isset($settings['filesystem_last_index']) ? (int)$settings['filesystem_last_index'] : 0;
            $intervalSeconds = $intervalMinutes * 60;

            // Si l'intervalle n'est pas depasse, sortir
            if ($lastRun > 0 && (time() - $lastRun) < $intervalSeconds) {
                return;
            }

            // Verifier qu'une indexation n'est pas deja en cours
            $indexer = new FilesystemIndexer();
            $progress = $indexer->getProgressData();

            if (in_array($progress['status'] ?? '', ['starting', 'running'])) {
                return; // Deja en cours
            }

            // Lancer l'indexation en arriere-plan
            $this->launchBackgroundIndexing();

        } catch (\Exception $e) {
            // Ignorer silencieusement les erreurs pour ne pas bloquer la navigation
            error_log("AutoIndexMiddleware error: " . $e->getMessage());
        }
    }

    /**
     * Lance le worker d'indexation en arriere-plan
     */
    private function launchBackgroundIndexing(): void
    {
        $indexer = new FilesystemIndexer();
        $indexer->initProgress();

        // Log
        $logFile = dirname(__DIR__, 2) . '/storage/logs/indexing_' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents($logFile, sprintf(
            "[%s] INFO: Demarrage indexation automatique (pseudo-cron)\n",
            date('Y-m-d H:i:s')
        ), FILE_APPEND);

        // Trouver PHP executable
        $phpPath = $this->findPhpExecutable();
        $workerPath = dirname(__DIR__) . '/workers/indexing_worker.php';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: start /B pour arriere-plan
            $cmd = "start /B \"\" \"$phpPath\" \"$workerPath\" 2>&1";
            pclose(popen($cmd, 'r'));
        } else {
            // Linux/Mac
            $cmd = "$phpPath $workerPath > /dev/null 2>&1 &";
            exec($cmd);
        }
    }

    /**
     * Trouve l'executable PHP
     */
    private function findPhpExecutable(): string
    {
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // Glob pour trouver PHP dans WAMP
        $glob = glob('C:/wamp64/bin/php/php*/php.exe');
        if (!empty($glob)) {
            rsort($glob);
            return $glob[0];
        }

        return 'php';
    }
}
