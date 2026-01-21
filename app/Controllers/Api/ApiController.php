<?php
/**
 * K-Docs - Contrôleur API de base
 * Fournit des méthodes communes pour tous les contrôleurs API
 */

namespace KDocs\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;

abstract class ApiController
{
    /**
     * Retourne une réponse JSON avec statut HTTP
     */
    protected function jsonResponse(Response $response, $data, int $statusCode = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }

    /**
     * Retourne une réponse d'erreur JSON
     */
    protected function errorResponse(Response $response, string $message, int $statusCode = 400, array $errors = []): Response
    {
        $data = [
            'error' => true,
            'message' => $message
        ];
        
        if (!empty($errors)) {
            $data['errors'] = $errors;
        }
        
        return $this->jsonResponse($response, $data, $statusCode);
    }

    /**
     * Retourne une réponse de succès JSON
     */
    protected function successResponse(Response $response, $data, string $message = null, int $statusCode = 200): Response
    {
        $responseData = [
            'success' => true,
            'data' => $data
        ];
        
        if ($message) {
            $responseData['message'] = $message;
        }
        
        return $this->jsonResponse($response, $responseData, $statusCode);
    }

    /**
     * Retourne une réponse paginée
     */
    protected function paginatedResponse(Response $response, array $data, int $page, int $perPage, int $total): Response
    {
        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage)
            ]
        ]);
    }

    /**
     * Valide les paramètres de pagination
     */
    protected function getPaginationParams(array $queryParams): array
    {
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $perPage = min(100, max(1, (int)($queryParams['per_page'] ?? 20)));
        
        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage
        ];
    }
}
