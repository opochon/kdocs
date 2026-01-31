<?php
/**
 * K-Docs Smoke Test - 32 vérifications
 * Tests rapides de base pour valider l'installation
 * 
 * Usage: php tests/smoke_test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$passed = 0;
$failed = 0;
$warnings = 0;
$results = [];

function test($name, $condition, $detail = '', $warning = false) {
    global $passed, $failed, $warnings, $results;
    if ($condition) {
        echo "\033[32m[OK]\033[0m $name";
        $passed++;
        $results[$name] = 'OK';
    } elseif ($warning) {
        echo "\033[33m[!!]\033[0m $name";
        $warnings++;
        $results[$name] = 'WARN';
    } else {
        echo "\033[31m[X]\033[0m $name";
        $failed++;
        $results[$name] = 'FAIL';
    }
    if ($detail) echo " - $detail";
    echo "\n";
    return $condition;
}

echo "\n========================================\n";
echo "K-DOCS SMOKE TEST (35 checks)\n";
echo "========================================\n\n";

// ==========================================
// 1. Configuration
// ==========================================
echo "--- CONFIGURATION ---\n";

$configPath = __DIR__ . '/../config/config.php';
test('1. config.php', file_exists($configPath), $configPath);

$config = null;
try {
    $config = require $configPath;
    test('2. Config loadable', is_array($config));
} catch (Exception $e) {
    test('2. Config loadable', false, $e->getMessage());
}

// ==========================================
// 2. Database
// ==========================================
echo "\n--- BASE DE DONNÉES ---\n";

$db = null;
try {
    $db = \KDocs\Core\Database::getInstance();
    test('3. Connexion DB', $db !== null);
} catch (Exception $e) {
    test('3. Connexion DB', false, $e->getMessage());
}

if ($db) {
    try {
        $count = $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        test('4. Table users', $count > 0, "$count utilisateur(s)");
    } catch (Exception $e) {
        test('4. Table users', false, $e->getMessage());
    }

    try {
        $count = $db->query('SELECT COUNT(*) FROM documents')->fetchColumn();
        test('5. Table documents', true, "$count document(s)");
    } catch (Exception $e) {
        test('5. Table documents', false, $e->getMessage());
    }
    
    // Tables embeddings
    try {
        $exists = $db->query("SHOW TABLES LIKE 'embedding_logs'")->fetch();
        test('6. Tables embeddings', $exists !== false);
    } catch (Exception $e) {
        test('6. Tables embeddings', false, $e->getMessage());
    }
    
    // Tables snapshots
    try {
        $exists = $db->query("SHOW TABLES LIKE 'snapshots'")->fetch();
        test('7. Tables snapshots', $exists !== false);
    } catch (Exception $e) {
        test('7. Tables snapshots', false, $e->getMessage());
    }
}

// ==========================================
// 3. Storage
// ==========================================
echo "\n--- STOCKAGE ---\n";

$dirs = [
    '8. storage/' => __DIR__ . '/../storage',
    '9. storage/documents/' => __DIR__ . '/../storage/documents',
    '10. storage/consume/' => __DIR__ . '/../storage/consume',
    '11. storage/thumbnails/' => __DIR__ . '/../storage/thumbnails',
    '12. storage/temp/' => __DIR__ . '/../storage/temp',
];

foreach ($dirs as $name => $path) {
    test($name, is_dir($path), basename($path));
}

$testFile = __DIR__ . '/../storage/temp/.smoke_test';
$writable = @file_put_contents($testFile, 'test') !== false;
if ($writable) @unlink($testFile);
test('13. storage/ writable', $writable);

// ==========================================
// 4. External Tools
// ==========================================
echo "\n--- OUTILS EXTERNES ---\n";

$tesseractPath = PHP_OS_FAMILY === 'Windows'
    ? 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'
    : '/usr/bin/tesseract';
$tesseractOk = file_exists($tesseractPath) || shell_exec('tesseract --version 2>&1') !== null;
test('14. Tesseract OCR', $tesseractOk);

$langs = @shell_exec('"' . $tesseractPath . '" --list-langs 2>&1');
$fraOk = $langs && strpos($langs, 'fra') !== false;
test('15. Tesseract fra', $fraOk, $fraOk ? 'installé' : 'manquant', true);

$gsPath = PHP_OS_FAMILY === 'Windows'
    ? glob('C:\\Program Files\\gs\\*\\bin\\gswin64c.exe')[0] ?? null
    : '/usr/bin/gs';
$gsOk = $gsPath && file_exists($gsPath);
test('16. Ghostscript', $gsOk, $gsOk ? basename(dirname(dirname($gsPath))) : 'manquant');

$pdftotextOk = shell_exec('pdftotext -v 2>&1') !== null ||
    file_exists('C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe') ||
    file_exists('C:\\Tools\\poppler\\Library\\bin\\pdftotext.exe');
test('17. pdftotext', $pdftotextOk, '', true);

$loPath = PHP_OS_FAMILY === 'Windows'
    ? 'C:\\Program Files\\LibreOffice\\program\\soffice.exe'
    : '/usr/bin/libreoffice';
test('18. LibreOffice', file_exists($loPath), '', true);

// ==========================================
// 5. Services PHP
// ==========================================
echo "\n--- SERVICES PHP ---\n";

try {
    $ocrService = new \KDocs\Services\OCRService();
    test('19. OCRService', true);
} catch (Exception $e) {
    test('19. OCRService', false, $e->getMessage());
}

try {
    $classService = new \KDocs\Services\ClassificationService();
    test('20. ClassificationService', true);
} catch (Exception $e) {
    test('20. ClassificationService', false, $e->getMessage());
}

try {
    $embService = new \KDocs\Services\EmbeddingService();
    test('21. EmbeddingService', true);
    $embAvailable = $embService->isAvailable();
    test('22. EmbeddingService available', $embAvailable, '', true);
} catch (Exception $e) {
    test('21. EmbeddingService', false, $e->getMessage());
}

try {
    $vecService = new \KDocs\Services\VectorStoreService();
    test('23. VectorStoreService', true);
} catch (Exception $e) {
    test('23. VectorStoreService', false, $e->getMessage());
}

try {
    $snapService = new \KDocs\Services\SnapshotService();
    test('24. SnapshotService', true);
} catch (Exception $e) {
    test('24. SnapshotService', false, $e->getMessage());
}

// ==========================================
// 6. Docker Services
// ==========================================
echo "\n--- SERVICES DOCKER ---\n";

// OnlyOffice
$ooUrl = $config['onlyoffice']['server_url'] ?? 'http://localhost:8080';
$ch = curl_init($ooUrl . '/healthcheck');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$ooResponse = curl_exec($ch);
curl_close($ch);
$ooOk = $ooResponse && strpos($ooResponse, 'true') !== false;
test('25. OnlyOffice', $ooOk, $ooOk ? 'healthcheck OK' : 'non accessible');

// Qdrant (optionnel - désactivé par défaut)
$qdEnabled = $config['qdrant']['enabled'] ?? false;
if ($qdEnabled) {
    $qdHost = $config['qdrant']['host'] ?? 'localhost';
    $qdPort = $config['qdrant']['port'] ?? 6333;
    $ch = curl_init("http://{$qdHost}:{$qdPort}/collections");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $qdResponse = curl_exec($ch);
    curl_close($ch);
    $qdOk = $qdResponse && strpos($qdResponse, 'result') !== false;
    test('26. Qdrant', $qdOk, $qdOk ? 'API accessible' : 'non accessible', true);
} else {
    test('26. Qdrant', true, 'désactivé (MySQL+Ollama utilisé)', false);
}

// Ollama
$ollamaUrl = $config['embeddings']['ollama_url'] ?? 'http://localhost:11434';
$ch = curl_init($ollamaUrl . '/api/tags');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$ollamaResponse = curl_exec($ch);
curl_close($ch);
$ollamaOk = $ollamaResponse && strpos($ollamaResponse, 'models') !== false;
test('27. Ollama', $ollamaOk, $ollamaOk ? 'API accessible' : 'non démarré', true);

$nomicOk = $ollamaResponse && strpos($ollamaResponse, 'nomic-embed-text') !== false;
test('28. nomic-embed-text', $nomicOk, $nomicOk ? 'modèle installé' : 'non installé', true);

// ==========================================
// 7. AI Providers (Claude / Ollama fallback)
// ==========================================
echo "\n--- INTELLIGENCE ARTIFICIELLE ---\n";

// Test AIProviderService
try {
    $aiProvider = new \KDocs\Services\AIProviderService();
    $aiStatus = $aiProvider->getStatus();
    
    $activeProvider = $aiStatus['active_provider'] ?? 'none';
    $claudeAvailable = $aiStatus['claude']['available'] ?? false;
    $ollamaAvailable = $aiStatus['ollama']['available'] ?? false;
    
    test('29. Claude API', $claudeAvailable, $claudeAvailable ? 'disponible' : 'non configuré', true);
    test('30. Ollama (fallback)', $ollamaAvailable, $ollamaAvailable ? 'disponible' : 'non démarré', true);
    
    $aiAvailable = $claudeAvailable || $ollamaAvailable;
    $providerInfo = match($activeProvider) {
        'claude' => 'Claude (qualité max)',
        'ollama' => 'Ollama (fallback)',
        default => 'aucun',
    };
    test('31. Provider IA actif', $aiAvailable, $providerInfo, true);
    
} catch (Exception $e) {
    test('29. Claude API', false, $e->getMessage(), true);
    test('30. Ollama (fallback)', false, '', true);
    test('31. Provider IA actif', false, 'erreur détection', true);
}

// ==========================================
// 8. Authentication
// ==========================================
echo "\n--- AUTHENTIFICATION ---\n";

if ($db) {
    $admin = $db->query('SELECT id, username, password_hash FROM users WHERE is_admin = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    test('32. Admin existe', $admin !== false, $admin ? $admin['username'] : '');
    
    $hasPassword = $admin && !empty($admin['password_hash']) && strlen($admin['password_hash']) > 10;
    test('33. Admin password', $hasPassword);
}

// ==========================================
// 9. HTTP Access
// ==========================================
echo "\n--- ACCÈS HTTP ---\n";

$appUrl = $config['app']['url'] ?? 'http://localhost/kdocs';
$ch = curl_init($appUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_NOBODY => true,
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$httpOk = $httpCode >= 200 && $httpCode < 400;
test('34. HTTP Access', $httpOk, "HTTP $httpCode @ $appUrl");

// Health endpoint
$ch = curl_init($appUrl . '/health');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
$healthResponse = curl_exec($ch);
$healthCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$healthOk = $healthCode === 200 && strpos($healthResponse, 'healthy') !== false;
test('35. Health endpoint', $healthOk, $healthOk ? 'OK' : "HTTP $healthCode");

// ==========================================
// Summary
// ==========================================
echo "\n========================================\n";
$total = $passed + $failed + $warnings;
echo "RÉSULTAT: $passed/$total OK";
if ($warnings > 0) echo ", $warnings warnings";
if ($failed > 0) echo ", $failed FAILED";
echo "\n========================================\n";

if ($failed === 0) {
    echo "\033[32m✓ SMOKE TEST RÉUSSI\033[0m\n";
    if ($warnings > 0) {
        echo "\033[33m  ($warnings avertissements non bloquants)\033[0m\n";
    }
    exit(0);
} else {
    echo "\033[31m✗ SMOKE TEST ÉCHOUÉ\033[0m\n";
    echo "  Corrigez les $failed erreurs ci-dessus.\n";
    exit(1);
}
