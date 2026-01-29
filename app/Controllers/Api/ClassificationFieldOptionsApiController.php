<?php
/**
 * K-Docs - Classification Field Options API Controller
 * API REST pour les options de champs de classification
 */

namespace KDocs\Controllers\Api;

use KDocs\Models\ClassificationFieldOption;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ClassificationFieldOptionsApiController extends ApiController
{
    /**
     * GET /api/classification-field-options
     * Liste toutes les options (groupées par champ)
     */
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $activeOnly = !isset($queryParams['all']);
        $grouped = !isset($queryParams['flat']);

        if ($grouped) {
            $options = ClassificationFieldOption::getAllGrouped($activeOnly);
        } else {
            $options = $activeOnly ? ClassificationFieldOption::getActive() : ClassificationFieldOption::all();
        }

        return $this->successResponse($response, [
            'options' => $options,
            'field_codes' => ClassificationFieldOption::getFieldCodes()
        ]);
    }

    /**
     * GET /api/classification-field-options/field/{fieldCode}
     * Liste les options pour un champ spécifique
     */
    public function getForField(Request $request, Response $response, array $args): Response
    {
        $fieldCode = $args['fieldCode'];
        $queryParams = $request->getQueryParams();
        $activeOnly = !isset($queryParams['all']);

        $options = ClassificationFieldOption::getForField($fieldCode, $activeOnly);

        return $this->successResponse($response, $options);
    }

    /**
     * POST /api/classification-field-options
     * Crée une nouvelle option
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        // Validation
        if (empty($data['field_code'])) {
            return $this->errorResponse($response, 'field_code est requis');
        }

        if (empty($data['option_value'])) {
            return $this->errorResponse($response, 'option_value est requis');
        }

        if (empty($data['option_label'])) {
            return $this->errorResponse($response, 'option_label est requis');
        }

        // Vérifier les codes de champ valides
        $validFieldCodes = array_keys(ClassificationFieldOption::getFieldCodes());
        if (!in_array($data['field_code'], $validFieldCodes)) {
            return $this->errorResponse($response, 'field_code invalide');
        }

        // Vérifier l'unicité
        if (ClassificationFieldOption::valueExists($data['field_code'], $data['option_value'])) {
            return $this->errorResponse($response, 'Cette valeur existe déjà pour ce champ');
        }

        try {
            $id = ClassificationFieldOption::create([
                'field_code' => $data['field_code'],
                'option_value' => $data['option_value'],
                'option_label' => $data['option_label'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0
            ]);

            $option = ClassificationFieldOption::find($id);
            return $this->successResponse($response, $option, 'Option créée', 201);

        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la création: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/classification-field-options/{id}
     * Récupère une option par ID
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $option = ClassificationFieldOption::find($id);

        if (!$option) {
            return $this->errorResponse($response, 'Option non trouvée', 404);
        }

        return $this->successResponse($response, $option);
    }

    /**
     * PUT /api/classification-field-options/{id}
     * Met à jour une option
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $option = ClassificationFieldOption::find($id);
        if (!$option) {
            return $this->errorResponse($response, 'Option non trouvée', 404);
        }

        // Vérifier l'unicité si la valeur change
        if (isset($data['option_value']) && $data['option_value'] !== $option['option_value']) {
            if (ClassificationFieldOption::valueExists($option['field_code'], $data['option_value'], $id)) {
                return $this->errorResponse($response, 'Cette valeur existe déjà pour ce champ');
            }
        }

        try {
            ClassificationFieldOption::update($id, $data);
            $updatedOption = ClassificationFieldOption::find($id);
            return $this->successResponse($response, $updatedOption, 'Option mise à jour');
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la mise à jour: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/classification-field-options/{id}
     * Supprime une option
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $option = ClassificationFieldOption::find($id);
        if (!$option) {
            return $this->errorResponse($response, 'Option non trouvée', 404);
        }

        try {
            ClassificationFieldOption::delete($id);
            return $this->successResponse($response, ['id' => $id], 'Option supprimée');
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de la suppression: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/classification-field-options/import
     * Importe des options en masse
     */
    public function import(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data['field_code'])) {
            return $this->errorResponse($response, 'field_code est requis');
        }

        if (empty($data['options']) || !is_array($data['options'])) {
            return $this->errorResponse($response, 'options (tableau) est requis');
        }

        $validFieldCodes = array_keys(ClassificationFieldOption::getFieldCodes());
        if (!in_array($data['field_code'], $validFieldCodes)) {
            return $this->errorResponse($response, 'field_code invalide');
        }

        try {
            $imported = ClassificationFieldOption::importBatch($data['field_code'], $data['options']);

            return $this->successResponse($response, [
                'imported' => $imported,
                'total' => count($data['options'])
            ], "$imported options importées");

        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Erreur lors de l\'import: ' . $e->getMessage(), 500);
        }
    }
}
