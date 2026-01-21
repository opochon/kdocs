<?php
/**
 * K-Docs - API REST pour Correspondents
 */

namespace KDocs\Controllers\Api;

use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class CorrespondentsApiController extends ApiController
{
    /**
     * Liste des correspondants (GET /api/correspondents)
     */
    public function index(Request $request, Response $response): Response
    {
        $db = Database::getInstance();
        $correspondents = $db->query("SELECT * FROM correspondents ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        
        return $this->successResponse($response, $correspondents);
    }

    /**
     * Détails d'un correspondant (GET /api/correspondents/{id})
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM correspondents WHERE id = ?");
        $stmt->execute([$id]);
        $correspondent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$correspondent) {
            return $this->errorResponse($response, 'Correspondant non trouvé', 404);
        }
        
        return $this->successResponse($response, $correspondent);
    }

    /**
     * Créer un correspondant (POST /api/correspondents)
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (empty($data['name'])) {
            return $this->errorResponse($response, 'name est requis');
        }
        
        $db = Database::getInstance();
        
        try {
            $stmt = $db->prepare("INSERT INTO correspondents (name, match, matching_algorithm) VALUES (?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['match'] ?? null,
                $data['matching_algorithm'] ?? 'none'
            ]);
            
            $id = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM correspondents WHERE id = ?");
            $stmt->execute([$id]);
            $correspondent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $this->successResponse($response, $correspondent, 'Correspondant créé avec succès', 201);
            
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la création : ' . $e->getMessage(), 500);
        }
    }

    /**
     * Mettre à jour un correspondant (PUT /api/correspondents/{id})
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM correspondents WHERE id = ?");
        $stmt->execute([$id]);
        $correspondent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$correspondent) {
            return $this->errorResponse($response, 'Correspondant non trouvé', 404);
        }
        
        try {
            $updateFields = [];
            $updateParams = [];
            
            if (isset($data['name'])) {
                $updateFields[] = 'name = ?';
                $updateParams[] = $data['name'];
            }
            
            if (isset($data['match'])) {
                $updateFields[] = 'match = ?';
                $updateParams[] = $data['match'];
            }
            
            if (isset($data['matching_algorithm'])) {
                $updateFields[] = 'matching_algorithm = ?';
                $updateParams[] = $data['matching_algorithm'];
            }
            
            if (!empty($updateFields)) {
                $updateParams[] = $id;
                $sql = "UPDATE correspondents SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($updateParams);
            }
            
            $stmt = $db->prepare("SELECT * FROM correspondents WHERE id = ?");
            $stmt->execute([$id]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $this->successResponse($response, $updated, 'Correspondant mis à jour avec succès');
            
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la mise à jour : ' . $e->getMessage(), 500);
        }
    }

    /**
     * Supprimer un correspondant (DELETE /api/correspondents/{id})
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $db = Database::getInstance();
        
        try {
            $stmt = $db->prepare("DELETE FROM correspondents WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'Correspondant non trouvé', 404);
            }
            
            return $this->successResponse($response, null, 'Correspondant supprimé avec succès');
            
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la suppression : ' . $e->getMessage(), 500);
        }
    }
}
