<?php
/**
 * Contrôleur pour gérer les champs de classification paramétrables
 */

namespace KDocs\Controllers;

use KDocs\Models\ClassificationField;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ClassificationFieldsController
{
    public function index(Request $request, Response $response): Response
    {
        $fields = ClassificationField::all();
        
        ob_start();
        include __DIR__ . '/../../templates/admin/classification_fields.php';
        $content = ob_get_clean();
        
        ob_start();
        $user = $request->getAttribute('user');
        $pageTitle = 'Champs de Classification';
        include __DIR__ . '/../../templates/layouts/main.php';
        $html = ob_get_clean();
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function showForm(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;
        $field = $id ? ClassificationField::find((int)$id) : null;
        
        ob_start();
        include __DIR__ . '/../../templates/admin/classification_field_form.php';
        $content = ob_get_clean();
        
        ob_start();
        $user = $request->getAttribute('user');
        $pageTitle = ($field ? 'Modifier' : 'Créer') . ' champ de classification';
        include __DIR__ . '/../../templates/layouts/main.php';
        $html = ob_get_clean();
        
        $response->getBody()->write($html);
        return $response;
    }
    
    public function save(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        
        $data['is_active'] = isset($data['is_active']);
        $data['use_for_storage_path'] = isset($data['use_for_storage_path']);
        $data['use_for_tag'] = isset($data['use_for_tag']);
        $data['use_ai'] = isset($data['use_ai']);
        $data['storage_path_position'] = !empty($data['storage_path_position']) ? (int)$data['storage_path_position'] : null;
        $data['ai_prompt'] = !empty($data['ai_prompt']) ? trim($data['ai_prompt']) : null;
        
        // Si le champ existe et est obligatoire, préserver is_required
        if ($id) {
            $existing = ClassificationField::find((int)$id);
            if ($existing && !empty($existing['is_required'])) {
                $data['is_required'] = true;
            } else {
                $data['is_required'] = isset($data['is_required']);
            }
        } else {
            $data['is_required'] = isset($data['is_required']);
        }
        
        try {
            if ($id) {
                ClassificationField::update((int)$id, $data);
            } else {
                ClassificationField::create($data);
            }
            
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Champ enregistré avec succès'];
            return $response->withHeader('Location', url('/admin/classification-fields'))->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Erreur: ' . $e->getMessage()];
            return $response->withHeader('Location', url('/admin/classification-fields'))->withStatus(302);
        }
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $field = ClassificationField::find((int)$args['id']);
            
            if (!$field) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Champ non trouvé'];
                return $response->withHeader('Location', url('/admin/classification-fields'))->withStatus(302);
            }
            
            // Vérifier si obligatoire
            if (!empty($field['is_required'])) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Ce champ est obligatoire et ne peut pas être supprimé'];
                return $response->withHeader('Location', url('/admin/classification-fields'))->withStatus(302);
            }
            
            // Vérifier l'utilisation
            $usage = ClassificationField::isUsed((int)$args['id']);
            if ($usage['used']) {
                $_SESSION['flash'] = [
                    'type' => 'error', 
                    'message' => "Impossible de supprimer ce champ : il est utilisé dans {$usage['count']} document(s). {$usage['message']}"
                ];
                return $response->withHeader('Location', url('/admin/classification-fields'))->withStatus(302);
            }
            
            ClassificationField::delete((int)$args['id']);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Champ supprimé'];
        } catch (\Exception $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
        }
        
        return $response->withHeader('Location', url('/admin/classification-fields'))->withStatus(302);
    }
}
