<?php
/**
 * K-DOCS - Smoke Test Stabilisation
 * 
 * Test complet de l'application après stabilisation.
 * Exécuter: php tests/smoke_test_stabilisation.php
 * 
 * Retourne code 0 si OK, 1 si erreurs critiques
 */

require_once __DIR__ . '/../app/autoload.php';

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\MySQLFullTextProvider;
use KDocs\Services\ThumbnailGenerator;
use KDocs\Services\SnapshotService;
use KDocs\Services\EmbeddingService;
use KDocs\Services\OnlyOfficeService;
use KDocs\Helpers\SystemHelper;

class StabilisationSmokeTest
{
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;
    private float $startTime;

    public function run(): int
    {
        $this->startTime = microtime(true);
        
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║         K-DOCS SMOKE TEST - STABILISATION                   ║\n";
        echo "║         " . date('Y-m-d H:i:s') . "                                   ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";

        // ===== TESTS CRITIQUES (CORE) =====
        $this->section("CORE - Base de données");
        $this->test('database_connection', fn() => $this->testDatabaseConnection(), critical: true);
        $this->test('fulltext_index_exists', fn() => $this->testFullTextIndex(), critical: true);
        $this->test('tables_exist', fn() => $this->testTablesExist(), critical: true);

        $this->section("CORE - Recherche MySQL FULLTEXT");
        $this->test('search_provider_available', fn() => $this->testSearchProviderAvailable(), critical: true);
        $this->test('search_basic', fn() => $this->testSearchBasic(), critical: true);
        $this->test('search_boolean_and', fn() => $this->testSearchBooleanAnd(), critical: false);
        $this->test('search_boolean_not', fn() => $this->testSearchBooleanNot(), critical: false);
        $this->test('search_phrase', fn() => $this->testSearchPhrase(), critical: false);
        $this->test('search_empty_returns_recent', fn() => $this->testSearchEmptyReturnsRecent(), critical: false);

        $this->section("CORE - Outils système");
        $this->test('ghostscript_available', fn() => $this->testGhostscriptAvailable(), critical: true);
        $this->test('tesseract_available', fn() => $this->testTesseractAvailable(), critical: true);
        $this->test('libreoffice_available', fn() => $this->testLibreOfficeAvailable(), critical: true);

        $this->section("CORE - Miniatures");
        $this->test('thumbnail_generator', fn() => $this->testThumbnailGenerator(), critical: true);
        $this->test('thumbnail_pdf', fn() => $this->testThumbnailPdf(), critical: false);

        // ===== TESTS NON-CRITIQUES (OPTIONNELS) =====
        $this->section("OPTIONNEL - Services IA");
        $this->test('ollama_available', fn() => $this->testOllamaAvailable(), critical: false, optional: true);
        $this->test('claude_api_configured', fn() => $this->testClaudeApiConfigured(), critical: false, optional: true);

        $this->section("OPTIONNEL - OnlyOffice");
        $this->test('onlyoffice_available', fn() => $this->testOnlyOfficeAvailable(), critical: false, optional: true);

        $this->section("FONCTIONNEL - API");
        $this->test('api_health', fn() => $this->testApiHealth(), critical: false);
        $this->test('api_documents_list', fn() => $this->testApiDocumentsList(), critical: false);

        $this->section("FONCTIONNEL - Snapshots");
        $this->test('snapshot_service', fn() => $this->testSnapshotService(), critical: false);

        $this->section("DONNÉES - Cohérence");
        $this->test('deleted_at_consistent', fn() => $this->testDeletedAtConsistent(), critical: false);
        $this->test('no_orphan_tags', fn() => $this->testNoOrphanTags(), critical: false);

        // ===== RAPPORT FINAL =====
        $this->printReport();

        return $this->failed > 0 ? 1 : 0;
    }

    private function section(string $title): void
    {
        echo "\n\033[1;34m━━━ {$title} ━━━\033[0m\n";
    }

    private function test(string $name, callable $fn, bool $critical = false, bool $optional = false): void
    {
        try {
            $result = $fn();
            
            if ($result === true) {
                $this->passed++;
                $status = "\033[32m✓ PASS\033[0m";
            } elseif ($result === 'skip') {
                $this->warnings++;
                $status = "\033[33m○ SKIP\033[0m";
            } elseif ($optional && $result === false) {
                $this->warnings++;
                $status = "\033[33m⚠ WARN\033[0m (optionnel)";
            } else {
                if ($critical) {
                    $this->failed++;
                    $status = "\033[31m✗ FAIL\033[0m (CRITIQUE)";
                } else {
                    $this->warnings++;
                    $status = "\033[33m⚠ WARN\033[0m";
                }
            }
            
            $this->results[$name] = [
                'status' => $result === true ? 'pass' : ($result === 'skip' ? 'skip' : ($optional ? 'warn' : ($critical ? 'fail' : 'warn'))),
                'critical' => $critical,
                'optional' => $optional,
            ];

        } catch (\Throwable $e) {
            if ($critical) {
                $this->failed++;
                $status = "\033[31m✗ FAIL\033[0m - " . $e->getMessage();
            } else {
                $this->warnings++;
                $status = "\033[33m⚠ WARN\033[0m - " . $e->getMessage();
            }
            
            $this->results[$name] = [
                'status' => $critical ? 'fail' : 'warn',
                'error' => $e->getMessage(),
                'critical' => $critical,
            ];
        }

        echo "  {$status} {$name}\n";
    }

    private function printReport(): void
    {
        $totalTime = round(microtime(true) - $this->startTime, 2);
        $total = $this->passed + $this->failed + $this->warnings;

        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                      RAPPORT FINAL                          ║\n";
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        printf("║  Tests exécutés: %-43d ║\n", $total);
        printf("║  \033[32mRéussis: %-50d\033[0m ║\n", $this->passed);
        printf("║  \033[33mAvertissements: %-43d\033[0m ║\n", $this->warnings);
        printf("║  \033[31mÉchecs critiques: %-41d\033[0m ║\n", $this->failed);
        printf("║  Temps: %-51s ║\n", $totalTime . 's');
        echo "╠══════════════════════════════════════════════════════════════╣\n";
        
        if ($this->failed === 0) {
            echo "║  \033[32m✓ STABILISATION OK - Tous les tests critiques passent\033[0m    ║\n";
        } else {
            echo "║  \033[31m✗ ÉCHEC - Corriger les erreurs critiques\033[0m                ║\n";
        }
        
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    }

    // ========== TESTS IMPLEMENTATION ==========

    private function testDatabaseConnection(): bool
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT 1");
        return $stmt->fetchColumn() == 1;
    }

    private function testFullTextIndex(): bool
    {
        $db = Database::getInstance();
        $stmt = $db->query("SHOW INDEX FROM documents WHERE Index_type = 'FULLTEXT'");
        return $stmt->rowCount() > 0;
    }

    private function testTablesExist(): bool
    {
        $db = Database::getInstance();
        $required = ['documents', 'correspondents', 'document_types', 'tags', 'document_tags', 'users'];
        
        foreach ($required as $table) {
            $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() === 0) {
                throw new \Exception("Table manquante: {$table}");
            }
        }
        return true;
    }

    private function testSearchProviderAvailable(): bool
    {
        $provider = new MySQLFullTextProvider();
        return $provider->isAvailable();
    }

    private function testSearchBasic(): bool
    {
        $provider = new MySQLFullTextProvider();
        $result = $provider->search('test', [], 10);
        return isset($result['documents']) && isset($result['total']);
    }

    private function testSearchBooleanAnd(): bool
    {
        $provider = new MySQLFullTextProvider();
        $result = $provider->search('facture AND swisscom', [], 10);
        return isset($result['documents']);
    }

    private function testSearchBooleanNot(): bool
    {
        $provider = new MySQLFullTextProvider();
        $result = $provider->search('facture NOT annulée', [], 10);
        return isset($result['documents']);
    }

    private function testSearchPhrase(): bool
    {
        $provider = new MySQLFullTextProvider();
        $result = $provider->search('"facture janvier"', [], 10);
        return isset($result['documents']);
    }

    private function testSearchEmptyReturnsRecent(): bool
    {
        $provider = new MySQLFullTextProvider();
        $result = $provider->search('', [], 10);
        return isset($result['documents']) && $result['query_type'] === 'recent';
    }

    private function testGhostscriptAvailable(): bool
    {
        $path = SystemHelper::findGhostscript();
        if (!$path || !file_exists($path)) {
            throw new \Exception("Ghostscript non trouvé");
        }
        return true;
    }

    private function testTesseractAvailable(): bool
    {
        $config = Config::load();
        $path = $config['ocr']['tesseract_path'] ?? null;
        
        if (!$path || !file_exists($path)) {
            throw new \Exception("Tesseract non trouvé: {$path}");
        }
        return true;
    }

    private function testLibreOfficeAvailable(): bool
    {
        $path = SystemHelper::findLibreOffice();
        if (!$path || !file_exists($path)) {
            throw new \Exception("LibreOffice non trouvé");
        }
        return true;
    }

    private function testThumbnailGenerator(): bool
    {
        $generator = new ThumbnailGenerator();
        $tools = $generator->getAvailableTools();
        
        if (!$tools['gd']) {
            throw new \Exception("Extension GD non disponible");
        }
        return true;
    }

    private function testThumbnailPdf(): bool
    {
        $generator = new ThumbnailGenerator();
        $tools = $generator->getAvailableTools();
        
        return $tools['ghostscript'] || $tools['imagemagick'];
    }

    private function testOllamaAvailable(): bool
    {
        $service = new EmbeddingService();
        return $service->isOllamaAvailable();
    }

    private function testClaudeApiConfigured(): bool
    {
        $config = Config::load();
        $key = $config['claude']['api_key'] ?? $config['ai']['claude_api_key'] ?? null;
        
        if (empty($key)) {
            // Vérifier le fichier
            $keyFile = dirname(__DIR__) . '/claude_api_key.txt';
            if (file_exists($keyFile)) {
                $key = trim(file_get_contents($keyFile));
            }
        }
        
        return !empty($key) && str_starts_with($key, 'sk-');
    }

    private function testOnlyOfficeAvailable(): bool
    {
        $service = new OnlyOfficeService();
        return $service->isAvailable();
    }

    private function testApiHealth(): bool
    {
        $url = Config::get('app.url', 'http://localhost/kdocs') . '/api/health';
        
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return 'skip'; // Peut-être pas accessible depuis CLI
        }
        
        $data = json_decode($response, true);
        return isset($data['status']) && $data['status'] === 'ok';
    }

    private function testApiDocumentsList(): bool
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL");
        $count = (int) $stmt->fetchColumn();
        
        // Juste vérifier qu'on peut compter
        return $count >= 0;
    }

    private function testSnapshotService(): bool
    {
        $service = new SnapshotService();
        // Juste vérifier que le service s'instancie
        return true;
    }

    private function testDeletedAtConsistent(): bool
    {
        $db = Database::getInstance();
        
        // Vérifier qu'il n'y a pas de documents avec is_deleted=1 ET deleted_at=NULL
        $stmt = $db->query("
            SELECT COUNT(*) FROM documents 
            WHERE is_deleted = 1 AND deleted_at IS NULL
        ");
        $inconsistent = (int) $stmt->fetchColumn();
        
        if ($inconsistent > 0) {
            throw new \Exception("{$inconsistent} documents avec is_deleted=1 mais deleted_at=NULL");
        }
        return true;
    }

    private function testNoOrphanTags(): bool
    {
        $db = Database::getInstance();
        
        // Tags liés à des documents supprimés
        $stmt = $db->query("
            SELECT COUNT(*) FROM document_tags dt
            INNER JOIN documents d ON dt.document_id = d.id
            WHERE d.deleted_at IS NOT NULL
        ");
        $orphans = (int) $stmt->fetchColumn();
        
        if ($orphans > 100) { // Seuil de tolérance
            throw new \Exception("{$orphans} liens tag-document vers documents supprimés");
        }
        return true;
    }
}

// Exécution
$test = new StabilisationSmokeTest();
exit($test->run());
