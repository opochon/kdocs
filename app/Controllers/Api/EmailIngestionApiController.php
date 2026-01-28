<?php
/**
 * K-Docs - EmailIngestionApiController
 * API pour la gestion des comptes email et l'ingestion
 */

namespace KDocs\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use KDocs\Services\EmailIngestionService;

class EmailIngestionApiController
{
    private EmailIngestionService $service;

    public function __construct()
    {
        $this->service = new EmailIngestionService();
    }

    /**
     * Liste tous les comptes email
     */
    public function getAccounts(Request $request, Response $response): Response
    {
        try {
            $accounts = $this->service->getAccounts();

            // Masquer les mots de passe
            foreach ($accounts as &$account) {
                $account['password'] = $account['password'] ? '********' : '';
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'accounts' => $accounts
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Récupère un compte par ID
     */
    public function getAccount(Request $request, Response $response, array $args): Response
    {
        try {
            $account = $this->service->getAccount((int) $args['id']);

            if (!$account) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Compte non trouvé'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Masquer le mot de passe
            $account['password'] = $account['password'] ? '********' : '';

            $response->getBody()->write(json_encode([
                'success' => true,
                'account' => $account
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Crée un nouveau compte email
     */
    public function createAccount(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();

            // Validation basique
            $required = ['name', 'host', 'username', 'password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => "Le champ '$field' est requis"
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }

            $id = $this->service->createAccount($data);

            $response->getBody()->write(json_encode([
                'success' => true,
                'id' => $id,
                'message' => 'Compte créé avec succès'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Met à jour un compte email
     */
    public function updateAccount(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $data = $request->getParsedBody();

            // Si le mot de passe est masqué, ne pas le mettre à jour
            if (isset($data['password']) && $data['password'] === '********') {
                unset($data['password']);
            }

            $success = $this->service->updateAccount($id, $data);

            $response->getBody()->write(json_encode([
                'success' => $success,
                'message' => $success ? 'Compte mis à jour' : 'Aucune modification'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Supprime un compte email
     */
    public function deleteAccount(Request $request, Response $response, array $args): Response
    {
        try {
            $success = $this->service->deleteAccount((int) $args['id']);

            $response->getBody()->write(json_encode([
                'success' => $success,
                'message' => $success ? 'Compte supprimé' : 'Erreur lors de la suppression'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Teste la connexion à un compte
     */
    public function testConnection(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int) $args['id'];
            $account = $this->service->getAccount($id);

            if (!$account) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Compte non trouvé'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $result = $this->service->testConnection($account);

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Teste la connexion avec des paramètres (avant création)
     */
    public function testConnectionParams(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();

            $required = ['host', 'username', 'password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => "Le champ '$field' est requis pour le test"
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }

            $result = $this->service->testConnection($data);

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Traite les emails d'un compte
     */
    public function processAccount(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->service->processAccount((int) $args['id']);

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Traite tous les comptes actifs
     */
    public function processAll(Request $request, Response $response): Response
    {
        try {
            $results = $this->service->processAllAccounts();

            $response->getBody()->write(json_encode([
                'success' => true,
                'results' => $results
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Récupère les logs d'ingestion
     */
    public function getLogs(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $accountId = isset($params['account_id']) ? (int) $params['account_id'] : null;
            $limit = isset($params['limit']) ? min((int) $params['limit'], 500) : 100;

            $logs = $this->service->getLogs($accountId, $limit);

            $response->getBody()->write(json_encode([
                'success' => true,
                'logs' => $logs
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
