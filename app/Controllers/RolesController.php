<?php
/**
 * K-Docs - RolesController
 * Gestion des rôles utilisateurs (interface admin)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Models\Role;
use KDocs\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RolesController
{
    /**
     * Liste des rôles et assignations
     */
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getInstance();

        // Récupérer tous les types de rôles
        $roles = Role::getAllRoleTypes();

        // Récupérer tous les utilisateurs avec leurs rôles
        $stmt = $db->query("
            SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.is_active,
                   GROUP_CONCAT(DISTINCT CONCAT(rt.code, ':', COALESCE(ur.scope, '*'), ':', COALESCE(ur.max_amount, 'unlimited')) SEPARATOR '|') as roles_info
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
                AND (ur.valid_from IS NULL OR ur.valid_from <= CURDATE())
                AND (ur.valid_to IS NULL OR ur.valid_to >= CURDATE())
            LEFT JOIN role_types rt ON ur.role_type_id = rt.id
            GROUP BY u.id
            ORDER BY u.username
        ");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Parser les rôles pour chaque utilisateur
        foreach ($users as &$user) {
            $user['roles'] = [];
            if (!empty($user['roles_info'])) {
                $rolesInfo = explode('|', $user['roles_info']);
                foreach ($rolesInfo as $roleInfo) {
                    $parts = explode(':', $roleInfo);
                    if (count($parts) >= 3 && $parts[0]) {
                        $user['roles'][] = [
                            'code' => $parts[0],
                            'scope' => $parts[1],
                            'max_amount' => $parts[2] === 'unlimited' ? null : $parts[2]
                        ];
                    }
                }
            }
            unset($user['roles_info']);
        }

        // Rendre le template
        $basePath = \KDocs\Core\Config::basePath();
        ob_start();
        include __DIR__ . '/../../templates/admin/roles.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Formulaire d'assignation de rôle
     */
    public function showAssignForm(Request $request, Response $response, array $args): Response
    {
        $userId = (int)($args['userId'] ?? 0);

        $user = User::findById($userId);
        if (!$user) {
            return $response->withHeader('Location', '/admin/roles')->withStatus(302);
        }

        $roles = Role::getAllRoleTypes();
        $userRoles = Role::getUserRoles($userId);

        // Récupérer les types de documents pour le scope
        $db = Database::getInstance();
        $documentTypes = $db->query("SELECT code, label FROM document_types ORDER BY label")->fetchAll(\PDO::FETCH_ASSOC);

        $basePath = \KDocs\Core\Config::basePath();
        ob_start();
        include __DIR__ . '/../../templates/admin/role_assign.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Assigner un rôle
     */
    public function assign(Request $request, Response $response, array $args): Response
    {
        $userId = (int)($args['userId'] ?? 0);
        $data = $request->getParsedBody();

        $roleCode = $data['role_code'] ?? null;
        if (!$roleCode) {
            $_SESSION['flash_error'] = 'Veuillez sélectionner un rôle';
            return $response->withHeader('Location', "/admin/roles/{$userId}/assign")->withStatus(302);
        }

        $scope = $data['scope'] ?? '*';
        $maxAmount = !empty($data['max_amount']) ? (float)$data['max_amount'] : null;
        $validFrom = !empty($data['valid_from']) ? $data['valid_from'] : null;
        $validTo = !empty($data['valid_to']) ? $data['valid_to'] : null;

        $success = Role::assignRole($userId, $roleCode, $scope, $maxAmount, $validFrom, $validTo);

        if ($success) {
            $_SESSION['flash_success'] = 'Rôle assigné avec succès';
        } else {
            $_SESSION['flash_error'] = 'Erreur lors de l\'assignation du rôle';
        }

        return $response->withHeader('Location', '/admin/roles')->withStatus(302);
    }

    /**
     * Retirer un rôle
     */
    public function remove(Request $request, Response $response, array $args): Response
    {
        $userId = (int)($args['userId'] ?? 0);
        $roleCode = $args['roleCode'] ?? '';
        $scope = $request->getQueryParams()['scope'] ?? null;

        $success = Role::removeRole($userId, $roleCode, $scope);

        if ($success) {
            $_SESSION['flash_success'] = 'Rôle retiré avec succès';
        } else {
            $_SESSION['flash_error'] = 'Erreur lors du retrait du rôle';
        }

        return $response->withHeader('Location', '/admin/roles')->withStatus(302);
    }
}
