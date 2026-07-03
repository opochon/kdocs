<?php
/**
 * Service ACL document — héritage dossier logique (GAP-040).
 *
 * Résolution des droits d'accès par remontée de la chaîne parent_id des
 * dossiers logiques (table logical_folders). Comportements garantis :
 *  - Admin (role=admin ou is_admin truthy) → toujours autorisé.
 *  - Permission explicite trouvée sur le dossier du document → appliquée.
 *  - Pas d'ACL sur le dossier → remontée récursive via parent_id.
 *  - Aucun ACL défini sur toute la chaîne → true (legacy ouvert, pas de régression).
 *  - Table folder_permissions absente (migration non appliquée) → true.
 *
 * Supporte subject user direct et groups via user_group_memberships.
 *
 * @see Tests\Unit\FolderPermissionTest
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class FolderPermissionService
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Vérifie si un utilisateur peut effectuer une action sur un document.
     *
     * Résolution : ACL du dossier du document → héritage parent → true si aucun ACL.
     *
     * @param  array  $user   Tableau utilisateur (id, role, is_admin, …)
     * @param  array  $doc    Tableau document (folder_id ou logical_folder_id, …)
     * @param  string $action 'read' | 'write' | 'delete'
     * @return bool
     */
    public function can(array $user, array $doc, string $action): bool
    {
        // Admin bypass — toujours autorisé
        if (($user['role'] ?? null) === 'admin' || !empty($user['is_admin'])) {
            return true;
        }

        $folderId = $doc['folder_id'] ?? $doc['logical_folder_id'] ?? null;
        if ($folderId === null) {
            return true; // Pas de dossier → comportement legacy ouvert
        }

        $userId   = (int) ($user['id'] ?? 0);
        $groupIds = $this->getUserGroupIds($userId);
        $column   = $this->actionColumn($action);

        return $this->resolvePermission((int) $folderId, $userId, $groupIds, $column);
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * Résout la permission en remontant la chaîne de dossiers logiques.
     *
     * Retourne true si aucun ACL défini (comportement ouvert / legacy).
     * Attrape \PDOException si une table est absente → retourne true.
     */
    private function resolvePermission(int $folderId, int $userId, array $groupIds, string $column): bool
    {
        try {
            $visited = [];
            $current = $folderId;

            while ($current > 0) {
                if (in_array($current, $visited, true)) {
                    break; // Eviter les cycles de parenté
                }
                $visited[] = $current;

                $result = $this->checkExplicitPermission($current, $userId, $groupIds, $column);
                if ($result !== null) {
                    return $result;
                }

                $parent = $this->parentFolderId($current);
                if ($parent === null) {
                    break;
                }
                $current = $parent;
            }
        } catch (\PDOException $e) {
            // Table absente (migration non appliquée) → comportement legacy ouvert
            return true;
        }

        // Aucun ACL défini sur toute la chaîne → ouvert
        return true;
    }

    /**
     * Vérifie s'il existe une permission explicite pour ce dossier.
     *
     * Priorité : user direct > groupe.
     *
     * @return bool|null true/false si ACL trouvé, null si aucun ACL pour ce dossier
     */
    private function checkExplicitPermission(int $folderId, int $userId, array $groupIds, string $column): ?bool
    {
        // Vérifie la permission utilisateur directe
        $stmt = $this->db->prepare(
            "SELECT {$column} FROM folder_permissions
             WHERE folder_id = ? AND subject_type = 'user' AND subject_id = ?
             LIMIT 1"
        );
        $stmt->execute([$folderId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (bool) $row[$column];
        }

        // Vérifie les permissions de groupe
        foreach ($groupIds as $groupId) {
            $stmt = $this->db->prepare(
                "SELECT {$column} FROM folder_permissions
                 WHERE folder_id = ? AND subject_type = 'group' AND subject_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$folderId, (int) $groupId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                return (bool) $row[$column];
            }
        }

        return null; // Pas d'ACL défini pour ce dossier
    }

    /**
     * Récupère les IDs des groupes auxquels appartient l'utilisateur.
     *
     * @return int[]
     */
    private function getUserGroupIds(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT group_id FROM user_group_memberships WHERE user_id = ?"
            );
            $stmt->execute([$userId]);
            return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'group_id');
        } catch (\PDOException $e) {
            return []; // Table absente → pas de groupes
        }
    }

    /**
     * Retourne le parent_id d'un dossier logique, ou null si racine.
     */
    private function parentFolderId(int $folderId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT parent_id FROM logical_folders WHERE id = ?"
        );
        $stmt->execute([$folderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || $row['parent_id'] === null) {
            return null;
        }

        return (int) $row['parent_id'];
    }

    /**
     * Mappe une action sur le nom de la colonne de permission correspondante.
     */
    private function actionColumn(string $action): string
    {
        return match ($action) {
            'write'  => 'can_write',
            'delete' => 'can_delete',
            default  => 'can_read',
        };
    }
}
