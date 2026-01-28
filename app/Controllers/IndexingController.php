<?php
/**
 * Controleur pour l'indexation des documents
 *
 * Index les documents de storage/documents pour une recherche optimale
 * Supporte l'indexation manuelle avec progression en temps reel
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\FilesystemIndexer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class IndexingController
{
    private string $storagePath;
    private string $logDir;
    private $db;

    public function __construct()
    {
        $config = Config::load();
        $this->storagePath = Config::get('storage.base_path', dirname(__DIR__, 2) . '/storage/documents');
        $this->logDir = dirname(__DIR__, 2) . '/storage/logs';
        $this->db = Database::getInstance();

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Page principale d'indexation
     */
    public function index(Request $request, Response $response): Response
    {
        $indexer = new FilesystemIndexer();
        $progress = $indexer->getProgressData();

        $status = [
            'is_running' => in_array($progress['status'] ?? '', ['starting', 'running']),
            'progress' => $progress,
            'stats' => [
                'total_documents' => $this->getDocumentCount(),
                'total_folders' => $this->getFolderCount()
            ]
        ];

        $logs = $this->getRecentLogs(30);
        $settings = $this->getIndexingSettings();

        $user = $request->getAttribute('user');
        $pageTitle = 'Indexation';

        ob_start();
        include __DIR__ . '/../../templates/admin/indexing.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../templates/layouts/main.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * API: Recuperer le statut et la progression
     */
    public function status(Request $request, Response $response): Response
    {
        $indexer = new FilesystemIndexer();
        $progress = $indexer->getProgressData();

        $status = [
            'is_running' => in_array($progress['status'] ?? '', ['starting', 'running']),
            'progress' => $progress,
            'stats' => [
                'total_documents' => $this->getDocumentCount(),
                'total_folders' => $this->getFolderCount()
            ]
        ];

        $response->getBody()->write(json_encode([
            'success' => true,
            'status' => $status
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Lancer l'indexation
     */
    public function start(Request $request, Response $response): Response
    {
        $indexer = new FilesystemIndexer();
        $progress = $indexer->getProgressData();

        // Verifier si deja en cours (avec timeout de 30s pour status stale)
        $status = $progress['status'] ?? '';
        if (in_array($status, ['starting', 'running'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Une indexation est deja en cours'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }

        // Initialiser la progression
        $indexer->initProgress();

        $this->log("INFO", "Demarrage indexation manuelle");

        // Lancer le worker en arriere-plan
        $this->launchBackgroundWorker();

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Indexation demarree en arriere-plan'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Lance le worker en arriere-plan (cross-platform)
     */
    private function launchBackgroundWorker(): void
    {
        $phpPath = $this->findPhpExecutable();
        $workerPath = dirname(__DIR__) . '/workers/indexing_worker.php';
        $logFile = $this->logDir . '/worker_launch.log';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: utiliser WScript.Shell via PowerShell ou COM
            // Methode 1: Via wmic (plus fiable depuis Apache)
            $cmd = sprintf(
                'wmic process call create "cmd /c \"%s\" \"%s\"" 2>&1',
                str_replace('/', '\\', $phpPath),
                str_replace('/', '\\', $workerPath)
            );
            @exec($cmd, $output, $code);

            // Log pour debug
            @file_put_contents($logFile, sprintf(
                "[%s] Launch cmd: %s\nOutput: %s\nCode: %d\n",
                date('Y-m-d H:i:s'),
                $cmd,
                implode("\n", $output ?? []),
                $code
            ), FILE_APPEND);
        } else {
            // Linux/Mac
            $cmd = "$phpPath $workerPath > /dev/null 2>&1 &";
            exec($cmd);
        }
    }

    /**
     * API: Arreter l'indexation en cours
     */
    public function stop(Request $request, Response $response): Response
    {
        $stopFile = dirname(__DIR__, 2) . '/storage/.indexing.stop';
        file_put_contents($stopFile, time());

        // Reinitialiser la progression
        $indexer = new FilesystemIndexer();
        $indexer->resetProgress();

        $this->log("INFO", "Arret demande par utilisateur");

        // Nettoyer le fichier stop apres quelques secondes
        register_shutdown_function(function() use ($stopFile) {
            sleep(2);
            @unlink($stopFile);
        });

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Arret demande'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Recuperer les logs
     */
    public function logs(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = min((int)($params['limit'] ?? 50), 200);

        $logs = $this->getRecentLogs($limit);

        $response->getBody()->write(json_encode([
            'success' => true,
            'logs' => $logs
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Effacer les logs
     */
    public function clearLogs(Request $request, Response $response): Response
    {
        $logFile = $this->logDir . '/indexing_' . date('Y-m-d') . '.log';
        if (file_exists($logFile)) {
            @unlink($logFile);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Logs effaces'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Sauvegarder les parametres
     */
    public function saveSettings(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true) ?? [];

        $autoEnabled = !empty($data['auto_enabled']);
        $interval = max(5, min(1440, (int)($data['interval_minutes'] ?? 60)));

        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(100) PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");

            $stmt = $this->db->prepare("
                INSERT INTO settings (`key`, value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
            ");

            $stmt->execute(['indexing_auto_enabled', $autoEnabled ? '1' : '0']);
            $stmt->execute(['indexing_interval_minutes', (string)$interval]);

            $this->log("INFO", "Parametres modifies", [
                'auto_enabled' => $autoEnabled,
                'interval' => $interval
            ]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Parametres sauvegardes'
            ]));
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Trouve l'executable PHP
     */
    private function findPhpExecutable(): string
    {
        // Essayer PHP_BINARY d'abord
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // Windows WAMP
        $wampPaths = [
            'C:/wamp64/bin/php/php8.2.0/php.exe',
            'C:/wamp64/bin/php/php8.1.0/php.exe',
            'C:/wamp64/bin/php/php8.0.0/php.exe',
        ];
        foreach ($wampPaths as $path) {
            if (file_exists($path)) return $path;
        }

        // Glob pour trouver PHP dans WAMP
        $glob = glob('C:/wamp64/bin/php/php*/php.exe');
        if (!empty($glob)) {
            rsort($glob);
            return $glob[0];
        }

        return 'php';
    }

    /**
     * Recupere les parametres d'indexation
     */
    private function getIndexingSettings(): array
    {
        $defaults = [
            'auto_enabled' => false,
            'interval_minutes' => 60,
            'last_run' => null
        ];

        try {
            $stmt = $this->db->query("
                SELECT `key`, value FROM settings
                WHERE `key` IN ('indexing_auto_enabled', 'indexing_interval_minutes', 'indexing_last_run', 'filesystem_last_index')
            ");
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            $defaults['auto_enabled'] = ($rows['indexing_auto_enabled'] ?? '0') === '1';
            $defaults['interval_minutes'] = (int)($rows['indexing_interval_minutes'] ?? 60);
            $defaults['last_run'] = isset($rows['filesystem_last_index']) ? (int)$rows['filesystem_last_index'] : null;
        } catch (\Exception $e) {
            // Table n'existe pas encore
        }

        return $defaults;
    }

    /**
     * Compte les documents
     */
    private function getDocumentCount(): int
    {
        try {
            return (int) $this->db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL")->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Compte les dossiers
     */
    private function getFolderCount(): int
    {
        try {
            return (int) $this->db->query("SELECT COUNT(*) FROM document_folders")->fetchColumn();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Ecrire dans le log
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $logFile = $this->logDir . '/indexing_' . date('Y-m-d') . '.log';

        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );

        @file_put_contents($logFile, $line, FILE_APPEND);
    }

    /**
     * Recuperer les logs recents
     */
    private function getRecentLogs(int $limit = 50): array
    {
        $logs = [];
        $logFile = $this->logDir . '/indexing_' . date('Y-m-d') . '.log';

        if (!file_exists($logFile)) {
            return $logs;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $logs;

        $lines = array_slice($lines, -$limit);

        foreach ($lines as $line) {
            if (preg_match('/^\[([^\]]+)\]\s+(\w+):\s+(.+)$/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'message' => $matches[3]
                ];
            }
        }

        return array_reverse($logs);
    }

    /**
     * Worker endpoint (pour appel via URL)
     */
    public function worker(Request $request, Response $response): Response
    {
        // Desactiver la limite de temps
        set_time_limit(0);
        ignore_user_abort(true);

        // Fermer la connexion immediatement pour liberer le navigateur
        if (function_exists('fastcgi_finish_request')) {
            $response->getBody()->write(json_encode(['success' => true, 'message' => 'Worker demarre']));
            $response = $response->withHeader('Content-Type', 'application/json');
            fastcgi_finish_request();
        }

        // Executer l'indexation
        $indexer = new FilesystemIndexer();
        $indexer->indexAll(true);

        return $response;
    }
}
