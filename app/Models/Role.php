<?php
/**
 * K-Docs - Modèle Role
 * Gestion des rôles métier (VALIDATOR, APPROVER, etc.)
 */

namespace KDocs\Models;

use KDocs\Core\Database;
use PDO;

class Role
{
    /**
     * Rôles système par défaut avec leurs niveaux
     */
    public const ROLES = [
        'VIEWER' => ['level' => 0, 'label' => 'Lecteur'],
        'CONTRIBUTOR' => ['level' => 1, 'label' => 'Contributeur'],
        'VALIDATOR_L1' => ['level' => 2, 'label' => 'Validateur Niveau 1'],
        'VALIDATOR_L2' => ['level' => 3, 'label' => 'Validateur Niveau 2'],
        'APPROVER' => ['level' => 4, 'label' => 'Approbateur'],
        'ADMIN' => ['level' => 5, 'label' => 'Administrateur'],
    ];

    /**
     * Récupère tous les types de rôles
     */
    public static function getAllRoleTypes(): array
    {
        $db = Database::getInstance();
        try {
            $stmt = $db->query("SELECT * FROM role_types ORDER BY level ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Fallback si la table n'existe pas
            return array_map(function ($code, $info) {
                return [
                    'code' => $code,
                    'label' => $info['label'],
                    'level' => $info['level']
                ];
            }, array_keys(self::ROLES), self::ROLES);
        }
    }

    /**
     * Récupère un rôle par son code
     */
    public static function findByCode(string $code): ?array
    {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("SELECT * FROM role_types WHERE code = ?");
            $stmt->execute([$code]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            if (isset(self::ROLES[$code])) {
                return [
                    'code' => $code,
                    'label' => self::ROLES[$code]['label'],
                    'level' => self::ROLES[$code]['level']
                ];
            }
            return null;
        }
    }

    /**
     * Récupère les rôles d'un utilisateur
     */
    public static function getUserRoles(int $userId): array
    {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare("
                SELECT rt.*, ur.scope, ur.max_amount, ur.valid_from, ur.valid_to
                FROM user_roles ur
                JOIN role_types rt ON ur.role_type_id = rt.id
                WHERE ur.user_id = ?
                  AND (ur.valid_from IS NULL OR ur.valid_from <= CURDATE())
                  AND (ur.valid_to IS NULL OR ur.valid_to >= CURDATE())
                ORDER BY rt.level DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Vérifie si un utilisateur a un rôle spécifique
     */
    public static function userHasRole(int $userId, string $roleCode, ?string $scope = null): bool
    {
        $db = Database::getInstance();
        try {
            $sql = "
                SELECT COUNT(*) as cnt
                FROM user_roles ur
                JOIN role_types rt ON ur.role_type_id = rt.id
                WHERE ur.user_id = ?
                  AND rt.code = ?
                  AND (ur.valid_from IS NULL OR ur.valid_from <= CURDATE())
                  AND (ur.valid_to IS NULL OR ur.valid_to >= CURDATE())
            ";
            $params = [$userId, $roleCode];

            if ($scope !== null) {
                $sql .= " AND (ur.scope = '*' OR ur.scope = ?)";
                $params[] = $scope;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($result['cnt'] ?? 0) > 0;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Vérifie si un utilisateur peut valider un document (montant, scope, rôle)
     */
    public static function canUserValidateDocument(int $userId, array $document): array
    {
        $roles = self::getUserRoles($userId);

        if (empty($roles)) {
            return ['can_validate' => false, 'reason' => 'Aucun rôle de validation'];
        }

        $documentAmount = (float)($document['amount'] ?? 0);
        $documentTypeCode = $document['document_type_code'] ?? $document['type_code'] ?? '*';

        foreach ($roles as $role) {
            // Vérifier le scope (type de document)
            if ($role['scope'] !== '*' && $role['scope'] !== $documentTypeCode) {
                continue;
            }

            // Vérifier le montant maximum
            if ($role['max_amount'] !== null && $documentAmount > (float)$role['max_amount']) {
                continue;
            }

            // Ce rôle permet la validation
            return [
                'can_validate' => true,
                'role_code' => $role['code'],
                'role_label' => $role['label'],
                'max_amount' => $role['max_amount']
            ];
        }

        // Aucun rôle compatible trouvé
        $maxAmount = 0;
        foreach ($roles as $role) {
            if ($role['max_amount'] !== null && $role['max_amount'] > $maxAmount) {
                $maxAmount = $role['max_amount'];
            }
        }

        if ($documentAmount > $maxAmount && $maxAmount > 0) {
            return [
                'can_validate' => false,
                'reason' => "Montant {$documentAmount} CHF dépasse votre limite de {$maxAmount} CHF"
            ];
        }

        return ['can_validate' => false, 'reason' => 'Scope de document non autorisé'];
    }

    /**
     * Assigne un rôle à un utilisateur
     */
    public static function assignRole(
        int $userId,
        string $roleCode,
        string $scope = '*',
        ?float $maxAmount = null,
        ?string $validFrom = null,
        ?string $validTo = null
    ): bool {
        $db = Database::getInstance();

        // Récupérer l'ID du rôle
        $role = self::findByCode($roleCode);
        if (!$role || !isset($role['id'])) {
            // Créer le rôle s'il n'existe pas
            $stmt = $db->prepare("
                INSERT INTO role_types (code, label, level)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE label = VALUES(label)
            ");
            $info = self::ROLES[$roleCode] ?? ['level' => 0, 'label' => $roleCode];
            $stmt->execute([$roleCode, $info['label'], $info['level']]);

            $stmt = $db->prepare("SELECT id FROM role_types WHERE code = ?");
            $stmt->execute([$roleCode]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$role || !isset($role['id'])) {
            return false;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO user_roles (user_id, role_type_id, scope, max_amount, valid_from, valid_to)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    max_amount = VALUES(max_amount),
                    valid_from = VALUES(valid_from),
                    valid_to = VALUES(valid_to)
            ");
            return $stmt->execute([
                $userId,
                $role['id'],
                $scope,
                $maxAmount,
                $validFrom,
                $validTo
            ]);
        } catch (\PDOException $e) {
            error_log("Role::assignRole error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retire un rôle d'un utilisateur
     */
    public static function removeRole(int $userId, string $roleCode, ?string $scope = null): bool
    {
        $db = Database::getInstance();
        try {
            $sql = "
                DELETE ur FROM user_roles ur
                JOIN role_types rt ON ur.role_type_id = rt.id
                WHERE ur.user_id = ? AND rt.code = ?
            ";
            $params = [$userId, $roleCode];

            if ($scope !== null) {
                $sql .= " AND ur.scope = ?";
                $params[] = $scope;
            }

            $stmt = $db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Récupère tous les utilisateurs ayant un rôle spécifique
     */
    public static function getUsersWithRole(string $roleCode, ?string $scope = null): array
    {
        $db = Database::getInstance();
        try {
            $sql = "
                SELECT u.*, rt.code as role_code, rt.label as role_label, ur.scope, ur.max_amount
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                JOIN role_types rt ON ur.role_type_id = rt.id
                WHERE rt.code = ?
                  AND u.is_active = 1
                  AND (ur.valid_from IS NULL OR ur.valid_from <= CURDATE())
                  AND (ur.valid_to IS NULL OR ur.valid_to >= CURDATE())
            ";
            $params = [$roleCode];

            if ($scope !== null) {
                $sql .= " AND (ur.scope = '*' OR ur.scope = ?)";
                $params[] = $scope;
            }

            $sql .= " ORDER BY u.username";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Récupère le rôle le plus élevé d'un utilisateur
     */
    public static function getHighestUserRole(int $userId): ?array
    {
        $roles = self::getUserRoles($userId);
        return !empty($roles) ? $roles[0] : null; // Déjà trié par level DESC
    }

    /**
     * Vérifie si un utilisateur a un niveau de rôle suffisant
     */
    public static function hasMinimumLevel(int $userId, int $minLevel): bool
    {
        $highest = self::getHighestUserRole($userId);
        return $highest && ($highest['level'] ?? 0) >= $minLevel;
    }
}
