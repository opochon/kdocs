<?php
/**
 * K-Docs - Classe d'authentification basique
 */

namespace KDocs\Core;

use KDocs\Core\Database;
use PDO;

class Auth
{
    /**
     * Liste des mots de passe faibles courants
     */
    private static array $weakPasswords = [
        'admin123', 'password', '123456', '12345678', 'admin', 'root',
        'password123', 'azerty', 'qwerty', '111111', '123123', 'admin1234',
        'motdepasse', 'abc123', 'iloveyou', 'welcome', 'monkey', 'dragon'
    ];

    /**
     * Vérifie si un mot de passe est faible
     */
    public static function isWeakPassword(string $password): bool
    {
        // Vide = faible
        if (empty($password)) {
            return true;
        }

        // Dans la liste des mots de passe faibles
        if (in_array(strtolower($password), self::$weakPasswords, true)) {
            return true;
        }

        // Trop court (moins de 8 caractères)
        if (strlen($password) < 8) {
            return true;
        }

        return false;
    }

    /**
     * Vérifie les identifiants de connexion
     * 
     * @param string $username Nom d'utilisateur
     * @param string $password Mot de passe
     * @return array|false Retourne les données de l'utilisateur ou false si échec
     */
    public static function attempt(string $username, string $password)
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT id, username, email, password_hash, first_name, last_name, 
                   is_active, is_admin, role, permissions, last_login, last_login_at
            FROM users 
            WHERE username = :username AND is_active = 1
        ");
        
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return false;
        }
        
        // Décoder les permissions JSON si présentes
        if (!empty($user['permissions'])) {
            $user['permissions'] = json_decode($user['permissions'], true) ?? [];
        } else {
            $user['permissions'] = [];
        }
        
        // Déterminer le rôle (is_admin = true => admin, sinon role ou 'user')
        if (!isset($user['role'])) {
            $user['role'] = ($user['is_admin'] ?? false) ? 'admin' : 'user';
        }
        
        // Pour l'instant, on accepte un mot de passe vide (développement)
        // TODO: Implémenter password_verify() en production
        if (empty($user['password_hash']) && empty($password)) {
            // Mettre à jour la dernière connexion
            $updateStmt = $db->prepare("UPDATE users SET last_login = NOW(), last_login_at = NOW() WHERE id = :id");
            $updateStmt->execute(['id' => $user['id']]);

            // Marquer comme mot de passe faible
            $user['_weak_password'] = true;

            return $user;
        }

        // Vérifier le mot de passe avec password_verify
        if (!empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
            // Mettre à jour la dernière connexion
            $updateStmt = $db->prepare("UPDATE users SET last_login = NOW(), last_login_at = NOW() WHERE id = :id");
            $updateStmt->execute(['id' => $user['id']]);

            // Vérifier si le mot de passe est faible
            if (self::isWeakPassword($password)) {
                $user['_weak_password'] = true;
            }

            return $user;
        }
        
        return false;
    }

    /**
     * Crée une session pour l'utilisateur
     * 
     * @param int $userId ID de l'utilisateur
     * @param string $sessionId ID de session
     * @param string|null $ipAddress Adresse IP
     * @param string|null $userAgent User agent
     * @return bool
     */
    public static function createSession(int $userId, string $sessionId, ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            INSERT INTO sessions (id, user_id, ip_address, user_agent, last_activity)
            VALUES (:id, :user_id, :ip_address, :user_agent, UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE 
                last_activity = UNIX_TIMESTAMP(),
                ip_address = :ip_address_update,
                user_agent = :user_agent_update
        ");
        
        return $stmt->execute([
            'id' => $sessionId,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'ip_address_update' => $ipAddress,
            'user_agent_update' => $userAgent,
        ]);
    }

    /**
     * Récupère l'utilisateur depuis la session
     * 
     * @param string $sessionId ID de session
     * @return array|false
     */
    public static function getUserFromSession(string $sessionId)
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.email, u.first_name, u.last_name, 
                   u.is_active, u.is_admin, u.role, u.permissions
            FROM sessions s
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.id = :session_id 
              AND u.is_active = 1
              AND s.last_activity > UNIX_TIMESTAMP() - 3600
        ");
        
        $stmt->execute(['session_id' => $sessionId]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Décoder les permissions JSON si présentes
            if (!empty($user['permissions'])) {
                $user['permissions'] = json_decode($user['permissions'], true) ?? [];
            } else {
                $user['permissions'] = [];
            }
            
            // Déterminer le rôle (is_admin = true => admin, sinon role ou 'user')
            if (!isset($user['role'])) {
                $user['role'] = ($user['is_admin'] ?? false) ? 'admin' : 'user';
            }
            
            // Mettre à jour l'activité de la session
            $updateStmt = $db->prepare("UPDATE sessions SET last_activity = UNIX_TIMESTAMP() WHERE id = :id");
            $updateStmt->execute(['id' => $sessionId]);
        }
        
        return $user ?: false;
    }

    /**
     * Supprime une session
     */
    public static function destroySession(string $sessionId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM sessions WHERE id = :id");
        return $stmt->execute(['id' => $sessionId]);
    }
}
