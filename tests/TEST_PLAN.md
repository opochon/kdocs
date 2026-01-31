# K-Docs - Plan de Tests Complet

## Vue d'ensemble

Ce document décrit la stratégie de tests pour K-Docs, incluant :
- Tests de fumée (smoke tests)
- Tests fonctionnels
- Tests d'intégration
- Tests de performance/stress

---

## 1. Structure des Tests

```
tests/
├── smoke_test.php              # Tests rapides de base (22 checks)
├── full_test_suite.php         # Suite complète (nouveau)
├── run_tests.bat               # Script Windows
├── run_smoke_test.bat          # Script smoke test
├── test_data/                  # Fichiers de test générés
├── test_results/               # Rapports JSON
├── Unit/                       # Tests unitaires PHPUnit (à créer)
└── Feature/                    # Tests fonctionnels (à créer)
```

---

## 2. Smoke Test (22 checks) ✅

Vérification rapide que l'application fonctionne.

```bash
php tests/smoke_test.php
```

### Checks effectués :
1. Config.php existe
2. Config chargeable
3. Connexion DB
4. Table users
5. Table documents
6. storage/ existe
7. storage/documents/
8. storage/consume/
9. storage/thumbnails/
10. storage/temp/
11. storage/ writable
12. Tesseract OCR
13. Tesseract fra (warning)
14. Ghostscript
15. pdftotext (warning)
16. OCRService
17. ClassificationService
18. FolderTreeHelper
19. Claude API Key (warning)
20. Admin existe
21. Admin password
22. HTTP Access

---

## 3. Full Test Suite (Nouveau) ✅

Suite complète avec 3 modes :

```bash
# Mode rapide (~30s)
php tests/full_test_suite.php --quick

# Mode complet (~2min)
php tests/full_test_suite.php --full

# Mode stress (~5min)
php tests/full_test_suite.php --stress
```

### Phase 1: Infrastructure
| Test | Description |
|------|-------------|
| Connexion DB | Base de données accessible |
| Tables principales | documents, users, tags, correspondents, document_types |
| Tables embeddings | embedding_logs |
| Tables snapshots | snapshots |
| Dossiers storage | Existent et writable |

### Phase 2: Services Docker
| Test | Description |
|------|-------------|
| OnlyOffice container | Healthcheck OK |
| OnlyOffice service | isAvailable() |
| Qdrant container | API accessible |
| VectorStoreService | isAvailable() |
| Collection Qdrant | Créable |
| Ollama | API accessible (warning) |
| nomic-embed-text | Modèle disponible (warning) |

### Phase 3: Authentification & API
| Test | Description |
|------|-------------|
| Page login | Accessible |
| Login admin | Cookie session |
| Accès authentifié | Documents visible |
| API /api/documents | Retourne JSON valide |
| API /api/tags | Retourne JSON valide |
| API /api/correspondents | Retourne JSON valide |
| API /api/document-types | Retourne JSON valide |
| API /health | Status OK |

### Phase 4: Opérations Documents
| Test | Description |
|------|-------------|
| Upload PDF | Création réussie, ID retourné |
| Upload DOCX | Création réussie |
| OCR document | Contenu extrait |
| Recherche fulltext | Résultats retournés |
| Recherche par tag | Filtre fonctionne |
| Mise à jour | Title modifiable |
| Suppression | Delete API fonctionne |

### Phase 5: Recherche Sémantique (mode full)
| Test | Description |
|------|-------------|
| EmbeddingService | Disponible |
| Génération embedding | Vecteur 768+ dimensions |
| Embedding document | Document indexé |
| Recherche vectorielle | Résultats pertinents |

### Phase 6: Workflows (mode full)
| Test | Description |
|------|-------------|
| Création workflow | INSERT en base |
| WorkflowEngine | Instanciable |

### Phase 7: Stress Tests (mode stress)
| Test | Description |
|------|-------------|
| Bulk upload | 50 documents uploadés |
| Recherches concurrentes | 20 requêtes parallèles |
| Bulk delete | Nettoyage en masse |

---

## 4. Tests Unitaires (À implémenter)

### Services à tester :

```php
// tests/Unit/Services/EmbeddingServiceTest.php
class EmbeddingServiceTest extends TestCase
{
    public function test_embed_returns_vector(): void
    {
        $service = new EmbeddingService();
        $vector = $service->embed("Test text");
        $this->assertIsArray($vector);
        $this->assertGreaterThan(100, count($vector));
    }
    
    public function test_embed_empty_text_returns_null(): void
    {
        $service = new EmbeddingService();
        $this->assertNull($service->embed(""));
    }
}

// tests/Unit/Services/VectorStoreServiceTest.php
class VectorStoreServiceTest extends TestCase
{
    public function test_upsert_and_search(): void
    {
        $service = new VectorStoreService();
        $vector = array_fill(0, 768, 0.1);
        
        $this->assertTrue($service->upsert(99999, $vector, ['title' => 'Test']));
        
        $results = $service->search($vector, 1);
        $this->assertNotEmpty($results);
        $this->assertEquals(99999, $results[0]['id']);
        
        $this->assertTrue($service->delete(99999));
    }
}

// tests/Unit/Services/SnapshotServiceTest.php
class SnapshotServiceTest extends TestCase
{
    public function test_create_snapshot(): void
    {
        $service = new SnapshotService();
        $id = $service->createSnapshot('Test Snapshot', 'Description', 'manual', 1);
        $this->assertGreaterThan(0, $id);
    }
}
```

### Modèles à tester :

```php
// tests/Unit/Models/DocumentTest.php
class DocumentTest extends TestCase
{
    public function test_create_document(): void
    {
        $id = Document::create([
            'title' => 'Test Doc',
            'filename' => 'test.pdf',
            'original_filename' => 'test.pdf',
            'file_path' => '/tmp/test.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
        ]);
        $this->assertGreaterThan(0, $id);
        
        // Cleanup
        Document::delete($id);
    }
}
```

---

## 5. Tests d'Intégration

### Scénarios complets :

```php
// tests/Feature/DocumentWorkflowTest.php
class DocumentWorkflowTest extends TestCase
{
    public function test_full_document_lifecycle(): void
    {
        // 1. Upload
        $docId = $this->uploadDocument('test.pdf');
        $this->assertGreaterThan(0, $docId);
        
        // 2. Wait for processing
        sleep(5);
        
        // 3. Check OCR
        $doc = Document::findById($docId);
        $this->assertNotEmpty($doc['ocr_text']);
        
        // 4. Check embedding
        $this->assertEquals('completed', $doc['embedding_status']);
        
        // 5. Search
        $results = $this->searchDocuments('test');
        $this->assertContains($docId, array_column($results, 'id'));
        
        // 6. Update
        Document::update($docId, ['title' => 'Updated']);
        
        // 7. Delete
        Document::delete($docId);
    }
    
    public function test_onlyoffice_preview(): void
    {
        $docId = $this->uploadDocument('test.docx');
        
        $response = $this->get("/api/onlyoffice/config/{$docId}");
        $this->assertJsonStructure($response, [
            'success',
            'config' => ['document', 'editorConfig']
        ]);
        
        Document::delete($docId);
    }
    
    public function test_semantic_search_accuracy(): void
    {
        // Upload documents with known content
        $invoiceId = $this->uploadDocument('facture_electricite.pdf');
        $contractId = $this->uploadDocument('contrat_travail.pdf');
        
        sleep(10); // Wait for embeddings
        
        // Search should find invoice first
        $results = $this->semanticSearch('facture énergie électricité');
        $this->assertEquals($invoiceId, $results[0]['id']);
        
        // Search should find contract first
        $results = $this->semanticSearch('contrat emploi travail');
        $this->assertEquals($contractId, $results[0]['id']);
        
        // Cleanup
        Document::delete($invoiceId);
        Document::delete($contractId);
    }
}
```

---

## 6. Tests de Performance

### Métriques à mesurer :

| Métrique | Cible | Test |
|----------|-------|------|
| Upload 1 PDF | < 2s | `test_upload_performance` |
| OCR 1 page | < 5s | `test_ocr_performance` |
| Recherche fulltext | < 100ms | `test_search_performance` |
| Recherche sémantique | < 500ms | `test_semantic_performance` |
| Génération embedding | < 2s | `test_embedding_performance` |
| Liste 100 documents | < 200ms | `test_list_performance` |

### Script de benchmark :

```php
// tests/benchmark.php
$results = [];

// Test upload performance
$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    uploadDocument("test_$i.pdf");
}
$results['upload_10_docs'] = (microtime(true) - $start) / 10;

// Test search performance
$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    searchDocuments('test');
}
$results['search_100_queries'] = (microtime(true) - $start) / 100;

// Output
print_r($results);
```

---

## 7. Tests OnlyOffice Spécifiques

| Test | Description |
|------|-------------|
| Healthcheck | Container répond |
| Config génération | Token JWT valide |
| Download callback | Docker accède à l'app |
| Edit mode | Édition fonctionne |
| Save callback | Modifications sauvegardées |
| Concurrent editing | Multi-utilisateurs |

```php
class OnlyOfficeTest extends TestCase
{
    public function test_config_generation(): void
    {
        $service = new OnlyOfficeService();
        $doc = Document::findById(1);
        
        $config = $service->generateConfig($doc, 1, 'Test User', false);
        
        $this->assertArrayHasKey('document', $config);
        $this->assertArrayHasKey('url', $config['document']);
        $this->assertArrayHasKey('editorConfig', $config);
    }
    
    public function test_download_url_accessible_from_docker(): void
    {
        // Execute curl from inside docker container
        $url = "http://192.168.1.14/kdocs/api/onlyoffice/public/download/1/testtoken";
        $output = shell_exec("docker exec kdocs-onlyoffice curl -s -o /dev/null -w '%{http_code}' '$url'");
        $this->assertEquals('200', trim($output));
    }
}
```

---

## 8. Exécution Continue (CI)

### GitHub Actions exemple :

```yaml
# .github/workflows/tests.yml
name: K-Docs Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mariadb:
        image: mariadb:10.11
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: kdocs_test
        ports:
          - 3306:3306
      
      qdrant:
        image: qdrant/qdrant
        ports:
          - 6333:6333
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_mysql, curl, zip
      
      - name: Install dependencies
        run: composer install
      
      - name: Run smoke tests
        run: php tests/smoke_test.php
      
      - name: Run full tests
        run: php tests/full_test_suite.php --full
```

---

## 9. Checklist Pre-Production

### Avant déploiement :

- [ ] Smoke test passe (22/22)
- [ ] Full test suite passe (--full)
- [ ] OnlyOffice healthcheck OK
- [ ] Qdrant accessible
- [ ] Ollama avec nomic-embed-text
- [ ] Backup de la base de données
- [ ] Snapshot système créé
- [ ] Tests de recherche manuels
- [ ] Test upload/delete manuel
- [ ] Test workflow manuel

---

## 10. Commandes Rapides

```bash
# Smoke test rapide
php tests/smoke_test.php

# Suite complète mode rapide
php tests/full_test_suite.php --quick

# Suite complète avec embeddings
php tests/full_test_suite.php --full

# Tests de stress
php tests/full_test_suite.php --stress

# Windows
tests\run_tests.bat --full
tests\run_smoke_test.bat
```

---

*Document créé le 30/01/2026*
