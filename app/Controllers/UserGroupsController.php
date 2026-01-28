<?php
/**
 * K-Docs - UserGroupsController
 * Gestion des groupes d'utilisateurs (CRUD admin)
 */

namespace KDocs\Controllers;

use KDocs\Core\Database;
use KDocs\Models\UserGroup;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserGroupsController
{
    private UserGroup $model;

    public function __construct()
    {
        $this->model = new UserGroup();
    }

    /**
     * Liste des groupes
     */
    public function index(Request $request, Response $response): Response
    {
        $groups = $this->model->getAll();

        // Compter les membres pour chaque groupe
        $db = Database::getInstance();
        foreach ($groups as &$group) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_group_memberships WHERE group_id = ?");
            $stmt->execute([$group['id']]);
            $group['member_count'] = (int)$stmt->fetchColumn();
        }

        $user = $request->getAttribute('user');
        $pageTitle = 'Groupes d\'utilisateurs';

        ob_start();
        include __DIR__ . '/../../templates/admin/user-groups/index.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../templates/layouts/main.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Formulaire de creation/edition
     */
    public function showForm(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;
        $group = null;
        $members = [];

        if ($id) {
            $group = $this->model->findById((int)$id);
            if (!$group) {
                return $response->withHeader('Location', url('/admin/user-groups'))->withStatus(302);
            }
            $members = $this->model->getMembers((int)$id);
        }

        // Recuperer tous les utilisateurs pour le select
        $db = Database::getInstance();
        $users = $db->query("SELECT id, username, email, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE is_active = 1 ORDER BY username")->fetchAll(\PDO::FETCH_ASSOC);

        $user = $request->getAttribute('user');
        $pageTitle = $group ? 'Modifier le groupe' : 'Nouveau groupe';

        ob_start();
        include __DIR__ . '/../../templates/admin/user-groups/form.php';
        $content = ob_get_clean();

        ob_start();
        include __DIR__ . '/../../templates/layouts/main.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Sauvegarde d'un groupe
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;
        $data = $request->getParsedBody();

        $groupData = [
            'name' => $data['name'] ?? '',
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'permissions' => []
        ];

        // Permissions
        if (!empty($data['permissions'])) {
            $groupData['permissions'] = is_array($data['permissions']) ? $data['permissions'] : json_decode($data['permissions'], true);
        }

        try {
            if ($id) {
                $this->model->update((int)$id, $groupData);
                $groupId = (int)$id;
            } else {
                $groupId = $this->model->create($groupData);
            }

            // Gerer les membres
            if (isset($data['members'])) {
                $this->updateGroupMembers($groupId, $data['members']);
            }

            $_SESSION['flash_success'] = 'Groupe enregistre avec succes';
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Erreur: ' . $e->getMessage();
        }

        return $response->withHeader('Location', url('/admin/user-groups'))->withStatus(302);
    }

    /**
     * Suppression d'un groupe
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        $group = $this->model->findById($id);
        if (!$group) {
            $_SESSION['flash_error'] = 'Groupe non trouve';
            return $response->withHeader('Location', url('/admin/user-groups'))->withStatus(302);
        }

        // Verifier si c'est un groupe systeme
        if ($group['is_system'] ?? false) {
            $_SESSION['flash_error'] = 'Impossible de supprimer un groupe systeme';
            return $response->withHeader('Location', url('/admin/user-groups'))->withStatus(302);
        }

        try {
            $this->model->delete($id);
            $_SESSION['flash_success'] = 'Groupe supprime';
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Erreur: ' . $e->getMessage();
        }

        return $response->withHeader('Location', url('/admin/user-groups'))->withStatus(302);
    }

    /**
     * Met a jour les membres d'un groupe
     */
    private function updateGroupMembers(int $groupId, array $userIds): void
    {
        $db = Database::getInstance();

        // Supprimer les membres actuels
        $stmt = $db->prepare("DELETE FROM user_group_memberships WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // Ajouter les nouveaux membres
        foreach ($userIds as $userId) {
            if ($userId) {
                $this->model->addUser($groupId, (int)$userId);
            }
        }
    }

    // ============== API ==============

    /**
     * API: Liste des groupes
     */
    public function apiIndex(Request $request, Response $response): Response
    {
        $groups = $this->model->getAll();

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $groups
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Detail d'un groupe
     */
    public function apiShow(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $group = $this->model->findById($id);

        if (!$group) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Groupe non trouve']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $group['members'] = $this->model->getMembers($id);

        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $group
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
