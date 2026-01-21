<?php
/**
 * K-Docs - Classe de gestion de la base de données (Singleton PDO)
 */

namespace KDocs\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Récupère l'instance unique de PDO (Singleton)
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = Config::get('database');
            
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['name'],
                $config['charset']
            );

            try {
                // #region agent log
                \KDocs\Core\DebugLogger::log('Database::getInstance', 'Attempting DB connection', [
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'dbname' => $config['name']
                ], 'E');
                // #endregion
                
                self::$instance = new PDO(
                    $dsn,
                    $config['user'],
                    $config['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                
                // #region agent log
                \KDocs\Core\DebugLogger::log('Database::getInstance', 'DB connection successful', [], 'E');
                // #endregion
            } catch (PDOException $e) {
                // #region agent log
                \KDocs\Core\DebugLogger::logException($e, 'Database::getInstance - Connection failed', 'E');
                // #endregion
                throw new \RuntimeException(
                    "Erreur de connexion à la base de données: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return self::$instance;
    }

    /**
     * Empêche la création d'instances externes
     */
    private function __construct()
    {
    }

    /**
     * Empêche le clonage
     */
    private function __clone()
    {
    }

    /**
     * Empêche la désérialisation
     */
    public function __wakeup()
    {
        throw new \RuntimeException("Cannot unserialize singleton");
    }
}
