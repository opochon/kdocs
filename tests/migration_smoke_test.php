<?php
/**
 * GEDv1 — Smoke tests post-migration (sans serveur HTTP ni BDD)
 *
 * Vérifie l'intégrité structurelle du dépôt copié vers F:\DATA\DEVELOPPEMENT\GEDv1.
 * Usage : php tests/migration_smoke_test.php
 */

define('KDOCS_ROOT', dirname(__DIR__));
define('GREEN', "\033[32m");
define('RED', "\033[31m");
define('RESET', "\033[0m");

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

$passed = 0;
$failed = 0;

function assert_true(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo GREEN . "  ✓ " . RESET . $name . "\n";
    } else {
        $failed++;
        $msg = $detail !== '' ? " — $detail" : '';
        echo RED . "  ✗ " . RESET . $name . $msg . "\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "  GEDv1 — MIGRATION SMOKE TESTS (offline)\n";
echo str_repeat('=', 60) . "\n\n";

echo "Structure critique\n";
$critical = [
    'index.php',
    'composer.json',
    'phpunit.xml',
    'app/Core/App.php',
    'app/Core/Config.php',
    'config/config.php',
    'database/schema_consolidated.sql',
    'connectors/winbiz/WinBizConnector.php',
    'tests/smoke_test.php',
    'tests/run_all.php',
];
foreach ($critical as $rel) {
    assert_true("Fichier $rel", is_file(KDOCS_ROOT . '/' . $rel));
}

echo "\nAutoload Composer\n";
$vendorOk = is_file(KDOCS_ROOT . '/vendor/autoload.php');
assert_true('vendor/autoload.php présent', $vendorOk, 'lancer: composer install');

if ($vendorOk) {
    require KDOCS_ROOT . '/vendor/autoload.php';
    require_once KDOCS_ROOT . '/app/helpers.php';
    assert_true('Classe KDocs\\Core\\App chargeable', class_exists('KDocs\\Core\\App'));
    assert_true('Fichier WinBizConnector.php', is_file(KDOCS_ROOT . '/connectors/winbiz/WinBizConnector.php'));
}

echo "\nConfiguration\n";
$configPath = KDOCS_ROOT . '/config/config.php';
if (is_file($configPath)) {
    $config = require $configPath;
    assert_true('config.php retourne un tableau', is_array($config));
    assert_true('Section database définie', isset($config['database']['name']));
    assert_true('Section storage définie', isset($config['storage']['base_path']));
}

echo "\nConnecteur WinBiz\n";
$wbConfig = KDOCS_ROOT . '/connectors/winbiz/config.php';
if (is_file($wbConfig)) {
    $wb = require $wbConfig;
    assert_true('WinBiz config chargeable', is_array($wb));
    assert_true('WinBiz tables mapping', isset($wb['tables']['articles']));
}

echo "\nSchéma SQL\n";
$schema = KDOCS_ROOT . '/database/schema_consolidated.sql';
if (is_file($schema)) {
    $sql = file_get_contents($schema);
    foreach (['users', 'documents', 'tags', 'workflow_templates'] as $table) {
        assert_true("Table SQL `$table` référencée", str_contains($sql, "`$table`"));
    }
}

echo "\nApps satellites\n";
foreach (['timetrack', 'invoices', 'mail'] as $app) {
    assert_true("apps/$app/routes.php", is_file(KDOCS_ROOT . "/apps/$app/routes.php"));
}

echo "\nDocumentation migration\n";
foreach (['docs/ORACLES.md', 'docs/ARCHITECTURE.md', 'SESSION-STATUS.md'] as $doc) {
    assert_true($doc, is_file(KDOCS_ROOT . '/' . $doc));
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "Résultat : $passed passés, $failed échoués\n";
exit($failed > 0 ? 1 : 0);
