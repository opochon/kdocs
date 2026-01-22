<?php
/**
 * K-Docs - Contrôleur des paramètres système
 */

namespace KDocs\Controllers;

use KDocs\Models\Setting;
use KDocs\Core\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController
{
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
     * Affiche la page des paramètres
     */
    public function index(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('SettingsController::index', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        
        // Récupérer les paramètres par catégorie
        $storageSettings = Setting::getAll('storage');
        $ocrSettings = Setting::getAll('ocr');
        $aiSettings = Setting::getAll('ai');
        
        // Valeurs par défaut depuis la config si pas définies en DB
        $config = Config::load();
        
        $content = $this->renderTemplate(__DIR__ . '/../../templates/admin/settings.php', [
            'user' => $user,
            'storageSettings' => $storageSettings,
            'ocrSettings' => $ocrSettings,
            'aiSettings' => $aiSettings,
            'defaultConfig' => $config,
        ]);
        
        $html = $this->renderTemplate(__DIR__ . '/../../templates/layouts/main.php', [
            'title' => 'Paramètres - K-Docs',
            'content' => $content,
            'user' => $user,
            'pageTitle' => 'Paramètres'
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Sauvegarde les paramètres
     */
    public function save(Request $request, Response $response): Response
    {
        // #region agent log
        \KDocs\Core\DebugLogger::log('SettingsController::save', 'Controller entry', [
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod()
        ], 'A');
        // #endregion
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $basePath = Config::basePath();
        
        $errors = [];
        $success = [];
        
        // Sauvegarder les paramètres storage
        if (isset($data['storage'])) {
            foreach ($data['storage'] as $key => $value) {
                $fullKey = 'storage.' . $key;
                if (Setting::set($fullKey, $value, 'string', $user['id'])) {
                    $success[] = "Paramètre $fullKey sauvegardé";
                } else {
                    $errors[] = "Erreur lors de la sauvegarde de $fullKey";
                }
            }
        }
        
        // Sauvegarder les paramètres OCR
        if (isset($data['ocr'])) {
            foreach ($data['ocr'] as $key => $value) {
                $fullKey = 'ocr.' . $key;
                if (Setting::set($fullKey, $value, 'string', $user['id'])) {
                    $success[] = "Paramètre $fullKey sauvegardé";
                } else {
                    $errors[] = "Erreur lors de la sauvegarde de $fullKey";
                }
            }
        }
        
        // Sauvegarder les paramètres AI
        if (isset($data['ai'])) {
            foreach ($data['ai'] as $key => $value) {
                $fullKey = 'ai.' . $key;
                // Masquer la clé API dans les logs
                $displayKey = ($key === 'claude_api_key' && !empty($value)) ? 'ai.claude_api_key (masquée)' : $fullKey;
                if (Setting::set($fullKey, $value, 'string', $user['id'])) {
                    $success[] = "Paramètre $displayKey sauvegardé";
                } else {
                    $errors[] = "Erreur lors de la sauvegarde de $displayKey";
                }
            }
        }
        
        // Sauvegarder les paramètres KDrive
        if (isset($data['kdrive'])) {
            foreach ($data['kdrive'] as $key => $value) {
                $fullKey = 'kdrive.' . $key;
                // Masquer le mot de passe dans les logs
                $displayKey = ($key === 'password' && !empty($value)) ? 'kdrive.password (masqué)' : $fullKey;
                if (Setting::set($fullKey, $value, 'string', $user['id'])) {
                    $success[] = "Paramètre $displayKey sauvegardé";
                } else {
                    $errors[] = "Erreur lors de la sauvegarde de $displayKey";
                }
            }
        }
        
        // Réinitialiser le cache de configuration pour recharger les nouveaux paramètres
        Config::reset();
        
        // Rediriger avec message
        $message = !empty($success) ? 'success=' . urlencode(implode(', ', $success)) : '';
        if (!empty($errors)) {
            $message .= (!empty($message) ? '&' : '') . 'error=' . urlencode(implode(', ', $errors));
        }
        
        return $response
            ->withHeader('Location', $basePath . '/admin/settings?' . $message)
            ->withStatus(302);
    }
}
