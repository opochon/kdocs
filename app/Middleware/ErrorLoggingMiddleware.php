<?php
/**
 * K-Docs - Middleware de logging des erreurs pour debug
 */

namespace KDocs\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class ErrorLoggingMiddleware implements MiddlewareInterface
{
    private $logPath;

    public function __construct()
    {
        $this->logPath = __DIR__ . '/../../.cursor/debug.log';
    }

    private function log($data)
    {
        $logEntry = json_encode([
            'timestamp' => time() * 1000,
            'location' => $data['location'] ?? 'unknown',
            'message' => $data['message'] ?? '',
            'data' => $data['data'] ?? [],
            'sessionId' => 'debug-session',
            'runId' => $data['runId'] ?? 'run1',
            'hypothesisId' => $data['hypothesisId'] ?? 'A'
        ]) . "\n";
        
        file_put_contents($this->logPath, $logEntry, FILE_APPEND);
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        // #region agent log
        $this->log([
            'location' => 'ErrorLoggingMiddleware::process',
            'message' => 'Request received',
            'data' => ['path' => $path, 'method' => $method],
            'hypothesisId' => 'A'
        ]);
        // #endregion

        try {
            $response = $handler->handle($request);
            
            // #region agent log
            $this->log([
                'location' => 'ErrorLoggingMiddleware::process',
                'message' => 'Request handled successfully',
                'data' => ['path' => $path, 'status' => $response->getStatusCode()],
                'hypothesisId' => 'A'
            ]);
            // #endregion
            
            return $response;
        } catch (\Exception $e) {
            // #region agent log
            $this->log([
                'location' => 'ErrorLoggingMiddleware::process',
                'message' => 'Exception caught',
                'data' => [
                    'path' => $path,
                    'method' => $method,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => substr($e->getTraceAsString(), 0, 500)
                ],
                'hypothesisId' => 'A'
            ]);
            // #endregion
            
            throw $e;
        }
    }
}
