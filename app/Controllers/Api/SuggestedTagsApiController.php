<?php
/**
 * K-Docs - Contrôleur API pour les tags suggérés
 */

namespace KDocs\Controllers\Api;

use KDocs\Core\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SuggestedTagsApiController extends ApiController
{
    /**
     * Marque un tag suggéré comme non pertinent (irrelevant)
     * POST /api/suggested-tags/mark-irrelevant
     */
    public function markIrrelevant(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (empty($data['tag_name']) || empty($data['document_id'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'tag_name et document_id requis'
            ], 400);
        }
        
        try {
            $db = Database::getInstance();
            
            // Récupérer les tags ignorés actuels
            $stmt = $db->prepare("SELECT ai_ignored_tags FROM documents WHERE id = ?");
            $stmt->execute([$data['document_id']]);
            $doc = $stmt->fetch();
            
            $ignoredTags = [];
            if ($doc && !empty($doc['ai_ignored_tags'])) {
                $ignoredTags = json_decode($doc['ai_ignored_tags'], true) ?: [];
            }
            
            // Ajouter le tag à la liste des ignorés
            if (!in_array($data['tag_name'], $ignoredTags)) {
                $ignoredTags[] = $data['tag_name'];
            }
            
            // Sauvegarder
            $updateStmt = $db->prepare("UPDATE documents SET ai_ignored_tags = ? WHERE id = ?");
            $updateStmt->execute([json_encode($ignoredTags), $data['document_id']]);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Tag marqué comme non pertinent'
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
