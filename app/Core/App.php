<?php
/**
 * K-Docs - Classe de bootstrap de l'application Slim
 */

namespace KDocs\Core;

use Slim\Factory\AppFactory;
use Slim\App as SlimApp;

class App
{
    private static ?SlimApp $instance = null;

    /**
     * Crée et configure l'application Slim
     */
    public static function create(): SlimApp
    {
        if (self::$instance === null) {
            // Charger la configuration
            $config = Config::load();
            
            // Créer l'application Slim
            $app = AppFactory::create();
            
            // Définir le base path depuis la configuration
            $basePath = Config::basePath();
            $app->setBasePath($basePath);
            
            // Ajouter le middleware d'erreur
            $errorMiddleware = $app->addErrorMiddleware(
                $config['app']['debug'],
                true,
                true
            );
            
            // #region agent log
            // Instrumentation: Log des erreurs non capturées
            $errorHandler = $errorMiddleware->getDefaultErrorHandler();
            $responseFactory = $app->getResponseFactory();
            $errorMiddleware->setDefaultErrorHandler(function ($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails) use ($errorHandler, $responseFactory) {
                \KDocs\Core\DebugLogger::logException($exception, 'App::create - ErrorMiddleware', 'A');
                
                // Si c'est une route API, forcer le retour JSON
                $path = $request->getUri()->getPath();
                if (strpos($path, '/api/') === 0) {
                    $response = $responseFactory->createResponse();
                    $statusCode = $exception instanceof \Slim\Exception\HttpException 
                        ? $exception->getCode() 
                        : 500;
                    
                    $errorData = [
                        'success' => false,
                        'error' => $exception->getMessage()
                    ];
                    
                    if ($displayErrorDetails) {
                        $errorData['details'] = [
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                            'trace' => explode("\n", $exception->getTraceAsString())
                        ];
                    }
                    
                    $response->getBody()->write(json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    return $response
                        ->withHeader('Content-Type', 'application/json')
                        ->withStatus($statusCode);
                }
                
                return $errorHandler($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
            });
            // #endregion
            
            // Définir le fuseau horaire
            date_default_timezone_set($config['app']['timezone']);
            
            self::$instance = $app;
        }

        return self::$instance;
    }

    /**
     * Récupère l'instance de l'application
     */
    public static function getInstance(): SlimApp
    {
        if (self::$instance === null) {
            return self::create();
        }
        return self::$instance;
    }
}
