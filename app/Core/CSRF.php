<?php
/**
 * K-Docs - CSRF Protection
 * Génération et validation de tokens CSRF
 */

namespace KDocs\Core;

class CSRF
{
    private const TOKEN_NAME = '_csrf_token';
    private const TOKEN_LENGTH = 32;

    /**
     * Génère un nouveau token CSRF et le stocke en session
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_NAME] = $token;
        $_SESSION[self::TOKEN_NAME . '_time'] = time();

        return $token;
    }

    /**
     * Récupère le token actuel ou en génère un nouveau
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::TOKEN_NAME])) {
            return self::generateToken();
        }

        // Régénérer si token trop vieux (1 heure)
        $tokenTime = $_SESSION[self::TOKEN_NAME . '_time'] ?? 0;
        if (time() - $tokenTime > 3600) {
            return self::generateToken();
        }

        return $_SESSION[self::TOKEN_NAME];
    }

    /**
     * Valide un token CSRF
     */
    public static function validateToken(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION[self::TOKEN_NAME])) {
            return false;
        }

        // Comparaison timing-safe
        return hash_equals($_SESSION[self::TOKEN_NAME], $token);
    }

    /**
     * Valide et régénère le token (pour formulaires)
     */
    public static function validateAndRegenerate(?string $token): bool
    {
        $valid = self::validateToken($token);

        if ($valid) {
            // Régénérer après validation réussie
            self::generateToken();
        }

        return $valid;
    }

    /**
     * Retourne le nom du champ de formulaire
     */
    public static function getTokenName(): string
    {
        return self::TOKEN_NAME;
    }

    /**
     * Génère le champ HTML hidden
     */
    public static function field(): string
    {
        $token = self::getToken();
        $name = self::TOKEN_NAME;
        return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Génère la meta tag pour AJAX
     */
    public static function metaTag(): string
    {
        $token = self::getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }
}
