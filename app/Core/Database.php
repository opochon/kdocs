<?php
/**
 * K-Docs - Classe de gestion de la base de données (Singleton PDO)
 * 
 * OPTIMISÉ : Suppression des appels DebugLogger pour performance
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
            } catch (PDOException $e) {
                throw new \RuntimeException(
                    "Erreur de connexion à la base de données: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException("Cannot unserialize singleton"); }
}
