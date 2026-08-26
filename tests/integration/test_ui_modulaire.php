<?php
/**
 * Oracle du secteur interface — structure graphique modulaire (2026-08-25).
 *
 * Olivier : « une structure graphique modulaire, par templates, puis la
 * segmentation visuelle / templates / plugin (donc link ktime) ».
 *
 * Ce que le lot pose et ce que cette sonde prouve PAR EFFET (rendu réel,
 * jamais hasMethod) :
 *
 *  1. MOTEUR CENTRAL — KDocs\Core\View::render() rend un gabarit + layout
 *     (la page pilote /admin/consume, avec ses vraies dépendances).
 *  2. COMPOSANTS — View::component() rend les briques du design system
 *     (nav_item avec badge/compteur, empty_state).
 *  3. SEGMENTATION PAR PLUGIN — la zone admin.sidebar.navigation rendue dans
 *     le VRAI partial sidebar_admin.php contient l'entrée K-Time (app
 *     activée) et NE CONTIENT PAS l'entrée du portail (app déclarée mais
 *     éteinte : PORTAL_APP_ENABLED absent du .env) — un module éteint
 *     disparaît de l'interface tout seul, c'est le cœur de la demande.
 *
 * Usage: php tests/integration/test_ui_modulaire.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use KDocs\Core\Config;
use KDocs\Core\View;

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - STRUCTURE GRAPHIQUE MODULAIRE (interface)         |\n";
echo "+==============================================================+\n\n";

$passed = 0;
$failed = 0;

function test(string $name, bool $ok, string $detail = ''): bool
{
    global $passed, $failed;
    echo $ok ? "✓ $name" : "✗ $name";
    $ok ? $passed++ : $failed++;
    if ($detail !== '') {
        echo " - $detail";
    }
    echo "\n";
    return $ok;
}

// ---------------------------------------------------------------------------
// 1. COMPOSANTS — les briques du design system.
// ---------------------------------------------------------------------------
echo "--- 1. COMPOSANTS (View::component) ---\n\n";

$nav = View::component('ui/nav_item', [
    'href' => '/kdocs/time', 'label' => 'K-Time', 'icon' => 'M12 8v4l3 3',
    'active' => true, 'badge' => '3',
]);
test('nav_item rend un item actif avec badge d\'alerte', str_contains($nav, 'is-active') && str_contains($nav, 'ds-nav-badge--alert') && str_contains($nav, '>3<') && str_contains($nav, 'K-Time'));

$navCount = View::component('ui/nav_item', ['href' => '/x', 'label' => 'X', 'icon' => 'M1 1', 'count' => 12]);
test('nav_item rend le compteur discret quand pas de badge', str_contains($navCount, 'ds-nav-count') && str_contains($navCount, '>12<'));

$empty = View::component('ui/empty_state', ['title' => 'Rien à classer', 'description' => 'La file est vide.']);
test('empty_state rend titre + description normalisés', str_contains($empty, 'Rien à classer') && str_contains($empty, 'La file est vide.') && str_contains($empty, 'role="status"'));

// ---------------------------------------------------------------------------
// 2. MOTEUR CENTRAL — la page pilote /admin/consume rendue par View::render.
// ---------------------------------------------------------------------------
echo "\n--- 2. MOTEUR CENTRAL (View::render, page pilote consume) ---\n\n";

$classifier = new \KDocs\Services\ClassificationService();
$documents = [];
$user = ['id' => 1, 'role' => 'admin', 'is_admin' => 1, 'first_name' => 'Probe', 'last_name' => 'UI', 'email' => 'p@k.local'];
$pageTitle = 'Validation des Documents';

$html = View::render('admin/consume.php', get_defined_vars(), 'layouts/main.php');

test(
    'La page pilote rend par le moteur central : gabarit ET layout dans le même HTML',
    str_contains($html, 'Validation des Documents') && str_contains($html, '<body'),
    'octets=' . strlen($html)
);
test(
    'Le layout embarque bien le contenu rendu (imbrication réelle)',
    substr_count($html, '<body') === 1 && str_contains($html, 'Validation des Documents'),
);

// ---------------------------------------------------------------------------
// 3. SEGMENTATION PAR PLUGIN — la zone navigation du shell.
// ---------------------------------------------------------------------------
echo "\n--- 3. SEGMENTATION PAR PLUGIN (zone admin.sidebar.navigation) ---\n\n";

test(
    "La zone est alimentée (slotHasContent) — K-Time activé déclare un fragment",
    View::slotHasContent('admin.sidebar.navigation'),
);

$slotHtml = View::pluginSlot('admin.sidebar.navigation', ['currentRoute' => '/kdocs/admin']);
test(
    "Le fragment K-Time rend l'entrée de navigation (lien /time, composant nav_item)",
    str_contains($slotHtml, '/time') && str_contains($slotHtml, 'ds-nav-item') && str_contains($slotHtml, 'K-Time'),
);

test(
    'Le fragment du PORTAIL (app déclarée mais ÉTEINTE) ne rend PAS — un module éteint disparaît de l\'interface',
    !str_contains($slotHtml, 'Portail clients') && !str_contains($slotHtml, '/portal'),
    'PORTAL_APP_ENABLED=' . var_export(env('PORTAL_APP_ENABLED', false), true) . ' (absent du .env = éteint)'
);

// La preuve finale : le VRAI partial du shell rend la zone intégrée.
$_SERVER['REQUEST_URI'] = Config::basePath() . '/admin';
ob_start();
include __DIR__ . '/../../templates/partials/sidebar_admin.php';
$sidebarHtml = (string) ob_get_clean();

test(
    'Le VRAI partial sidebar admin rend l\'entrée K-Time via le slot (zéro if() dans le shell)',
    str_contains($sidebarHtml, 'href="' . Config::basePath() . '/time"') && str_contains($sidebarHtml, 'K-Time'),
);
test(
    'Le VRAI partial ne rend PAS l\'entrée du portail éteint',
    !str_contains($sidebarHtml, 'Portail clients'),
);

// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 64) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 64) . "\n";

if ($failed > 0) {
    echo "\n\033[31mLa structure modulaire n'est pas prouvee.\033[0m\n";
    exit(1);
}

echo "\n\033[32mStructure modulaire prouvee : moteur central, composants, segmentation par plugin.\033[0m\n";
exit(0);
