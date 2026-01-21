<?php
/**
 * K-Docs - Modèle User
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class User
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Récupère un utilisateur par ID
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Décoder les permissions JSON
            if (!empty($user['permissions'])) {
                $user['permissions'] = json_decode($user['permissions'], true) ?? [];
            } else {
                $user['permissions'] = [];
            }
            
            // Déterminer le rôle (is_admin = true => admin, sinon role ou 'user')
            if (!isset($user['role']) || empty($user['role'])) {
                $user['role'] = ($user['is_admin'] ?? false) ? 'admin' : 'user';
            }
        }
        
        return $user ?: null;
    }

    /**
     * Récupère un utilisateur par username
     */
    public static function findByUsername(string $username): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Décoder les permissions JSON
            if (!empty($user['permissions'])) {
                $user['permissions'] = json_decode($user['permissions'], true) ?? [];
            } else {
                $user['permissions'] = [];
            }
            
            // Déterminer le rôle (is_admin = true => admin, sinon role ou 'user')
            if (!isset($user['role']) || empty($user['role'])) {
                $user['role'] = ($user['is_admin'] ?? false) ? 'admin' : 'user';
            }
        }
        
        return $user ?: null;
    }

    /**
     * Récupère tous les utilisateurs
     */
    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT id, username, email, role, is_active, last_login_at, created_at FROM users ORDER BY username");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crée un nouvel utilisateur
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, email, role, permissions, is_active, is_admin, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $permissions = !empty($data['permissions']) ? json_encode($data['permissions']) : null;
        $role = $data['role'] ?? 'user';
        $isAdmin = ($role === 'admin') ? 1 : 0;
        
        $stmt->execute([
            $data['username'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['email'] ?? null,
            $role,
            $permissions,
            $data['is_active'] ?? true,
            $isAdmin
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Met à jour un utilisateur
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        
        if (isset($data['username'])) {
            $fields[] = 'username = ?';
            $params[] = $data['username'];
        }
        
        if (isset($data['email'])) {
            $fields[] = 'email = ?';
            $params[] = $data['email'];
        }
        
        if (isset($data['password'])) {
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (isset($data['role'])) {
            $fields[] = 'role = ?';
            $params[] = $data['role'];
            // Mettre à jour is_admin en fonction du rôle
            $fields[] = 'is_admin = ?';
            $params[] = ($data['role'] === 'admin') ? 1 : 0;
        }
        
        if (isset($data['permissions'])) {
            $fields[] = 'permissions = ?';
            $params[] = json_encode($data['permissions']);
        }
        
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }
        
        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Met à jour la dernière connexion
     */
    public function updateLastLogin(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Supprime un utilisateur
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Vérifie si un utilisateur a une permission
     */
    public static function hasPermission(array $user, string $permission): bool
    {
        // Admin a tous les droits (via role ou is_admin)
        $role = $user['role'] ?? (($user['is_admin'] ?? false) ? 'admin' : 'user');
        if ($role === 'admin' || ($user['is_admin'] ?? false)) {
            return true;
        }
        
        // Vérifier les permissions granulaires
        $permissions = $user['permissions'] ?? [];
        if (in_array('*', $permissions) || in_array($permission, $permissions)) {
            return true;
        }
        
        // Permissions par rôle
        $rolePermissions = self::getRolePermissions($role);
        return in_array($permission, $rolePermissions);
    }

    /**
     * Retourne les permissions par défaut d'un rôle
     */
    private static function getRolePermissions(string $role): array
    {
        $permissions = [
            'admin' => ['*'], // Tous les droits
            'user' => [
                'documents.view',
                'documents.create',
                'documents.edit',
                'documents.delete',
                'tags.view',
                'tags.create',
                'tags.edit',
                'tags.delete',
                'correspondents.view',
                'correspondents.create',
                'correspondents.edit',
                'correspondents.delete',
            ],
            'viewer' => [
                'documents.view',
                'tags.view',
                'correspondents.view',
            ],
        ];
        
        return $permissions[$role] ?? [];
    }

    /**
     * Récupère les groupes d'un utilisateur
     */
    public function getUserGroups(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT user_groups.* 
                FROM user_groups
                INNER JOIN user_group_memberships ON user_groups.id = user_group_memberships.group_id
                WHERE user_group_memberships.user_id = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Si la table n'existe pas encore, retourner un tableau vide
            return [];
        }
    }
}
