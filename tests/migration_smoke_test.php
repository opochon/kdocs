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

echo "\nLot B0 — spec produit K-Docs vs REDX\n";
$productDocs = [
    'docs/superpowers/specs/2026-06-18-kdocs-redx-simplification-design.md',
    'docs/ORACLES-KDOCS-PRODUCT.md',
    'docs/ROADMAP-KDOCS-PRODUCT.md',
    'docs/DETTE-UI-ORPHELINS.md',
];
foreach ($productDocs as $rel) {
    assert_true($rel, is_file(KDOCS_ROOT . '/' . $rel));
}
if (is_file(KDOCS_ROOT . '/docs/ROADMAP-KDOCS-PRODUCT.md')) {
    $roadmap = (string) file_get_contents(KDOCS_ROOT . '/docs/ROADMAP-KDOCS-PRODUCT.md');
    assert_true('ROADMAP phase B0', str_contains($roadmap, '## Phase B0'));
    assert_true('ROADMAP phase B1', str_contains($roadmap, '## Phase B1'));
    assert_true('ROADMAP phase A Factures', str_contains($roadmap, '## Phase A'));
}
if (is_file(KDOCS_ROOT . '/docs/ORACLES-KDOCS-PRODUCT.md')) {
    $oracle = (string) file_get_contents(KDOCS_ROOT . '/docs/ORACLES-KDOCS-PRODUCT.md');
    assert_true('Oracle PluginRegistry', str_contains($oracle, 'PluginRegistry'));
    assert_true('Oracle workers-only ingest', str_contains($oracle, 'workers'));
}
assert_true('templates/documents/index_old.php absent', !is_file(KDOCS_ROOT . '/templates/documents/index_old.php'));
assert_true('templates/documents/show_old.php absent', !is_file(KDOCS_ROOT . '/templates/documents/show_old.php'));

echo "\nLot B0.8 — sidebar user vs admin\n";
assert_true('partials/sidebar_user.php', is_file(KDOCS_ROOT . '/templates/partials/sidebar_user.php'));
assert_true('partials/sidebar_admin.php', is_file(KDOCS_ROOT . '/templates/partials/sidebar_admin.php'));
if (is_file(KDOCS_ROOT . '/templates/partials/sidebar_user.php')) {
    $sidebarUser = (string) file_get_contents(KDOCS_ROOT . '/templates/partials/sidebar_user.php');
    foreach (['Bibliothèque', 'Recherche', 'À traiter', 'Importer', 'Administration'] as $label) {
        assert_true("sidebar user contient « {$label} »", str_contains($sidebarUser, $label));
    }
    assert_true('sidebar user sans lien admin/tags', !str_contains($sidebarUser, '/admin/tags'));
}
if ($vendorOk) {
    assert_true('Helper isAdminChromeRoute()', function_exists('isAdminChromeRoute'));
}

echo "\nLot B0.9 — ingest workers-only (index.php)\n";
if (is_file(KDOCS_ROOT . '/index.php')) {
    $indexPhp = (string) file_get_contents(KDOCS_ROOT . '/index.php');
    assert_true('index.php sans processPendingDocuments sync', !str_contains($indexPhp, 'processPendingDocuments'));
    assert_true('index.php sans CrawlerAutoTrigger sync', !str_contains($indexPhp, 'CrawlerAutoTrigger'));
}

echo "\nLot B0.10 — UI Qdrant conditionnelle\n";
if ($vendorOk) {
    assert_true('Helper isQdrantUiEnabled()', function_exists('isQdrantUiEnabled'));
}
if (is_file(KDOCS_ROOT . '/templates/admin/settings.php')) {
    $settingsTpl = (string) file_get_contents(KDOCS_ROOT . '/templates/admin/settings.php');
    assert_true('settings.php gate isQdrantUiEnabled', str_contains($settingsTpl, 'isQdrantUiEnabled()'));
}

echo "\nLot B0.11 — template show_paperless retiré\n";
assert_true('templates/documents/show_paperless.php absent', !is_file(KDOCS_ROOT . '/templates/documents/show_paperless.php'));

echo "\nLot B0.12 — gel AIClassifierService\n";
assert_true('docs/DEPRECATED-AI-CLASSIFIER.md', is_file(KDOCS_ROOT . '/docs/DEPRECATED-AI-CLASSIFIER.md'));
if (is_file(KDOCS_ROOT . '/app/Services/AIClassifierService.php')) {
    $aiCls = (string) file_get_contents(KDOCS_ROOT . '/app/Services/AIClassifierService.php');
    assert_true('AIClassifierService marqué @deprecated', str_contains($aiCls, '@deprecated'));
    assert_true('AIClassifierService pointe UnifiedClassifier', str_contains($aiCls, 'UnifiedClassifier'));
}

echo "\nModules chantier GEDv1\n";
assert_true('ConnectorInterface', is_file(KDOCS_ROOT . '/app/Connectors/ConnectorInterface.php'));
assert_true('PluginRegistry', is_file(KDOCS_ROOT . '/app/Core/PluginRegistry.php'));
assert_true('.env.example', is_file(KDOCS_ROOT . '/.env.example'));
assert_true('apps/invoices/Controllers/InvoiceController.php', is_file(KDOCS_ROOT . '/apps/invoices/Controllers/InvoiceController.php'));
assert_true('apps/invoices/Controllers/MatchingController.php', is_file(KDOCS_ROOT . '/apps/invoices/Controllers/MatchingController.php'));
assert_true('tests Unit MatchingServiceInvoiceTest', is_file(KDOCS_ROOT . '/tests/Unit/Services/MatchingServiceInvoiceTest.php'));
assert_true('tests Unit WinBizConnectorTest', is_file(KDOCS_ROOT . '/tests/Unit/Connectors/WinBizConnectorTest.php'));

if ($vendorOk) {
    assert_true('Helper env() disponible', function_exists('env'));
    assert_true('Classe PluginRegistry chargeable', class_exists('KDocs\\Core\\PluginRegistry'));
}

echo "\nLot IA — sidecar ClearMyDocs\n";
assert_true('ClearMyDocsSidecarClient.php', is_file(KDOCS_ROOT . '/app/Services/ClearMyDocsSidecarClient.php'));
assert_true('PdfSplitService.php', is_file(KDOCS_ROOT . '/app/Services/PdfSplit/PdfSplitService.php'));
assert_true('tests Unit ClearMyDocsSidecarClientTest', is_file(KDOCS_ROOT . '/tests/Unit/Services/ClearMyDocsSidecarClientTest.php'));
if ($vendorOk) {
    assert_true('Classe ClearMyDocsSidecarClient chargeable', class_exists('KDocs\\Services\\ClearMyDocsSidecarClient'));
    assert_true('Classe PdfSplitService chargeable', class_exists('KDocs\\Services\\PdfSplit\\PdfSplitService'));
}

echo "\nLot IA — sync taxonomie HTMLEDITOR\n";
assert_true('HtmleditorTaxonomyAdapter.php', is_file(KDOCS_ROOT . '/app/Adapters/HtmleditorTaxonomyAdapter.php'));
assert_true('TaxonomySyncService.php', is_file(KDOCS_ROOT . '/app/Services/Classification/TaxonomySyncService.php'));
assert_true('ClassificationTaxonomyApiController.php', is_file(KDOCS_ROOT . '/app/Controllers/Api/ClassificationTaxonomyApiController.php'));
assert_true('tests Unit HtmleditorTaxonomyAdapterTest', is_file(KDOCS_ROOT . '/tests/Unit/Adapters/HtmleditorTaxonomyAdapterTest.php'));
if ($vendorOk) {
    assert_true('Classe TaxonomySyncService chargeable', class_exists('KDocs\\Services\\Classification\\TaxonomySyncService'));
    assert_true('Classe ClassificationTaxonomyApiController chargeable', class_exists('KDocs\\Controllers\\Api\\ClassificationTaxonomyApiController'));
}

echo "\nLot IA — UnifiedClassifier ingest\n";
assert_true('ClassificationResult.php', is_file(KDOCS_ROOT . '/app/DTO/ClassificationResult.php'));
assert_true('GedNativeClassifierAdapter.php', is_file(KDOCS_ROOT . '/app/Adapters/GedNativeClassifierAdapter.php'));
assert_true('InfomaniakClassifierAdapter.php', is_file(KDOCS_ROOT . '/app/Adapters/InfomaniakClassifierAdapter.php'));
assert_true('IngestClassificationService.php', is_file(KDOCS_ROOT . '/app/Services/Classification/IngestClassificationService.php'));
assert_true('ClassifyDocumentJob.php', is_file(KDOCS_ROOT . '/app/Jobs/ClassifyDocumentJob.php'));
assert_true('tests Unit UnifiedClassifierTest', is_file(KDOCS_ROOT . '/tests/Unit/Services/Classifiers/UnifiedClassifierTest.php'));
assert_true('tests Unit IngestClassificationServiceTest', is_file(KDOCS_ROOT . '/tests/Unit/Services/Classification/IngestClassificationServiceTest.php'));
if ($vendorOk) {
    assert_true('Classe UnifiedClassifier chargeable', class_exists('KDocs\\Services\\Classifiers\\UnifiedClassifier'));
    assert_true('Classe IngestClassificationService chargeable', class_exists('KDocs\\Services\\Classification\\IngestClassificationService'));
    assert_true('Classe ClassifyDocumentJob chargeable', class_exists('KDocs\\Jobs\\ClassifyDocumentJob'));
}

echo "\nLot IA — ingest dual-mode CMD v3\n";
$dualModeFiles = [
    'app/Services/Ingest/IngestEngineRouter.php',
    'app/Services/Ingest/ClearMyDocsIngestEngine.php',
    'app/Services/Ingest/GedNativeIngestEngine.php',
    'app/Services/Ingest/CmdResultMapper.php',
    'app/Services/Ingest/ClearMyDocsCapabilityProbe.php',
    'docs/INGEST-DUAL-MODE.md',
    'tools/start-cmd-sidecar.bat',
    'tests/Unit/Services/Ingest/IngestEngineRouterTest.php',
    'tests/Unit/Services/Ingest/ClearMyDocsCapabilityProbeTest.php',
    'tests/Unit/Services/Ingest/CmdResultMapperTest.php',
];
foreach ($dualModeFiles as $rel) {
    assert_true($rel, is_file(KDOCS_ROOT . '/' . $rel));
}
if ($vendorOk) {
    assert_true('Classe IngestEngineRouter chargeable', class_exists('KDocs\\Services\\Ingest\\IngestEngineRouter'));
    assert_true('Classe ClearMyDocsCapabilityProbe chargeable', class_exists('KDocs\\Services\\Ingest\\ClearMyDocsCapabilityProbe'));
    assert_true('Classe CmdResultMapper chargeable', class_exists('KDocs\\Services\\Ingest\\CmdResultMapper'));
    assert_true('.env.example INGEST_ENGINE', str_contains((string) file_get_contents(KDOCS_ROOT . '/.env.example'), 'INGEST_ENGINE'));
    assert_true('.env.example CLEARMYDOCS_MIN_VERSION', str_contains((string) file_get_contents(KDOCS_ROOT . '/.env.example'), 'CLEARMYDOCS_MIN_VERSION'));
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "Résultat : $passed passés, $failed échoués\n";
exit($failed > 0 ? 1 : 0);
