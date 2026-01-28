<?php
/**
 * K-Docs - Error Handler Middleware
 * Catches exceptions and renders user-friendly error pages
 */

namespace KDocs\Middleware;

use KDocs\Core\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class ErrorHandlerMiddleware implements MiddlewareInterface
{
    private string $logPath;
    private bool $debug;

    public function __construct()
    {
        $this->logPath = dirname(__DIR__, 2) . '/storage/logs/errors.log';
        $config = Config::load();
        $this->debug = $config['app']['debug'] ?? false;

        // Ensure log directory exists
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (\Slim\Exception\HttpNotFoundException $e) {
            return $this->render404($request);
        } catch (\Throwable $e) {
            return $this->render500($request, $e);
        }
    }

    /**
     * Render 404 Not Found page
     */
    private function render404(Request $request): Response
    {
        $response = new SlimResponse();

        // Log 404
        $this->log('404', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'ip' => $this->getClientIp($request)
        ]);

        // Check if API request
        if ($this->isApiRequest($request)) {
            $response->getBody()->write(json_encode([
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
                'status' => 404
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }

        // Render HTML page
        ob_start();
        include dirname(__DIR__, 2) . '/templates/errors/404.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html')
            ->withStatus(404);
    }

    /**
     * Render 500 Internal Server Error page
     */
    private function render500(Request $request, \Throwable $e): Response
    {
        $response = new SlimResponse();

        // Generate error reference
        $errorRef = date('YmdHis') . '-' . substr(md5(uniqid()), 0, 8);

        // Log error with full details
        $this->log('500', [
            'reference' => $errorRef,
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'ip' => $this->getClientIp($request),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        // Check if API request
        if ($this->isApiRequest($request)) {
            $data = [
                'error' => 'Internal Server Error',
                'message' => $this->debug ? $e->getMessage() : 'An unexpected error occurred',
                'reference' => $errorRef,
                'status' => 500
            ];

            if ($this->debug) {
                $data['file'] = $e->getFile();
                $data['line'] = $e->getLine();
            }

            $response->getBody()->write(json_encode($data));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }

        // Prepare template variables
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();
        $showDetails = $this->debug;

        // Render HTML page
        ob_start();
        include dirname(__DIR__, 2) . '/templates/errors/500.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html')
            ->withStatus(500);
    }

    /**
     * Check if request is for API
     */
    private function isApiRequest(Request $request): bool
    {
        $path = $request->getUri()->getPath();
        $accept = $request->getHeaderLine('Accept');

        return strpos($path, '/api/') !== false
            || strpos($accept, 'application/json') !== false;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        $headers = ['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP'];

        foreach ($headers as $header) {
            $value = $request->getHeaderLine($header);
            if (!empty($value)) {
                $ips = explode(',', $value);
                return trim($ips[0]);
            }
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Log error to file
     */
    private function log(string $type, array $data): void
    {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'data' => $data
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";

        @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
