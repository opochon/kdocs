<?php
/**
 * K-Docs - Contrôleur MailAccountsController
 * Gestion des comptes email et règles
 */

namespace KDocs\Controllers;

use KDocs\Models\MailAccount;
use KDocs\Models\MailRule;
use KDocs\Services\MailService;
use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MailAccountsController
{
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }
    
    /**
     * Liste des comptes email
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('MailAccountsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $accounts = MailAccount::all();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/mail_accounts.php', [
            'accounts' => $accounts
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Comptes Email - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Comptes Email',
            'currentPage' => 'mail-accounts'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Formulaire de création/édition
     */
    public function showForm(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('MailAccountsController::showForm', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = isset($args['id']) ? (int)$args['id'] : null;
        
        $account = $id ? MailAccount::find($id) : null;
        $rules = $id ? MailRule::getByAccount($id) : [];
        
        $db = Database::getInstance();
        $documentTypes = $db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll();
        $storagePaths = $db->query("SELECT id, name FROM storage_paths ORDER BY name")->fetchAll();
        $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/mail_account_form.php', [
            'account' => $account,
            'rules' => $rules,
            'documentTypes' => $documentTypes,
            'storagePaths' => $storagePaths,
            'tags' => $tags
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => ($id ? 'Modifier' : 'Nouveau') . ' Compte Email - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => ($id ? 'Modifier' : 'Nouveau') . ' Compte Email',
            'currentPage' => 'mail-accounts'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Sauvegarde d'un compte
     */
    public function save(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('MailAccountsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $basePath = \KDocs\Core\Config::basePath();
        
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        
        try {
            if ($id) {
                MailAccount::update($id, $data);
            } else {
                $id = MailAccount::create($data);
            }
            
            return $response
                ->withHeader('Location', $basePath . '/admin/mail-accounts?success=1')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $response
                ->withHeader('Location', $basePath . '/admin/mail-accounts?error=' . urlencode($e->getMessage()))
                ->withStatus(302);
        }
    }
    
    /**
     * Test de connexion
     */
    public function testConnection(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('MailAccountsController::testConnection', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $id = (int)$args['id'];
        $service = new \KDocs\Services\EmailIngestionService();
        $result = $service->testConnection($id);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Traitement manuel des emails
     */
    public function process(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('MailAccountsController::process', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $id = (int)$args['id'];
        $service = new \KDocs\Services\EmailIngestionService();
        $result = $service->processAccount($id);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Suppression d'un compte
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('MailAccountsController::delete', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];
        $basePath = \KDocs\Core\Config::basePath();
        
        MailAccount::delete($id);
        
        return $response
            ->withHeader('Location', $basePath . '/admin/mail-accounts?success=1')
            ->withStatus(302);
    }
}
