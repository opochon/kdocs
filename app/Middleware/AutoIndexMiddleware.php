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
                WHERE `key` IN ('indexing_auto_enabled', 'indexing_interval_minutes', 'filesystem_last_index', 'indexing_last_attempt')
            ");
            $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            // Si pas active, sortir immediatement
            if (($settings['indexing_auto_enabled'] ?? '0') !== '1') {
                return;
            }

            $intervalMinutes = (int)($settings['indexing_interval_minutes'] ?? 60);
            $lastRun = isset($settings['filesystem_last_index']) ? (int)$settings['filesystem_last_index'] : 0;
            $lastAttempt = isset($settings['indexing_last_attempt']) ? (int)$settings['indexing_last_attempt'] : 0;
            $intervalSeconds = $intervalMinutes * 60;

            // Si l'intervalle n'est pas depasse depuis la derniere indexation reussie, sortir
            if ($lastRun > 0 && (time() - $lastRun) < $intervalSeconds) {
                return;
            }

            // Eviter les tentatives trop frequentes (cooldown de 5 minutes apres un echec)
            $cooldownSeconds = 300; // 5 minutes
            if ($lastAttempt > 0 && (time() - $lastAttempt) < $cooldownSeconds) {
                return;
            }

            // Verifier qu'une indexation n'est pas deja en cours
            $indexer = new FilesystemIndexer();
            $progress = $indexer->getProgressData();

            if (in_array($progress['status'] ?? '', ['starting', 'running'])) {
                return; // Deja en cours
            }

            // Enregistrer cette tentative
            $this->recordAttempt($db);

            // Lancer l'indexation en arriere-plan
            $this->launchBackgroundIndexing();

        } catch (\Exception $e) {
            // Ignorer silencieusement les erreurs pour ne pas bloquer la navigation
            error_log("AutoIndexMiddleware error: " . $e->getMessage());
        }
    }

    /**
     * Enregistre une tentative d'indexation
     */
    private function recordAttempt($db): void
    {
        try {
            $stmt = $db->prepare("INSERT INTO settings (`key`, value) VALUES ('indexing_last_attempt', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            $stmt->execute([(string)time()]);
        } catch (\Exception $e) {
            // Ignorer
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
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/indexing_' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, sprintf(
            "[%s] INFO: Demarrage indexation automatique (pseudo-cron)\n",
            date('Y-m-d H:i:s')
        ), FILE_APPEND);

        // Trouver PHP executable
        $phpPath = $this->findPhpExecutable();
        $workerPath = dirname(__DIR__) . '/workers/indexing_worker.php';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: utiliser VBS pour lancement en arriere-plan (plus fiable depuis Apache)
            $phpPathWin = str_replace('/', '\\', $phpPath);
            $workerPathWin = str_replace('/', '\\', $workerPath);

            // Creer un script VBS temporaire
            $vbsScript = sys_get_temp_dir() . '\\kdocs_indexing_' . uniqid() . '.vbs';
            $vbsContent = "Set WshShell = CreateObject(\"WScript.Shell\")\n";
            $vbsContent .= "WshShell.Run \"\"\"$phpPathWin\"\" \"\"$workerPathWin\"\"\", 0, False\n";
            $vbsContent .= "Set WshShell = Nothing\n";

            if (@file_put_contents($vbsScript, $vbsContent)) {
                $command = 'cscript.exe //nologo "' . $vbsScript . '"';
                pclose(popen($command, 'r'));

                // Nettoyer le script VBS après un court delai
                usleep(100000); // 100ms
                @unlink($vbsScript);
            }
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
