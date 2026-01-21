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
            $errorMiddleware->setDefaultErrorHandler(function ($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails) use ($errorHandler) {
                \KDocs\Core\DebugLogger::logException($exception, 'App::create - ErrorMiddleware', 'A');
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
