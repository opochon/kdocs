<?php
/**
 * Diagnostic OnlyOffice & Thumbnails
 */
require_once __DIR__ . '/vendor/autoload.php';

use KDocs\Core\Config;

echo "<pre style='font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px;'>\n";
echo "=== DIAGNOSTIC ONLYOFFICE & THUMBNAILS ===\n\n";

// 1. Config
$config = Config::load();
echo "1. CONFIGURATION\n";
echo "   - onlyoffice.enabled: " . ($config['onlyoffice']['enabled'] ?? 'non défini') . "\n";
echo "   - onlyoffice.server_url: " . ($config['onlyoffice']['server_url'] ?? 'non défini') . "\n";
echo "   - onlyoffice.callback_url: " . ($config['onlyoffice']['callback_url'] ?? 'NON DÉFINI (utilise app_url)') . "\n";
echo "   - onlyoffice.app_url: " . ($config['onlyoffice']['app_url'] ?? 'non défini') . "\n";

// 2. OnlyOffice Service
echo "\n2. ONLYOFFICE SERVICE\n";
$onlyOfficeService = new \KDocs\Services\OnlyOfficeService();
echo "   - isEnabled(): " . ($onlyOfficeService->isEnabled() ? 'OUI' : 'NON') . "\n";

// Test de connectivité
$serverUrl = $config['onlyoffice']['server_url'] ?? '';
$healthUrl = rtrim($serverUrl, '/') . '/healthcheck';
echo "   - URL health check: $healthUrl\n";

$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$healthResponse = @file_get_contents($healthUrl, false, $ctx);
$healthOk = $healthResponse !== false && strpos($healthResponse, 'true') !== false;
echo "   - Réponse healthcheck: " . ($healthResponse ?: 'AUCUNE RÉPONSE') . "\n";
echo "   - isAvailable(): " . ($onlyOfficeService->isAvailable() ? 'OUI' : 'NON') . "\n";

// 3. LibreOffice pour thumbnails
echo "\n3. LIBREOFFICE (pour miniatures)\n";
$libreOfficePath = $config['tools']['libreoffice'] ?? '';
echo "   - Chemin configuré: $libreOfficePath\n";
echo "   - Fichier existe: " . (file_exists($libreOfficePath) ? 'OUI' : 'NON') . "\n";

$thumbService = new \KDocs\Services\OfficeThumbnailService();
echo "   - OfficeThumbnailService::isAvailable(): " . ($thumbService->isAvailable() ? 'OUI' : 'NON') . "\n";

// 4. Test avec un document docx existant
echo "\n4. TEST DOCUMENT DOCX\n";
$db = \KDocs\Core\Database::getInstance()->getConnection();
$docx = $db->query("SELECT id, filename, original_filename, file_path, thumbnail_path FROM documents WHERE filename LIKE '%.docx' OR original_filename LIKE '%.docx' LIMIT 1")->fetch();
if ($docx) {
    echo "   - Document trouvé: ID " . $docx['id'] . "\n";
    echo "   - Filename: " . ($docx['filename'] ?? $docx['original_filename']) . "\n";
    echo "   - File path: " . $docx['file_path'] . "\n";
    echo "   - Thumbnail path: " . ($docx['thumbnail_path'] ?: 'AUCUN') . "\n";

    // Vérifier si le fichier existe
    $basePath = $config['storage']['documents'] ?? __DIR__ . '/storage/documents';
    $fullPath = $basePath . '/' . $docx['file_path'];
    if (!file_exists($fullPath)) {
        $fullPath = $docx['file_path']; // Essayer chemin absolu
    }
    echo "   - Fichier existe: " . (file_exists($fullPath) ? 'OUI - ' . $fullPath : 'NON') . "\n";
} else {
    echo "   - Aucun document .docx trouvé en base\n";
}

// 5. Vérification des variables pour show.php
echo "\n5. SIMULATION SHOW.PHP\n";
if ($docx) {
    $filename = $docx['filename'] ?? $docx['original_filename'] ?? '';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $officeExtensions = ['docx', 'doc', 'odt', 'rtf', 'xlsx', 'xls', 'ods', 'csv', 'pptx', 'ppt', 'odp'];
    $isOffice = in_array($ext, $officeExtensions);
    $canPreviewOffice = $isOffice && $onlyOfficeService->isAvailable();

    echo "   - Extension: $ext\n";
    echo "   - isOffice: " . ($isOffice ? 'OUI' : 'NON') . "\n";
    echo "   - onlyOfficeService->isAvailable(): " . ($onlyOfficeService->isAvailable() ? 'OUI' : 'NON') . "\n";
    echo "   - canPreviewOffice: " . ($canPreviewOffice ? 'OUI' : 'NON') . "\n";
    echo "   - canEdit (si canPreviewOffice): " . ($canPreviewOffice ? 'OUI (dépend user)' : 'NON (pas de preview)') . "\n";
}

// 6. Recommandations
echo "\n6. RECOMMANDATIONS\n";
if (!$healthOk) {
    echo "   [!] OnlyOffice n'est pas accessible sur $serverUrl\n";
    echo "       -> Vérifiez que le conteneur Docker tourne\n";
    echo "       -> Vérifiez le port 8080\n";
    echo "       -> Essayez: docker start kdocs-onlyoffice\n";
}
if (!file_exists($libreOfficePath)) {
    echo "   [!] LibreOffice non trouvé pour les miniatures\n";
    echo "       -> Vérifiez le chemin dans config.php\n";
    echo "       -> Chemin actuel: $libreOfficePath\n";
}

echo "\n=== FIN DIAGNOSTIC ===\n";
echo "</pre>";
