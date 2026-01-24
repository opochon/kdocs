<?php
/**
 * K-Docs - Contrôleur API pour les mappings de catégories IA
 */

namespace KDocs\Controllers\Api;

use KDocs\Services\CategoryMappingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CategoryMappingApiController
{
    /**
     * Crée un tag depuis une catégorie
     * POST /api/category-mapping/create-tag
     */
    public function createTag(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['category_name']) || empty($data['tag_name'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'error' => 'category_name et tag_name requis'
            ]);
        }
        
        try {
            $service = new CategoryMappingService();
            $tagId = $service->createTagFromCategory(
                $data['category_name'],
                $data['tag_name']
            );
            
            return $response->withHeader('Content-Type', 'application/json')->withJson([
                'success' => true,
                'tag_id' => $tagId,
                'message' => 'Tag créé avec succès'
            ]);
        } catch (\PDOException $e) {
            error_log("CategoryMappingApiController::createTag - PDO Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => 'Erreur de base de données. La table category_mappings existe-t-elle ?',
                    'details' => $e->getMessage()
                ]);
        } catch (\Exception $e) {
            error_log("CategoryMappingApiController::createTag - Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
        }
    }
    
    /**
     * Crée un champ de classification depuis une catégorie
     * POST /api/category-mapping/create-field
     */
    public function createField(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['category_name']) || empty($data['field_name']) || empty($data['field_code'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'error' => 'category_name, field_name et field_code requis'
            ]);
        }
        
        try {
            $service = new CategoryMappingService();
            $fieldId = $service->createClassificationFieldFromCategory(
                $data['category_name'],
                $data['field_name'],
                $data['field_code']
            );
            
            return $response->withHeader('Content-Type', 'application/json')->withJson([
                'success' => true,
                'field_id' => $fieldId,
                'message' => 'Champ créé avec succès'
            ]);
        } catch (\PDOException $e) {
            error_log("CategoryMappingApiController::createField - PDO Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => 'Erreur de base de données. La table category_mappings existe-t-elle ?',
                    'details' => $e->getMessage()
                ]);
        } catch (\Exception $e) {
            error_log("CategoryMappingApiController::createField - Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
        }
    }
    
    /**
     * Mappe une catégorie sur un tag
     * POST /api/category-mapping/map-to-tag
     */
    public function mapToTag(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['category_name']) || empty($data['tag_id']) || empty($data['tag_name'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'error' => 'category_name, tag_id et tag_name requis'
            ]);
        }
        
        try {
            $service = new CategoryMappingService();
            $mappingId = $service->mapToTag(
                $data['category_name'],
                (int)$data['tag_id'],
                $data['tag_name']
            );
            
            $service->incrementUsage($mappingId);
            
            return $response->withJson([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Mapping créé avec succès'
            ]);
        } catch (\Exception $e) {
            return $response->withStatus(500)->withJson([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Mappe une catégorie sur un champ de classification
     * POST /api/category-mapping/map-to-field
     */
    public function mapToField(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['category_name']) || empty($data['field_id']) || empty($data['field_name'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'error' => 'category_name, field_id et field_name requis'
            ]);
        }
        
        try {
            $service = new CategoryMappingService();
            $mappingId = $service->mapToClassificationField(
                $data['category_name'],
                (int)$data['field_id'],
                $data['field_name']
            );
            
            $service->incrementUsage($mappingId);
            
            return $response->withHeader('Content-Type', 'application/json')->withJson([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Mapping créé avec succès'
            ]);
        } catch (\PDOException $e) {
            error_log("CategoryMappingApiController::mapToField - PDO Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => 'Erreur de base de données. La table category_mappings existe-t-elle ?',
                    'details' => $e->getMessage()
                ]);
        } catch (\Exception $e) {
            error_log("CategoryMappingApiController::mapToField - Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
        }
    }
    
    /**
     * Mappe une catégorie sur un correspondant
     * POST /api/category-mapping/map-to-correspondent
     */
    public function mapToCorrespondent(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['category_name']) || empty($data['correspondent_id']) || empty($data['correspondent_name'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'error' => 'category_name, correspondent_id et correspondent_name requis'
            ]);
        }
        
        try {
            $service = new CategoryMappingService();
            $mappingId = $service->mapToCorrespondent(
                $data['category_name'],
                (int)$data['correspondent_id'],
                $data['correspondent_name']
            );
            
            $service->incrementUsage($mappingId);
            
            return $response->withHeader('Content-Type', 'application/json')->withJson([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Mapping créé avec succès'
            ]);
        } catch (\PDOException $e) {
            error_log("CategoryMappingApiController::mapToCorrespondent - PDO Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => 'Erreur de base de données. La table category_mappings existe-t-elle ?',
                    'details' => $e->getMessage()
                ]);
        } catch (\Exception $e) {
            error_log("CategoryMappingApiController::mapToCorrespondent - Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
        }
    }
    
    /**
     * Crée un correspondant depuis une catégorie
     * POST /api/category-mapping/create-correspondent
     */
    public function createCorrespondent(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['category_name']) || empty($data['correspondent_name'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'error' => 'category_name et correspondent_name requis'
            ]);
        }
        
        try {
            $db = \KDocs\Core\Database::getInstance();
            
            // Créer le correspondant
            $stmt = $db->prepare("INSERT INTO correspondents (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['correspondent_name'])));
            $stmt->execute([$data['correspondent_name'], $slug]);
            $correspondentId = (int)$db->lastInsertId();
            
            // Créer le mapping
            $service = new CategoryMappingService();
            $mappingId = $service->mapToCorrespondent(
                $data['category_name'],
                $correspondentId,
                $data['correspondent_name']
            );
            
            return $response->withHeader('Content-Type', 'application/json')->withJson([
                'success' => true,
                'correspondent_id' => $correspondentId,
                'correspondent_name' => $data['correspondent_name'],
                'mapping_id' => $mappingId,
                'message' => 'Correspondant créé avec succès'
            ]);
        } catch (\PDOException $e) {
            error_log("CategoryMappingApiController::createCorrespondent - PDO Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => 'Erreur de base de données. La table category_mappings existe-t-elle ?',
                    'details' => $e->getMessage()
                ]);
        } catch (\Exception $e) {
            error_log("CategoryMappingApiController::createCorrespondent - Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
        }
    }
    
    /**
     * Mappe une catégorie sur un type de document
     * POST /api/category-mapping/map-to-type
     */
    public function mapToType(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['category_name']) || empty($data['type_id']) || empty($data['type_name'])) {
            return $response->withStatus(400)->withJson([
                'success' => false,
                'error' => 'category_name, type_id et type_name requis'
            ]);
        }
        
        try {
            $service = new CategoryMappingService();
            $mappingId = $service->mapToDocumentType(
                $data['category_name'],
                (int)$data['type_id'],
                $data['type_name']
            );
            
            $service->incrementUsage($mappingId);
            
            return $response->withHeader('Content-Type', 'application/json')->withJson([
                'success' => true,
                'mapping_id' => $mappingId,
                'message' => 'Mapping créé avec succès'
            ]);
        } catch (\PDOException $e) {
            error_log("CategoryMappingApiController::mapToType - PDO Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => 'Erreur de base de données. La table category_mappings existe-t-elle ?',
                    'details' => $e->getMessage()
                ]);
        } catch (\Exception $e) {
            error_log("CategoryMappingApiController::mapToType - Error: " . $e->getMessage());
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500)
                ->withJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
        }
    }
}
