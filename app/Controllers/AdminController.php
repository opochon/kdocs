<?php
/**
 * K-Docs - Contrôleur d'administration
 */

namespace KDocs\Controllers;

use KDocs\Core\Config;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    /**
     * Helper pour rendre un template
     */
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Exécute une commande shell avec timeout (évite blocage LibreOffice/Ghostscript en dev).
     */
    private function runShellWithTimeout(string $command, int $timeoutSeconds = 3): ?string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $start = time();
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) >= $timeoutSeconds) {
                proc_terminate($process);
                break;
            }
            usleep(100_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output !== '' ? $output : null;
    }

    /**
     * Page d'accueil de l'administration
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('AdminController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath()
        ], 'A');
        // #endregion
        
        $user = $request->getAttribute('user');
        
        $db = Database::getInstance();
        
        // Statistiques générales
        $stats = [
            'users' => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'documents' => (int)$db->query("SELECT COUNT(*) FROM documents")->fetchColumn(),
            'tasks' => (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn(),
            'document_types' => (int)$db->query("SELECT COUNT(*) FROM document_types")->fetchColumn(),
            'correspondents' => (int)$db->query("SELECT COUNT(*) FROM correspondents")->fetchColumn(),
        ];
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/index.php', [
            'stats' => $stats,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Administration - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Administration'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Liste des utilisateurs
     */
    public function users(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('AdminController::users', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        $db = Database::getInstance();
        $users = $db->query("
            SELECT u.*, 
                   COUNT(DISTINCT d.id) as document_count,
                   COUNT(DISTINCT t.id) as task_count
            FROM users u
            LEFT JOIN documents d ON d.created_by = u.id
            LEFT JOIN tasks t ON t.assigned_to = u.id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/users.php', [
            'users' => $users,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Gestion des utilisateurs - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Gestion des utilisateurs'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Configuration système
     */
    public function settings(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('AdminController::settings', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/settings.php', []);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Configuration - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Configuration'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Page de diagnostic système
     */
    public function diagnostic(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $db = Database::getInstance();

        // Status IA
        $aiProvider = new \KDocs\Services\AIProviderService();
        $aiStatus = $aiProvider->getStatus();

        // Comptage règles
        $rulesCount = 0;
        try {
            $rulesCount = (int)$db->query("SELECT COUNT(*) FROM attribution_rules WHERE active = 1")->fetchColumn();
        } catch (\Exception $e) {
            // Table peut ne pas exister
        }

        // Training status
        $training = [
            'enabled' => Config::get('ai.training.enabled', false),
            'corrections' => 0,
            'min_similarity' => Config::get('ai.training.min_similarity', 0.85),
        ];
        $trainingFile = Config::get('ai.training.file');
        if ($trainingFile && file_exists($trainingFile)) {
            $data = json_decode(file_get_contents($trainingFile), true);
            $training['corrections'] = count($data['corrections'] ?? []);
        }

        // Embeddings status
        $embeddings = [
            'enabled' => Config::get('embeddings.enabled', false),
            'provider' => Config::get('embeddings.provider', 'ollama'),
            'dimensions' => Config::get('embeddings.dimensions', 768),
            'total_docs' => 0,
            'docs_with_embedding' => 0,
        ];
        try {
            $embeddings['total_docs'] = (int)$db->query("SELECT COUNT(*) FROM documents")->fetchColumn();
            $embeddings['docs_with_embedding'] = (int)$db->query("SELECT COUNT(*) FROM documents WHERE embedding IS NOT NULL")->fetchColumn();
        } catch (\Exception $e) {
            // Ignore
        }

        // Tools status
        $tools = [];
        $toolsConfig = [
            'Tesseract OCR' => Config::get('ocr.tesseract_path'),
            'Ghostscript' => Config::get('tools.ghostscript'),
            'LibreOffice' => Config::get('tools.libreoffice'),
            'pdftotext' => Config::get('tools.pdftotext'),
            'pdftoppm' => Config::get('tools.pdftoppm'),
        ];

        foreach ($toolsConfig as $name => $path) {
            $installed = file_exists($path);
            $version = null;

            if ($installed) {
                switch ($name) {
                    case 'Tesseract OCR':
                        $output = $this->runShellWithTimeout("\"$path\" --version 2>&1") ?? '';
                        preg_match('/tesseract\s+([\d.]+)/i', $output, $m);
                        $version = $m[1] ?? 'unknown';
                        break;
                    case 'Ghostscript':
                        $version = trim($this->runShellWithTimeout("\"$path\" --version 2>&1") ?? 'unknown');
                        break;
                    case 'LibreOffice':
                        // soffice.exe --version peut bloquer indéfiniment sous Windows
                        $version = $installed ? 'installed' : null;
                        break;
                    default:
                        $version = 'installed';
                }
            }

            $tools[$name] = [
                'installed' => $installed,
                'path' => $path,
                'version' => $version,
            ];
        }

        // Services status
        $services = [];

        // MySQL
        try {
            $mysqlVersion = $db->query("SELECT VERSION()")->fetchColumn();
            $services['mysql'] = ['connected' => true, 'version' => $mysqlVersion];
        } catch (\Exception $e) {
            $services['mysql'] = ['connected' => false, 'error' => $e->getMessage()];
        }

        // OnlyOffice
        $onlyOfficeEnabled = Config::get('onlyoffice.enabled', false);
        if ($onlyOfficeEnabled) {
            $url = Config::get('onlyoffice.server_url');
            $ch = curl_init(rtrim($url, '/') . '/healthcheck');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $services['onlyoffice'] = [
                'status' => ($httpCode === 200 && $result === 'true') ? 'connected' : 'error',
                'url' => $url,
            ];
        } else {
            $services['onlyoffice'] = ['status' => 'disabled', 'url' => null];
        }

        // Ollama
        $ollamaUrl = Config::get('ollama.url', 'http://localhost:11434');
        $ch = curl_init("$ollamaUrl/api/tags");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ollamaData = json_decode($result, true);
        $services['ollama'] = [
            'connected' => $httpCode === 200,
            'url' => $ollamaUrl,
            'models_count' => count($ollamaData['models'] ?? []),
        ];

        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/diagnostic.php', [
            'aiStatus' => $aiStatus,
            'rulesCount' => $rulesCount,
            'training' => $training,
            'embeddings' => $embeddings,
            'tools' => $tools,
            'services' => $services,
            'ingestEngine' => (new \KDocs\Services\Ingest\IngestEngineRouter())->getStatus(),
        ]);

        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Diagnostic - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Diagnostic Système'
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Statistiques d'utilisation de l'API Claude
     */
    public function apiUsage(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $db = Database::getInstance();
        
        // Vérifier si la table existe
        $tableExists = false;
        try {
            $db->query("SELECT 1 FROM api_usage_logs LIMIT 1");
            $tableExists = true;
        } catch (\Exception $e) {
            // Table n'existe pas encore
        }
        
        $stats = [];
        $recentLogs = [];
        $period = $request->getQueryParams()['period'] ?? '30'; // Par défaut 30 jours
        
        if ($tableExists) {
            // Statistiques globales
            $stats = [
                'total_requests' => (int)$db->query("SELECT COUNT(*) FROM api_usage_logs")->fetchColumn(),
                'successful_requests' => (int)$db->query("SELECT COUNT(*) FROM api_usage_logs WHERE success = 1")->fetchColumn(),
                'failed_requests' => (int)$db->query("SELECT COUNT(*) FROM api_usage_logs WHERE success = 0")->fetchColumn(),
                'total_input_tokens' => (int)$db->query("SELECT SUM(input_tokens) FROM api_usage_logs")->fetchColumn() ?: 0,
                'total_output_tokens' => (int)$db->query("SELECT SUM(output_tokens) FROM api_usage_logs")->fetchColumn() ?: 0,
                'total_tokens' => (int)$db->query("SELECT SUM(total_tokens) FROM api_usage_logs")->fetchColumn() ?: 0,
                'total_cost_usd' => (float)$db->query("SELECT SUM(estimated_cost_usd) FROM api_usage_logs")->fetchColumn() ?: 0,
            ];
            
            // Statistiques par période
            $periodDays = (int)$period;
            if ($period === 'all') {
                $periodStats = $db->query("
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as requests,
                        SUM(input_tokens) as input_tokens,
                        SUM(output_tokens) as output_tokens,
                        SUM(total_tokens) as total_tokens,
                        SUM(estimated_cost_usd) as cost_usd
                    FROM api_usage_logs
                    GROUP BY DATE(created_at)
                    ORDER BY date DESC
                ")->fetchAll(\PDO::FETCH_ASSOC);
            } else {
                $stmt = $db->prepare("
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as requests,
                        SUM(input_tokens) as input_tokens,
                        SUM(output_tokens) as output_tokens,
                        SUM(total_tokens) as total_tokens,
                        SUM(estimated_cost_usd) as cost_usd
                    FROM api_usage_logs
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY date DESC
                ");
                $stmt->execute([$periodDays]);
                $periodStats = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
            
            // Statistiques par type de requête
            $typeStats = $db->query("
                SELECT 
                    request_type,
                    COUNT(*) as count,
                    SUM(input_tokens) as input_tokens,
                    SUM(output_tokens) as output_tokens,
                    SUM(estimated_cost_usd) as cost_usd
                FROM api_usage_logs
                GROUP BY request_type
            ")->fetchAll(\PDO::FETCH_ASSOC);
            
            // Logs récents
            $recentLogs = $db->query("
                SELECT 
                    l.*,
                    d.original_filename as document_name
                FROM api_usage_logs l
                LEFT JOIN documents d ON d.id = l.document_id
                ORDER BY l.created_at DESC
                LIMIT 50
            ")->fetchAll(\PDO::FETCH_ASSOC);
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/api_usage.php', [
            'stats' => $stats,
            'periodStats' => $periodStats ?? [],
            'typeStats' => $typeStats ?? [],
            'recentLogs' => $recentLogs,
            'period' => $period,
            'tableExists' => $tableExists,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Statistiques API Claude - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Statistiques API Claude'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
