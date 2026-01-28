<?php
/**
 * K-Docs - Contrôleur de gestion des utilisateurs
 */

namespace KDocs\Controllers;

use KDocs\Models\User;
use KDocs\Models\UserGroup;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UsersController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Liste des utilisateurs
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('UsersController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        if (!User::hasPermission($user, 'users.view')) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/documents')
                ->withStatus(302);
        }
        
        $userModel = new User();
        $users = $userModel->getAll();
        
        // Récupérer les groupes pour chaque utilisateur
        foreach ($users as &$u) {
            $u['groups'] = $userModel->getUserGroups($u['id']);
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/users_list.php', [
            'users' => $users,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Gestion des utilisateurs - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Gestion des utilisateurs'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Affiche le formulaire de création/édition
     */
    public function showForm(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('UsersController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        if (!User::hasPermission($user, 'users.edit')) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/users')
                ->withStatus(302);
        }
        
        $id = !empty($args['id']) ? (int)$args['id'] : null;
        $userData = null;
        
        if ($id) {
            $userData = User::findById($id);
            if (!$userData) {
                $basePath = \KDocs\Core\Config::basePath();
                return $response
                    ->withHeader('Location', $basePath . '/admin/users')
                    ->withStatus(302);
            }
        }
        
        $groups = [];
        $userGroups = [];
        
        try {
            $groupModel = new UserGroup();
            $groups = $groupModel->getAll();
            
            $userModel = new User();
            $userGroups = $id ? $userModel->getUserGroups($id) : [];
        } catch (\Exception $e) {
            // Table user_groups n'existe peut-être pas encore
            error_log("Erreur chargement groupes utilisateurs: " . $e->getMessage());
        }
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/user_form.php', [
            'user' => $userData,
            'groups' => $groups,
            'userGroups' => $userGroups,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($id ? 'Modifier' : 'Créer') . ' un utilisateur - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($id ? 'Modifier' : 'Créer') . ' un utilisateur'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Crée ou met à jour un utilisateur
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('UsersController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        if (!User::hasPermission($user, 'users.edit')) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/users')
                ->withStatus(302);
        }
        
        $id = !empty($args['id']) ? (int)$args['id'] : null;
        $data = $request->getParsedBody();
        
        $userModel = new User();
        
        if ($id) {
            // Mise à jour
            $updateData = [
                'username' => $data['username'] ?? '',
                'email' => $data['email'] ?? null,
            ];

            if (!empty($data['password'])) {
                $updateData['password'] = $data['password'];
            }

            if (isset($data['is_active'])) {
                $updateData['is_active'] = (bool)$data['is_active'];
            }

            $userModel->update($id, $updateData);
            
            // Gérer les groupes
            $groupModel = new UserGroup();
            if (isset($data['groups']) && is_array($data['groups'])) {
                // Récupérer les groupes actuels
                $currentGroups = $userModel->getUserGroups($id);
                $currentGroupIds = array_column($currentGroups, 'id');
                
                // Ajouter les nouveaux groupes
                foreach ($data['groups'] as $groupId) {
                    $groupId = (int)$groupId;
                    if ($groupId > 0 && !in_array($groupId, $currentGroupIds)) {
                        $groupModel->addUser($groupId, $id);
                    }
                }
                
                // Retirer les groupes non sélectionnés
                foreach ($currentGroupIds as $groupId) {
                    if (!in_array($groupId, $data['groups'])) {
                        $groupModel->removeUser($groupId, $id);
                    }
                }
            }
        } else {
            // Création
            if (empty($data['username']) || empty($data['password'])) {
                $basePath = \KDocs\Core\Config::basePath();
                return $response
                    ->withHeader('Location', $basePath . '/admin/users')
                    ->withStatus(302);
            }

            $createData = [
                'username' => $data['username'],
                'password' => $data['password'],
                'email' => $data['email'] ?? null,
                'is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
            ];

            $newId = $userModel->create($createData);
            
            // Ajouter aux groupes
            if (!empty($data['groups']) && is_array($data['groups'])) {
                $groupModel = new UserGroup();
                foreach ($data['groups'] as $groupId) {
                    $groupId = (int)$groupId;
                    if ($groupId > 0) {
                        $groupModel->addUser($groupId, $newId);
                    }
                }
            }
        }
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response
            ->withHeader('Location', $basePath . '/admin/users')
            ->withStatus(302);
    }

    /**
     * Supprime un utilisateur
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('UsersController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        if (!User::hasPermission($user, 'users.delete')) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/users')
                ->withStatus(302);
        }
        
        $id = (int)$args['id'];
        
        // Ne pas permettre la suppression de soi-même
        if ($id === ($user['id'] ?? 0)) {
            $basePath = \KDocs\Core\Config::basePath();
            return $response
                ->withHeader('Location', $basePath . '/admin/users?error=self_delete')
                ->withStatus(302);
        }
        
        $userModel = new User();
        $userModel->delete($id);
        
        $basePath = \KDocs\Core\Config::basePath();
        return $response
            ->withHeader('Location', $basePath . '/admin/users')
            ->withStatus(302);
    }
}
