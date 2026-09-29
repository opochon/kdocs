<?php

declare(strict_types=1);

namespace Ged;

use PDO;

/**
 * Connexion MariaDB unique, partagée par tous les secteurs. La base vient de la configuration
 * (DB_NAME) : les tests imposent gedv2_test, le harness aussi.
 */
final class Base
{
    /** @var array<string, PDO> */
    private static array $connexions = [];

    public static function pdo(Config $config): PDO
    {
        $nom = $config->get('DB_NAME', 'gedv2');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $nom)) {
            throw new \InvalidArgumentException("Nom de base invalide : $nom");
        }
        $cle = $config->get('DB_HOST', '127.0.0.1') . ':' . $config->get('DB_PORT', '3307') . '/' . $nom;

        return self::$connexions[$cle] ??= self::ouvrir($config, $nom);
    }

    /** Crée la base si elle n'existe pas (installateur, tests). Jamais appelé par les écrans. */
    public static function assurerBase(Config $config): void
    {
        $nom = $config->get('DB_NAME', 'gedv2');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $nom)) {
            throw new \InvalidArgumentException("Nom de base invalide : $nom");
        }
        $serveur = new PDO(self::dsn($config, null), $config->get('DB_USER', 'root'), $config->get('DB_PASS', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $serveur->exec("CREATE DATABASE IF NOT EXISTS `$nom` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private static function ouvrir(Config $config, string $nom): PDO
    {
        return new PDO(self::dsn($config, $nom), $config->get('DB_USER', 'root'), $config->get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function dsn(Config $config, ?string $nom): string
    {
        return sprintf('mysql:host=%s;port=%s;%scharset=utf8mb4', $config->get('DB_HOST', '127.0.0.1'),
            $config->get('DB_PORT', '3307'), $nom !== null ? "dbname=$nom;" : '');
    }
}
