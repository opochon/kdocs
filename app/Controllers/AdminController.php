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

        $icon = static fn (string $path) => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $path . '"/></svg>';

        $adminTiles = [
            ['title' => 'Paramètres', 'description' => 'Configuration générale et IA', 'href' => url('/admin/settings'), 'icon' => $icon('M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z')],
            ['title' => 'Utilisateurs', 'description' => 'Comptes et rôles', 'href' => url('/admin/users'), 'icon' => $icon('M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z')],
            ['title' => 'Tags', 'description' => 'Étiquettes documentaires', 'href' => url('/admin/tags'), 'icon' => $icon('M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z')],
            ['title' => 'Types de documents', 'description' => 'Typologie et classification', 'href' => url('/admin/document-types'), 'icon' => $icon('M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z')],
            ['title' => 'Workflows', 'description' => 'Circuits de validation', 'href' => url('/admin/workflows'), 'icon' => $icon('M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15')],
            ['title' => 'Correspondants', 'description' => 'Contacts et fournisseurs', 'href' => url('/admin/correspondents'), 'icon' => $icon('M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z')],
            ['title' => 'Diagnostic', 'description' => 'IA, ingest et services', 'href' => url('/admin/diagnostic'), 'icon' => $icon('M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z')],
            ['title' => 'Indexation', 'description' => 'Workers et file d\'attente', 'href' => url('/admin/indexing'), 'icon' => $icon('M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15')],
            ['title' => 'Fichiers à valider', 'description' => 'Dossier consume', 'href' => url('/admin/consume'), 'icon' => $icon('M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z')],
            ['title' => 'Snapshots', 'description' => 'Sauvegardes configuration', 'href' => url('/admin/snapshots'), 'icon' => $icon('M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z M15 13a3 3 0 11-6 0 3 3 0 016 0z')],
            ['title' => 'Règles d\'attribution', 'description' => 'Classification automatique', 'href' => url('/admin/attribution-rules'), 'icon' => $icon('M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2')],
            ['title' => 'Audit', 'description' => 'Journal des actions', 'href' => url('/admin/audit-logs'), 'icon' => $icon('M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z')],
        ];
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/index.php', [
            'stats' => $stats,
            'adminTiles' => $adminTiles,
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
