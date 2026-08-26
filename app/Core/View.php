<?php
declare(strict_types=1);

namespace KDocs\Core;

/**
 * Moteur de rendu central — lot interface-modulaire-slots (2026-08-25).
 *
 * Avant : chaque contrôleur dupliquait sa méthode privée renderTemplate()
 * (extract + include). Dorénavant un seul moteur :
 *
 *   View::render('admin/consume.php', $data, 'layouts/main.php')
 *   View::component('ui/button', ['label' => ..., 'variant' => 'primary'])
 *   View::pluginSlot('admin.sidebar.navigation', ['user' => $user])
 *
 * SEGMENTATION PAR PLUGIN : un slot est une zone nommée du shell. Chaque app
 * satellite déclare dans son config.php :
 *
 *   'ui_slots' => [
 *       'admin.sidebar.navigation' => __DIR__ . '/templates/slots/admin_sidebar.php',
 *   ],
 *
 * View::pluginSlot() rend les fragments de toutes les apps ACTIVÉES
 * (PluginRegistry::isEnabled — la même source de vérité que les routes) :
 * un module éteint disparaît de l'interface tout seul, sans if() éparpillé
 * dans les gabarits. Convention documentée dans docs/PLUGIN-SYSTEM.md.
 */
class View
{
    private const TEMPLATE_ROOT = __DIR__ . '/../../templates/';

    /** Rend un gabarit (chemin relatif à templates/), avec layout optionnel. */
    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::renderFile(self::path($template), $data);
        if ($layout !== null) {
            $content = self::renderFile(self::path($layout), array_merge($data, ['content' => $content]));
        }
        return $content;
    }

    /** Rend un composant (templates/components/<name>.php) avec ses props. */
    public static function component(string $name, array $props = []): string
    {
        return self::renderFile(self::path('components/' . $name . '.php'), $props);
    }

    /**
     * Rend une zone du shell alimentée par les apps activées.
     * Les fragments reçoivent $slot (nom de la zone) et le $context donné.
     */
    public static function pluginSlot(string $slot, array $context = []): string
    {
        $html = '';
        foreach (self::slotFragments($slot) as $appName => $fragment) {
            $html .= self::renderFile($fragment, array_merge($context, [
                'slot'     => $slot,
                'app_name' => $appName,
            ]));
        }
        return $html;
    }

    /** Une zone est-elle alimentée par au moins une app activée ? */
    public static function slotHasContent(string $slot): bool
    {
        return self::slotFragments($slot) !== [];
    }

    /**
     * @return array<string, string> appName => chemin du fragment, apps activées uniquement
     */
    private static function slotFragments(string $slot): array
    {
        $fragments = [];
        foreach (glob(dirname(__DIR__, 2) . '/apps/*/config.php') ?: [] as $configFile) {
            $appName = basename(dirname($configFile));
            if (!PluginRegistry::isEnabled($appName)) {
                continue;
            }
            $config = require $configFile;
            $fragment = $config['ui_slots'][$slot] ?? null;
            if (is_string($fragment) && is_file($fragment)) {
                $fragments[$appName] = $fragment;
            }
        }
        return $fragments;
    }

    private static function path(string $template): string
    {
        return self::TEMPLATE_ROOT . '/' . ltrim($template, '/');
    }

    private static function renderFile(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        include $__file;
        return (string) ob_get_clean();
    }
}
