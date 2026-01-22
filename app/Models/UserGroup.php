<?php
/**
 * K-Docs - Modèle UserGroup
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class UserGroup
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Récupère tous les groupes
     */
    public function getAll(): array
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('UserGroup::getAll', 'Before query', [
            'table' => 'user_groups'
        ], 'A');
        // #endregion
        
        try {
            // Vérifier si la table existe et sa structure
            $columns = $this->db->query("SHOW COLUMNS FROM user_groups")->fetchAll(PDO::FETCH_COLUMN);
            
            // #region agent log
            \KDocs\Core\DebugLogger::log('UserGroup::getAll', 'Table columns', [
                'columns' => $columns
            ], 'A');
            // #endregion
            
            // Déterminer la colonne de tri (name ou group_name)
            $orderColumn = in_array('name', $columns) ? 'name' : (in_array('group_name', $columns) ? 'group_name' : 'id');
            
            $stmt = $this->db->query("SELECT * FROM user_groups ORDER BY $orderColumn");
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // #region agent log
            \KDocs\Core\DebugLogger::logException($e, 'UserGroup::getAll', 'A');
            // #endregion
            // Table n'existe peut-être pas encore
            return [];
        }
        
        foreach ($groups as &$group) {
            if ($group['permissions']) {
                $group['permissions'] = json_decode($group['permissions'], true) ?? [];
            } else {
                $group['permissions'] = [];
            }
        }
        
        return $groups;
    }

    /**
     * Récupère un groupe par ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM user_groups WHERE id = ?");
        $stmt->execute([$id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($group && $group['permissions']) {
            $group['permissions'] = json_decode($group['permissions'], true) ?? [];
        }
        
        return $group ?: null;
    }

    /**
     * Crée un nouveau groupe
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_groups (name, description, permissions, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        
        $permissions = !empty($data['permissions']) ? json_encode($data['permissions']) : null;
        
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $permissions
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Met à jour un groupe
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        
        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = $data['name'];
        }
        
        if (isset($data['description'])) {
            $fields[] = 'description = ?';
            $params[] = $data['description'];
        }
        
        if (isset($data['permissions'])) {
            $fields[] = 'permissions = ?';
            $params[] = json_encode($data['permissions']);
        }
        
        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        
        $sql = "UPDATE user_groups SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Supprime un groupe
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_groups WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Ajoute un utilisateur à un groupe
     */
    public function addUser(int $groupId, int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO user_group_memberships (user_id, group_id) VALUES (?, ?)");
            return $stmt->execute([$userId, $groupId]);
        } catch (\PDOException $e) {
            // Déjà membre
            return false;
        }
    }

    /**
     * Retire un utilisateur d'un groupe
     */
    public function removeUser(int $groupId, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_group_memberships WHERE user_id = ? AND group_id = ?");
        return $stmt->execute([$userId, $groupId]);
    }

    /**
     * Récupère les membres d'un groupe
     */
    public function getMembers(int $groupId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.email, u.role
            FROM users u
            INNER JOIN user_group_memberships ugm ON u.id = ugm.user_id
            WHERE ugm.group_id = ?
            ORDER BY u.username
        ");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
