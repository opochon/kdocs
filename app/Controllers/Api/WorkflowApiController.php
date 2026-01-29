<?php
/**
 * K-Docs - Workflow API Controller
 * API pour le designer de workflows et les options dynamiques
 */

namespace KDocs\Controllers\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use KDocs\Core\Database;
use KDocs\Workflow\Nodes\NodeExecutorFactory;

class WorkflowApiController extends ApiController
{
    /**
     * GET /api/workflow/node-catalog
     * Retourne le catalogue complet des nodes pour le designer
     */
    public function getNodeCatalog(Request $request, Response $response): Response
    {
        return $this->successResponse($response, NodeExecutorFactory::getNodeTypes());
    }

    /**
     * GET /api/workflow/node-config/{type}
     * Retourne le schema de configuration d'un type de node
     */
    public function getNodeConfig(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'] ?? '';

        $info = NodeExecutorFactory::getNodeInfo($type);
        if (!$info) {
            return $this->errorResponse($response, 'Type de node non trouvé', 404);
        }

        return $this->successResponse($response, $info);
    }

    /**
     * GET /api/workflow/options
     * Retourne toutes les options pour les selects du designer
     */
    public function getOptions(Request $request, Response $response): Response
    {
        $db = Database::getInstance();

        $options = [];

        // Utilisateurs actifs
        $stmt = $db->query("
            SELECT id, username, email, first_name, last_name
            FROM users
            WHERE is_active = 1
            ORDER BY username
        ");
        $options['users'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Groupes d'utilisateurs
        $stmt = $db->query("
            SELECT id, name, code, description
            FROM groups
            ORDER BY name
        ");
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $options['groups'] = $groups ?: $this->getDefaultGroups();

        // Tags
        $stmt = $db->query("
            SELECT id, name, color
            FROM tags
            ORDER BY name
        ");
        $options['tags'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Correspondants
        $stmt = $db->query("
            SELECT id, name
            FROM correspondents
            ORDER BY name
        ");
        $options['correspondents'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Types de documents
        $stmt = $db->query("
            SELECT id, label, slug
            FROM document_types
            ORDER BY label
        ");
        $options['document_types'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Champs personnalisés (custom_fields)
        $stmt = $db->query("
            SELECT id, name, label, data_type
            FROM custom_fields
            ORDER BY name
        ");
        $customFields = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $options['custom_fields'] = $customFields ?: [];

        // Champs de classification
        $stmt = $db->query("
            SELECT id, name, label
            FROM classification_fields
            WHERE is_active = 1
            ORDER BY name
        ");
        $classificationFields = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $options['classification_fields'] = $classificationFields ?: [];

        // Chemins de stockage
        $stmt = $db->query("
            SELECT id, path, label
            FROM storage_paths
            ORDER BY path
        ");
        $options['storage_paths'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Champs standard du document
        $options['standard_fields'] = [
            ['value' => 'title', 'label' => 'Titre'],
            ['value' => 'content', 'label' => 'Contenu'],
            ['value' => 'original_filename', 'label' => 'Nom de fichier'],
            ['value' => 'document_date', 'label' => 'Date du document'],
            ['value' => 'added_date', 'label' => 'Date d\'ajout'],
            ['value' => 'amount', 'label' => 'Montant'],
            ['value' => 'currency', 'label' => 'Devise'],
            ['value' => 'correspondent_id', 'label' => 'Correspondant'],
            ['value' => 'document_type_id', 'label' => 'Type de document'],
        ];

        return $this->successResponse($response, $options);
    }

    /**
     * Retourne les groupes par défaut si la table n'existe pas encore
     */
    private function getDefaultGroups(): array
    {
        return [
            ['id' => 1, 'name' => 'Administrateurs', 'code' => 'ADMIN', 'description' => 'Administrateurs système'],
            ['id' => 2, 'name' => 'Superviseurs', 'code' => 'SUPERVISORS', 'description' => 'Superviseurs'],
            ['id' => 3, 'name' => 'Comptabilité', 'code' => 'ACCOUNTING', 'description' => 'Service comptabilité'],
            ['id' => 4, 'name' => 'Direction', 'code' => 'MANAGEMENT', 'description' => 'Direction'],
            ['id' => 5, 'name' => 'Utilisateurs', 'code' => 'USERS', 'description' => 'Utilisateurs standards'],
        ];
    }
}
