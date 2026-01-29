<?php
/**
 * K-Docs - Attribution Rules Admin Controller
 * Gestion des règles d'attribution via l'interface web
 */

namespace KDocs\Controllers\Admin;

use KDocs\Core\Database;
use KDocs\Models\AttributionRule;
use KDocs\Models\ClassificationFieldOption;
use KDocs\Services\Attribution\AttributionRuleEngine;
use KDocs\Services\Attribution\RuleConditionEvaluator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AttributionRulesController
{
    /**
     * Affiche la liste des règles
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $rules = AttributionRule::all();

        // Statistiques
        $db = Database::getInstance();
        $stats = [
            'total' => count($rules),
            'active' => count(array_filter($rules, fn($r) => $r['is_active'])),
            'executions_today' => (int)$db->query("
                SELECT COUNT(*) FROM attribution_rule_logs
                WHERE DATE(created_at) = CURDATE()
            ")->fetchColumn(),
            'matches_today' => (int)$db->query("
                SELECT COUNT(*) FROM attribution_rule_logs
                WHERE DATE(created_at) = CURDATE() AND matched = 1
            ")->fetchColumn()
        ];

        $content = $this->renderTemplate(__DIR__ . '/../../../templates/admin/attribution-rules/index.php', [
            'rules' => $rules,
            'stats' => $stats
        ]);

        return $this->renderLayout($response, $content, [
            'title' => 'Règles d\'attribution',
            'user' => $user,
            'pageTitle' => 'Règles d\'attribution'
        ]);
    }

    /**
     * Affiche l'éditeur de règle (création ou modification)
     */
    public function editor(Request $request, Response $response, array $args = []): Response
    {
        $user = $request->getAttribute('user');
        $id = isset($args['id']) ? (int)$args['id'] : null;

        $rule = null;
        if ($id) {
            $rule = AttributionRule::find($id);
            if (!$rule) {
                // Redirect to list with error
                return $response->withHeader('Location', '/kdocs/admin/attribution-rules')->withStatus(302);
            }
        }

        // Données pour le formulaire
        $db = Database::getInstance();

        // Correspondants
        $correspondents = $db->query("SELECT id, name FROM correspondents ORDER BY name")->fetchAll();

        // Types de documents
        $documentTypes = $db->query("SELECT id, label FROM document_types ORDER BY label")->fetchAll();

        // Tags
        $tags = $db->query("SELECT id, name FROM tags ORDER BY name")->fetchAll();

        // Options de champs
        $fieldOptions = ClassificationFieldOption::getAllGrouped();

        // Dossiers logiques
        $folders = $db->query("SELECT id, name, path FROM logical_folders ORDER BY path")->fetchAll();

        // Types de champs et opérateurs
        $fieldTypes = AttributionRuleEngine::getFieldTypes();
        $actionTypes = AttributionRuleEngine::getActionTypes();

        foreach ($fieldTypes as $type => &$config) {
            $config['operators'] = RuleConditionEvaluator::getOperatorsForFieldType($type);
        }

        $content = $this->renderTemplate(__DIR__ . '/../../../templates/admin/attribution-rules/editor.php', [
            'rule' => $rule,
            'correspondents' => $correspondents,
            'documentTypes' => $documentTypes,
            'tags' => $tags,
            'fieldOptions' => $fieldOptions,
            'folders' => $folders,
            'fieldTypes' => $fieldTypes,
            'actionTypes' => $actionTypes
        ]);

        return $this->renderLayout($response, $content, [
            'title' => $rule ? 'Modifier la règle' : 'Nouvelle règle',
            'user' => $user,
            'pageTitle' => $rule ? 'Modifier la règle' : 'Nouvelle règle',
            'fullHeight' => true
        ]);
    }

    /**
     * Affiche les logs d'une règle
     */
    public function logs(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int)$args['id'];

        $rule = AttributionRule::find($id);
        if (!$rule) {
            return $response->withHeader('Location', '/kdocs/admin/attribution-rules')->withStatus(302);
        }

        $logs = AttributionRule::getLogs($id, 100);

        $content = $this->renderTemplate(__DIR__ . '/../../../templates/admin/attribution-rules/logs.php', [
            'rule' => $rule,
            'logs' => $logs
        ]);

        return $this->renderLayout($response, $content, [
            'title' => 'Logs - ' . $rule['name'],
            'user' => $user,
            'pageTitle' => 'Logs de règle'
        ]);
    }

    /**
     * Helper pour rendre un template
     */
    private function renderTemplate(string $templatePath, array $data = []): string
    {
        extract($data);
        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Helper pour rendre avec le layout
     */
    private function renderLayout(Response $response, string $content, array $data = []): Response
    {
        $data['content'] = $content;
        $html = $this->renderTemplate(__DIR__ . '/../../../templates/layouts/main.php', $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }
}
