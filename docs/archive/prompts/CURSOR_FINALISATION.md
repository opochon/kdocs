# K-Docs v1 - Instructions Cursor pour Finalisation

## 🎯 OBJECTIF

Finaliser K-Docs pour atteindre **100% de parité Paperless-ngx** + **bonus IA (FILESORDNER)**.

**Répertoire** : `C:\wamp64\www\kdocs`
**DB** : MariaDB port 3307, database `kdocs`

---

## 📊 ÉTAT ACTUEL (~70%)

### ✅ Déjà implémenté
- Documents CRUD, Upload, Preview PDF.js
- Tags avec nested/hiérarchie
- Correspondents, Document Types
- Storage Paths
- Custom Fields
- Matching Service (6 algorithmes)
- OCR Service (Tesseract)
- Workflows (structure)
- Webhooks
- Mail Accounts & Rules
- Scheduled Tasks
- Export/Import
- Audit Logs
- Users & Roles

### ❌ À finaliser (30% restant)

---

## 📋 SÉQUENCE D'EXÉCUTION

### ÉTAPE 1 : Consume Folder (Scanner automatique)
**Priorité : HAUTE**

Créer un service qui surveille un dossier et importe automatiquement les nouveaux fichiers.

**Fichiers à créer/modifier** :
```
app/Services/ConsumeFolderService.php
app/workers/consume_worker.php
templates/admin/consume_settings.php
```

**ConsumeFolderService.php** :
```php
<?php
namespace KDocs\Services;

class ConsumeFolderService
{
    private string $consumePath;
    private DocumentProcessor $processor;
    
    public function __construct()
    {
        $config = \KDocs\Core\Config::load();
        $this->consumePath = $config['storage']['consume'] ?? __DIR__ . '/../../storage/consume';
        $this->processor = new DocumentProcessor();
    }
    
    /**
     * Scanner le dossier consume et traiter les nouveaux fichiers
     */
    public function scan(): array
    {
        $results = ['processed' => 0, 'errors' => []];
        
        if (!is_dir($this->consumePath)) {
            @mkdir($this->consumePath, 0755, true);
            return $results;
        }
        
        $files = glob($this->consumePath . '/*.*');
        
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif'])) {
                continue;
            }
            
            try {
                $this->processFile($file);
                $results['processed']++;
            } catch (\Exception $e) {
                $results['errors'][] = basename($file) . ': ' . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Traiter un fichier du dossier consume
     */
    private function processFile(string $filePath): void
    {
        $filename = basename($filePath);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // Générer hash pour éviter doublons
        $hash = md5_file($filePath);
        
        // Vérifier si déjà importé
        $db = \KDocs\Core\Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM documents WHERE checksum = ?");
        $stmt->execute([$hash]);
        if ($stmt->fetch()) {
            // Doublon, supprimer ou déplacer
            @unlink($filePath);
            return;
        }
        
        // Copier vers storage/documents
        $config = \KDocs\Core\Config::load();
        $destDir = $config['storage']['documents'] ?? __DIR__ . '/../../storage/documents';
        $newFilename = uniqid() . '_' . $filename;
        $destPath = $destDir . '/' . $newFilename;
        
        if (!copy($filePath, $destPath)) {
            throw new \Exception("Impossible de copier le fichier");
        }
        
        // Créer le document en DB
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $stmt = $db->prepare("
            INSERT INTO documents (title, original_filename, storage_path, mime_type, checksum, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff'
        ];
        $stmt->execute([
            $title,
            $filename,
            $newFilename,
            $mimeTypes[$ext] ?? 'application/octet-stream',
            $hash
        ]);
        
        $documentId = $db->lastInsertId();
        
        // Lancer le traitement (OCR, matching, thumbnail)
        $this->processor->process($documentId);
        
        // Supprimer le fichier original du consume folder
        @unlink($filePath);
    }
}
```

**consume_worker.php** :
```php
<?php
require_once __DIR__ . '/../autoload.php';

$service = new \KDocs\Services\ConsumeFolderService();
$results = $service->scan();

echo "Processed: " . $results['processed'] . "\n";
if (!empty($results['errors'])) {
    echo "Errors:\n";
    foreach ($results['errors'] as $error) {
        echo "  - $error\n";
    }
}
```

**Critère de succès** : Déposer un PDF dans `storage/consume/`, lancer le worker, document importé automatiquement.

---

### ÉTAPE 2 : Améliorer DocumentProcessor
**Priorité : HAUTE**

Le DocumentProcessor doit enchaîner : OCR → Matching → Thumbnail → Workflows

**Modifier** `app/Services/DocumentProcessor.php` :
```php
<?php
namespace KDocs\Services;

use KDocs\Core\Database;

class DocumentProcessor
{
    private OCRService $ocr;
    private MatchingService $matching;
    private ThumbnailGenerator $thumbnail;
    private WorkflowService $workflow;
    
    public function __construct()
    {
        $this->ocr = new OCRService();
        $this->thumbnail = new ThumbnailGenerator();
        $this->workflow = new WorkflowService();
    }
    
    /**
     * Traitement complet d'un document
     */
    public function process(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch();
        
        if (!$document) {
            throw new \Exception("Document introuvable");
        }
        
        $results = [
            'ocr' => false,
            'matching' => [],
            'thumbnail' => false,
            'workflows' => []
        ];
        
        // 1. OCR si pas de contenu
        if (empty($document['content'])) {
            $config = \KDocs\Core\Config::load();
            $filePath = ($config['storage']['documents'] ?? __DIR__ . '/../../storage/documents') 
                        . '/' . $document['storage_path'];
            
            if (file_exists($filePath)) {
                $content = $this->ocr->extractText($filePath);
                if ($content) {
                    $stmt = $db->prepare("UPDATE documents SET content = ? WHERE id = ?");
                    $stmt->execute([$content, $documentId]);
                    $document['content'] = $content;
                    $results['ocr'] = true;
                }
            }
        }
        
        // 2. Matching automatique
        if (!empty($document['content'])) {
            $matches = MatchingService::applyMatching($document['content']);
            
            // Appliquer les tags
            foreach ($matches['tags'] as $tagId) {
                $db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                   ->execute([$documentId, $tagId]);
            }
            
            // Appliquer le premier correspondent trouvé
            if (!empty($matches['correspondents']) && empty($document['correspondent_id'])) {
                $db->prepare("UPDATE documents SET correspondent_id = ? WHERE id = ?")
                   ->execute([$matches['correspondents'][0], $documentId]);
            }
            
            // Appliquer le premier type trouvé
            if (!empty($matches['document_types']) && empty($document['document_type_id'])) {
                $db->prepare("UPDATE documents SET document_type_id = ? WHERE id = ?")
                   ->execute([$matches['document_types'][0], $documentId]);
            }
            
            // Appliquer le premier storage path trouvé
            if (!empty($matches['storage_paths']) && empty($document['storage_path_id'])) {
                $db->prepare("UPDATE documents SET storage_path_id = ? WHERE id = ?")
                   ->execute([$matches['storage_paths'][0], $documentId]);
            }
            
            $results['matching'] = $matches;
        }
        
        // 3. Générer thumbnail
        if (empty($document['thumbnail_path'])) {
            $config = \KDocs\Core\Config::load();
            $filePath = ($config['storage']['documents'] ?? __DIR__ . '/../../storage/documents') 
                        . '/' . $document['storage_path'];
            
            $thumbPath = $this->thumbnail->generate($filePath, $documentId);
            if ($thumbPath) {
                $db->prepare("UPDATE documents SET thumbnail_path = ? WHERE id = ?")
                   ->execute([$thumbPath, $documentId]);
                $results['thumbnail'] = true;
            }
        }
        
        // 4. Exécuter les workflows
        $results['workflows'] = $this->workflow->executeForDocument($documentId);
        
        return $results;
    }
    
    /**
     * Retraiter tous les documents sans contenu
     */
    public function reprocessAll(): array
    {
        $db = Database::getInstance();
        $docs = $db->query("SELECT id FROM documents WHERE content IS NULL OR content = ''")->fetchAll();
        
        $results = ['total' => count($docs), 'success' => 0, 'errors' => []];
        
        foreach ($docs as $doc) {
            try {
                $this->process($doc['id']);
                $results['success']++;
            } catch (\Exception $e) {
                $results['errors'][] = "Doc #{$doc['id']}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
}
```

---

### ÉTAPE 3 : Interface Notes de Documents
**Priorité : MOYENNE**

Le modèle `DocumentNote` existe, créer l'interface.

**Modifier** `templates/documents/show.php` pour ajouter section Notes :
```php
<!-- Section Notes -->
<div class="bg-white rounded-lg shadow p-6 mt-6">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-semibold">Notes</h3>
        <button onclick="showAddNoteModal()" class="btn btn-sm btn-primary">
            <i class="fas fa-plus mr-1"></i> Ajouter
        </button>
    </div>
    
    <div id="notes-list">
        <?php foreach ($notes as $note): ?>
        <div class="border-b py-3 last:border-0">
            <div class="flex justify-between">
                <span class="text-sm text-gray-500">
                    <?= htmlspecialchars($note['user_name'] ?? 'Système') ?> - 
                    <?= date('d/m/Y H:i', strtotime($note['created_at'])) ?>
                </span>
                <button onclick="deleteNote(<?= $note['id'] ?>)" class="text-red-500 hover:text-red-700">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <p class="mt-1"><?= nl2br(htmlspecialchars($note['content'])) ?></p>
        </div>
        <?php endforeach; ?>
        <?php if (empty($notes)): ?>
        <p class="text-gray-500 italic">Aucune note</p>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Ajout Note -->
<div id="add-note-modal" class="modal hidden">
    <div class="modal-content">
        <h3>Ajouter une note</h3>
        <form action="/documents/<?= $document['id'] ?>/notes" method="POST">
            <textarea name="content" rows="4" class="w-full border rounded p-2" required></textarea>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" onclick="hideAddNoteModal()" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary">Ajouter</button>
            </div>
        </form>
    </div>
</div>
```

**Ajouter routes** dans `index.php` :
```php
$app->post('/documents/{id}/notes', [DocumentsController::class, 'addNote']);
$app->delete('/documents/{id}/notes/{noteId}', [DocumentsController::class, 'deleteNote']);
```

---

### ÉTAPE 4 : Recherche Full-Text Améliorée
**Priorité : MOYENNE**

Améliorer le SearchParser pour supporter plus d'opérateurs.

**Modifier** `app/Services/SearchParser.php` :
```php
<?php
namespace KDocs\Services;

class SearchParser
{
    /**
     * Parse une requête de recherche et retourne les conditions SQL
     */
    public static function parse(string $query): array
    {
        $conditions = [];
        $params = [];
        
        // Recherche par tag: tag:facture
        if (preg_match_all('/tag:(\w+)/i', $query, $matches)) {
            foreach ($matches[1] as $tag) {
                $conditions[] = "EXISTS (SELECT 1 FROM document_tags dt JOIN tags t ON dt.tag_id = t.id WHERE dt.document_id = d.id AND t.name LIKE ?)";
                $params[] = "%$tag%";
            }
            $query = preg_replace('/tag:\w+/i', '', $query);
        }
        
        // Recherche par correspondent: correspondent:swisscom ou from:swisscom
        if (preg_match_all('/(correspondent|from):(\w+)/i', $query, $matches)) {
            foreach ($matches[2] as $corr) {
                $conditions[] = "EXISTS (SELECT 1 FROM correspondents c WHERE c.id = d.correspondent_id AND c.name LIKE ?)";
                $params[] = "%$corr%";
            }
            $query = preg_replace('/(correspondent|from):\w+/i', '', $query);
        }
        
        // Recherche par type: type:facture
        if (preg_match_all('/type:(\w+)/i', $query, $matches)) {
            foreach ($matches[1] as $type) {
                $conditions[] = "EXISTS (SELECT 1 FROM document_types dt WHERE dt.id = d.document_type_id AND dt.name LIKE ?)";
                $params[] = "%$type%";
            }
            $query = preg_replace('/type:\w+/i', '', $query);
        }
        
        // Recherche par date: date:2024 ou date:2024-01 ou date:2024-01-15
        if (preg_match_all('/date:(\d{4}(?:-\d{2})?(?:-\d{2})?)/i', $query, $matches)) {
            foreach ($matches[1] as $date) {
                $conditions[] = "d.document_date LIKE ?";
                $params[] = "$date%";
            }
            $query = preg_replace('/date:\d{4}(?:-\d{2})?(?:-\d{2})?/i', '', $query);
        }
        
        // Recherche par plage de dates: after:2024-01-01 before:2024-12-31
        if (preg_match('/after:(\d{4}-\d{2}-\d{2})/i', $query, $match)) {
            $conditions[] = "d.document_date >= ?";
            $params[] = $match[1];
            $query = preg_replace('/after:\d{4}-\d{2}-\d{2}/i', '', $query);
        }
        if (preg_match('/before:(\d{4}-\d{2}-\d{2})/i', $query, $match)) {
            $conditions[] = "d.document_date <= ?";
            $params[] = $match[1];
            $query = preg_replace('/before:\d{4}-\d{2}-\d{2}/i', '', $query);
        }
        
        // Recherche par ASN: asn:123
        if (preg_match('/asn:(\d+)/i', $query, $match)) {
            $conditions[] = "d.archive_serial_number = ?";
            $params[] = (int)$match[1];
            $query = preg_replace('/asn:\d+/i', '', $query);
        }
        
        // Recherche par montant: amount:>100 ou amount:<500
        if (preg_match('/amount:([<>]=?)(\d+(?:\.\d+)?)/i', $query, $match)) {
            $op = $match[1];
            $conditions[] = "d.amount $op ?";
            $params[] = (float)$match[2];
            $query = preg_replace('/amount:[<>]=?\d+(?:\.\d+)?/i', '', $query);
        }
        
        // Recherche full-text sur le reste
        $query = trim($query);
        if (!empty($query)) {
            // Recherche dans titre ET contenu
            $conditions[] = "(d.title LIKE ? OR d.content LIKE ? OR d.original_filename LIKE ?)";
            $params[] = "%$query%";
            $params[] = "%$query%";
            $params[] = "%$query%";
        }
        
        return [
            'conditions' => $conditions,
            'params' => $params,
            'sql' => !empty($conditions) ? implode(' AND ', $conditions) : '1=1'
        ];
    }
}
```

---

### ÉTAPE 5 : Tester et Valider
**Priorité : HAUTE**

Créer un script de test complet.

**Créer** `test_complete.php` :
```php
<?php
require_once __DIR__ . '/app/autoload.php';

echo "=== Test Complet K-Docs ===\n\n";

// 1. Test DB
echo "1. Connexion DB... ";
try {
    $db = \KDocs\Core\Database::getInstance();
    echo "OK\n";
} catch (Exception $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Test OCR
echo "2. Service OCR... ";
$ocr = new \KDocs\Services\OCRService();
echo "OK (Tesseract)\n";

// 3. Test Matching
echo "3. Service Matching... ";
$text = "Facture Swisscom du 15 janvier 2024";
$result = \KDocs\Services\MatchingService::match($text, 'any', 'swisscom facture');
echo $result ? "OK\n" : "ERREUR\n";

// 4. Test Thumbnails
echo "4. Service Thumbnails... ";
$thumb = new \KDocs\Services\ThumbnailGenerator();
echo "OK\n";

// 5. Test Consume Folder
echo "5. Service Consume... ";
$consume = new \KDocs\Services\ConsumeFolderService();
echo "OK\n";

// 6. Compter les entités
echo "\n=== Statistiques DB ===\n";
$stats = [
    'documents' => $db->query("SELECT COUNT(*) FROM documents")->fetchColumn(),
    'tags' => $db->query("SELECT COUNT(*) FROM tags")->fetchColumn(),
    'correspondents' => $db->query("SELECT COUNT(*) FROM correspondents")->fetchColumn(),
    'document_types' => $db->query("SELECT COUNT(*) FROM document_types")->fetchColumn(),
    'users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
];
foreach ($stats as $name => $count) {
    echo "  $name: $count\n";
}

echo "\n=== Tests terminés ===\n";
```

---

## 🚀 INSTRUCTIONS POUR CURSOR

```
Tu es chargé de finaliser K-Docs (C:\wamp64\www\kdocs).

RÈGLES :
1. Exécute les étapes dans l'ordre (1 → 5)
2. Teste chaque étape avant de passer à la suivante
3. Ne casse pas le code existant
4. Ajoute des commentaires PHPDoc
5. Utilise les services existants

ÉTAPES :
1. Créer ConsumeFolderService + worker
2. Améliorer DocumentProcessor (OCR → Matching → Thumbnail → Workflow)
3. Ajouter interface Notes sur page document
4. Améliorer SearchParser (plus d'opérateurs)
5. Tester le tout

Commence par l'étape 1.
```

---

## ✅ CRITÈRES DE SUCCÈS FINAL

- [ ] Consume folder fonctionne (déposer PDF → import auto)
- [ ] OCR extrait le texte automatiquement
- [ ] Matching assigne tags/correspondents automatiquement
- [ ] Notes peuvent être ajoutées aux documents
- [ ] Recherche supporte tag:xxx, from:xxx, date:xxx, etc.
- [ ] Aucune régression sur les fonctions existantes

**Parité Paperless-ngx visée : 100%**
