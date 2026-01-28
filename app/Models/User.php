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
     * Note: le champ 'role' est conservé pour compatibilité DB mais les permissions
     * sont maintenant gérées par les groupes
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, email, role, permissions, is_active, is_admin, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        // Role 'user' par défaut pour compatibilité, les vraies permissions viennent des groupes
        $role = $data['role'] ?? 'user';

        $stmt->execute([
            $data['username'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['email'] ?? null,
            $role,
            null, // permissions now come from groups
            $data['is_active'] ?? true,
            0 // is_admin now determined by ADMIN group membership
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
     * Les permissions sont déterminées UNIQUEMENT par les groupes de l'utilisateur
     */
    public static function hasPermission(array $user, string $permission): bool
    {
        $userId = $user['id'] ?? 0;
        if (!$userId) {
            return false;
        }

        // Vérifier si l'utilisateur est dans le groupe ADMIN
        if (self::isInAdminGroup($userId)) {
            return true;
        }

        // Récupérer toutes les permissions des groupes de l'utilisateur
        $groupPermissions = self::getGroupPermissions($userId);

        // Vérifier la permission demandée
        if (in_array('*', $groupPermissions)) {
            return true;
        }

        if (in_array($permission, $groupPermissions)) {
            return true;
        }

        // Vérifier les wildcards (ex: documents.* inclut documents.view)
        $permissionParts = explode('.', $permission);
        if (count($permissionParts) >= 2) {
            $wildcardPermission = $permissionParts[0] . '.*';
            if (in_array($wildcardPermission, $groupPermissions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si l'utilisateur est dans le groupe ADMIN
     */
    public static function isInAdminGroup(int $userId): bool
    {
        static $cache = [];

        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM user_group_memberships ugm
                INNER JOIN user_groups ug ON ug.id = ugm.group_id
                WHERE ugm.user_id = ? AND ug.code = 'ADMIN'
            ");
            $stmt->execute([$userId]);
            $cache[$userId] = (int)$stmt->fetchColumn() > 0;
        } catch (\PDOException $e) {
            $cache[$userId] = false;
        }

        return $cache[$userId];
    }

    /**
     * Récupère toutes les permissions agrégées des groupes d'un utilisateur
     */
    public static function getGroupPermissions(int $userId): array
    {
        static $cache = [];

        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $permissions = [];

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT ug.permissions, ug.code
                FROM user_groups ug
                INNER JOIN user_group_memberships ugm ON ug.id = ugm.group_id
                WHERE ugm.user_id = ?
            ");
            $stmt->execute([$userId]);
            $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($groups as $group) {
                // Groupe ADMIN = tous les droits
                if ($group['code'] === 'ADMIN') {
                    $cache[$userId] = ['*'];
                    return $cache[$userId];
                }

                // Décoder et ajouter les permissions du groupe
                if (!empty($group['permissions'])) {
                    $groupPerms = json_decode($group['permissions'], true) ?? [];
                    // Si c'est un tableau associatif (permissions checkbox), convertir
                    if (!empty($groupPerms) && !isset($groupPerms[0])) {
                        foreach ($groupPerms as $key => $value) {
                            if ($value) {
                                $permissions[] = $key;
                            }
                        }
                    } else {
                        $permissions = array_merge($permissions, $groupPerms);
                    }
                }
            }
        } catch (\PDOException $e) {
            // Table n'existe peut-être pas
        }

        $cache[$userId] = array_unique($permissions);
        return $cache[$userId];
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
