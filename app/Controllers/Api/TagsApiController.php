<?php
/**
 * K-Docs - API REST pour Tags
 */

namespace KDocs\Controllers\Api;

use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class TagsApiController extends ApiController
{
    /**
     * Liste des tags (GET /api/tags)
     */
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getInstance();
        $tags = $db->query("SELECT * FROM tags ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->successResponse($response, $tags);
    }

    /**
     * Détails d'un tag (GET /api/tags/{id})
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
        $stmt->execute([$id]);
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tag) {
            return $this->errorResponse($response, 'Tag non trouvé', 404);
        }
        
        return $this->successResponse($response, $tag);
    }

    /**
     * Créer un tag (POST /api/tags)
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (empty($data['name'])) {
            return $this->errorResponse($response, 'name est requis');
        }
        
        $db = Database::getInstance();
        
        try {
            $stmt = $db->prepare("INSERT INTO tags (name, color, match, matching_algorithm, parent_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['color'] ?? null,
                $data['match'] ?? null,
                $data['matching_algorithm'] ?? 'none',
                !empty($data['parent_id']) ? (int)$data['parent_id'] : null
            ]);
            
            $id = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
            $stmt->execute([$id]);
            $tag = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $this->successResponse($response, $tag, 'Tag créé avec succès', 201);
            
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la création : ' . $e->getMessage(), 500);
        }
    }

    /**
     * Mettre à jour un tag (PUT /api/tags/{id})
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
        $stmt->execute([$id]);
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tag) {
            return $this->errorResponse($response, 'Tag non trouvé', 404);
        }
        
        try {
            $updateFields = [];
            $updateParams = [];
            
            if (isset($data['name'])) {
                $updateFields[] = 'name = ?';
                $updateParams[] = $data['name'];
            }
            
            if (isset($data['color'])) {
                $updateFields[] = 'color = ?';
                $updateParams[] = $data['color'];
            }
            
            if (isset($data['match'])) {
                $updateFields[] = 'match = ?';
                $updateParams[] = $data['match'];
            }
            
            if (isset($data['matching_algorithm'])) {
                $updateFields[] = 'matching_algorithm = ?';
                $updateParams[] = $data['matching_algorithm'];
            }
            
            if (isset($data['parent_id'])) {
                $updateFields[] = 'parent_id = ?';
                $updateParams[] = $data['parent_id'] ? (int)$data['parent_id'] : null;
            }
            
            if (!empty($updateFields)) {
                $updateParams[] = $id;
                $sql = "UPDATE tags SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($updateParams);
            }
            
            $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
            $stmt->execute([$id]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $this->successResponse($response, $updated, 'Tag mis à jour avec succès');
            
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la mise à jour : ' . $e->getMessage(), 500);
        }
    }

    /**
     * Supprimer un tag (DELETE /api/tags/{id})
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $db = Database::getInstance();
        
        try {
            $stmt = $db->prepare("DELETE FROM tags WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'Tag non trouvé', 404);
            }
            
            return $this->successResponse($response, null, 'Tag supprimé avec succès');
            
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la suppression : ' . $e->getMessage(), 500);
        }
    }
}
