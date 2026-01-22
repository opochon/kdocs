# K-Docs - CONSUME FOLDER : Pipeline d'ingestion SANS IA obligatoire

## 🎯 OBJECTIF

Pipeline Paperless-ngx style qui fonctionne **SANS Claude** :
1. Surveillance du dossier `/storage/consume`
2. Import + OCR automatique
3. **Classification par règles** (patterns, mots-clés, sous-dossiers)
4. Classification IA **optionnelle** (bonus si configuré)
5. Page de validation

---

## 📖 COMMENT PAPERLESS FAIT SANS IA

### 1. Matching par règles

Chaque entité (correspondant, tag, type) a des **mots-clés de matching** :

| Algorithme | Description |
|------------|-------------|
| `any` | Un seul mot-clé suffit |
| `all` | Tous les mots-clés requis |
| `literal` | Phrase exacte |
| `regex` | Expression régulière |
| `fuzzy` | Approximatif (Levenshtein) |

### 2. Auto-détection par patterns

```php
// Emails → Correspondant
preg_match('/[\w.-]+@[\w.-]+\.\w+/', $text, $emails);

// Dates → document_date  
preg_match('/(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/', $text, $dates);

// Montants → amount
preg_match('/(CHF|EUR|USD|\$|€)\s*([\d\'\s]+[.,]\d{2})/', $text, $amounts);
```

### 3. Sous-dossiers consume

```
/storage/consume/factures/    → Type "Facture"
/storage/consume/courrier/    → Type "Courrier"  
/storage/consume/contrats/    → Type "Contrat"
/storage/consume/             → Pas de type par défaut
```

---

## 📁 FICHIERS À CRÉER/MODIFIER

### 1. Migration : Ajouter matching_algorithm aux tables

```sql
-- Ajouter les colonnes de matching
ALTER TABLE correspondents ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any';
ALTER TABLE correspondents ADD COLUMN IF NOT EXISTS matching_keywords TEXT;
ALTER TABLE correspondents ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

ALTER TABLE tags ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any';
ALTER TABLE tags ADD COLUMN IF NOT EXISTS matching_keywords TEXT;
ALTER TABLE tags ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

ALTER TABLE document_types ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any';
ALTER TABLE document_types ADD COLUMN IF NOT EXISTS matching_keywords TEXT;
ALTER TABLE document_types ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;
ALTER TABLE document_types ADD COLUMN IF NOT EXISTS consume_subfolder VARCHAR(100);
```

---

### 2. AutoClassifierService.php (SANS IA)

**Fichier** : `app/Services/AutoClassifierService.php`

```php
<?php
/**
 * Classification automatique SANS IA
 * Basé sur les patterns et mots-clés comme Paperless-ngx
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class AutoClassifierService
{
    private $db;
    
    // Patterns de détection automatique
    private array $datePatterns = [
        // DD/MM/YYYY ou DD-MM-YYYY ou DD.MM.YYYY
        '/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})/',
        // YYYY-MM-DD (ISO)
        '/(\d{4})-(\d{2})-(\d{2})/',
        // "le 15 janvier 2024" ou "15 janvier 2024"
        '/(\d{1,2})\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+(\d{4})/i',
        // "January 15, 2024"
        '/(\w+)\s+(\d{1,2}),?\s+(\d{4})/i',
    ];
    
    private array $amountPatterns = [
        // CHF 1'234.56 ou CHF 1234.56
        '/(CHF|Fr\.?)\s*([\d\'\s]+[.,]\d{2})/',
        // EUR/€ 1234,56
        '/(EUR|€)\s*([\d\s]+[.,]\d{2})/',
        // USD/$ 1,234.56
        '/(USD|\$)\s*([\d,\s]+\.\d{2})/',
        // Montant générique avec devise après
        '/([\d\'\s]+[.,]\d{2})\s*(CHF|EUR|USD|Fr\.?)/',
    ];
    
    private array $emailPattern = '/[\w\.-]+@[\w\.-]+\.\w{2,}/';
    
    private array $frenchMonths = [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12
    ];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Classifier un document automatiquement
     */
    public function classify(int $documentId): array
    {
        $doc = $this->getDocument($documentId);
        if (!$doc) {
            return ['error' => 'Document non trouvé'];
        }
        
        // Texte à analyser
        $text = implode(' ', array_filter([
            $doc['title'] ?? '',
            $doc['content'] ?? '',
            $doc['ocr_text'] ?? '',
            $doc['original_filename'] ?? ''
        ]));
        
        $results = [
            'correspondent_id' => null,
            'correspondent_name' => null,
            'document_type_id' => null,
            'document_type_name' => null,
            'tag_ids' => [],
            'tag_names' => [],
            'document_date' => null,
            'amount' => null,
            'currency' => null,
            'emails_found' => [],
            'confidence' => 'rules' // Indique que c'est basé sur des règles, pas IA
        ];
        
        // 1. Extraire les données automatiques
        $results['document_date'] = $this->extractDate($text);
        $amount = $this->extractAmount($text);
        if ($amount) {
            $results['amount'] = $amount['value'];
            $results['currency'] = $amount['currency'];
        }
        $results['emails_found'] = $this->extractEmails($text);
        
        // 2. Matcher les correspondants
        $correspondent = $this->matchCorrespondent($text, $results['emails_found']);
        if ($correspondent) {
            $results['correspondent_id'] = $correspondent['id'];
            $results['correspondent_name'] = $correspondent['name'];
        }
        
        // 3. Matcher le type de document
        $docType = $this->matchDocumentType($text, $doc['consume_subfolder'] ?? null);
        if ($docType) {
            $results['document_type_id'] = $docType['id'];
            $results['document_type_name'] = $docType['label'];
        }
        
        // 4. Matcher les tags
        $tags = $this->matchTags($text);
        $results['tag_ids'] = array_column($tags, 'id');
        $results['tag_names'] = array_column($tags, 'name');
        
        return $results;
    }
    
    /**
     * Extraire la date du texte
     */
    public function extractDate(string $text): ?string
    {
        foreach ($this->datePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return $this->parseDate($matches);
            }
        }
        return null;
    }
    
    private function parseDate(array $matches): ?string
    {
        try {
            // Format DD/MM/YYYY
            if (isset($matches[3]) && strlen($matches[3]) === 4 && is_numeric($matches[1]) && is_numeric($matches[2])) {
                $day = (int)$matches[1];
                $month = (int)$matches[2];
                $year = (int)$matches[3];
                
                // Vérifier si c'est plutôt MM/DD/YYYY (peu probable en Europe)
                if ($day > 12 && $month <= 12) {
                    // C'est bien DD/MM/YYYY
                } elseif ($month > 12 && $day <= 12) {
                    // C'est MM/DD/YYYY, inverser
                    list($day, $month) = [$month, $day];
                }
                
                if (checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
            
            // Format YYYY-MM-DD (ISO)
            if (isset($matches[1]) && strlen($matches[1]) === 4 && is_numeric($matches[1])) {
                $year = (int)$matches[1];
                $month = (int)$matches[2];
                $day = (int)$matches[3];
                if (checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
            
            // Format "15 janvier 2024"
            if (isset($matches[2]) && !is_numeric($matches[2])) {
                $day = (int)$matches[1];
                $monthName = strtolower($matches[2]);
                $year = (int)$matches[3];
                
                $month = $this->frenchMonths[$monthName] ?? null;
                if ($month && checkdate($month, $day, $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs de parsing
        }
        
        return null;
    }
    
    /**
     * Extraire le montant du texte
     */
    public function extractAmount(string $text): ?array
    {
        foreach ($this->amountPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $currency = $this->normalizeCurrency($matches[1] ?? $matches[3] ?? 'CHF');
                $value = $matches[2] ?? $matches[1];
                
                // Nettoyer le montant
                $value = preg_replace("/['\s]/", '', $value); // Supprimer apostrophes et espaces
                $value = str_replace(',', '.', $value); // Virgule → point
                $value = (float)$value;
                
                if ($value > 0) {
                    return ['value' => $value, 'currency' => $currency];
                }
            }
        }
        return null;
    }
    
    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $map = [
            '€' => 'EUR',
            '$' => 'USD',
            'FR.' => 'CHF',
            'FR' => 'CHF',
        ];
        return $map[$currency] ?? $currency;
    }
    
    /**
     * Extraire les emails du texte
     */
    public function extractEmails(string $text): array
    {
        preg_match_all($this->emailPattern, $text, $matches);
        return array_unique($matches[0] ?? []);
    }
    
    /**
     * Matcher un correspondant par règles
     */
    public function matchCorrespondent(string $text, array $emails = []): ?array
    {
        // 1. D'abord essayer par email
        if (!empty($emails)) {
            foreach ($emails as $email) {
                $stmt = $this->db->prepare("
                    SELECT id, name FROM correspondents 
                    WHERE email = ? OR matching_keywords LIKE ?
                    LIMIT 1
                ");
                $stmt->execute([$email, '%' . $email . '%']);
                $match = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($match) return $match;
            }
        }
        
        // 2. Ensuite par mots-clés
        $correspondents = $this->db->query("
            SELECT id, name, matching_algorithm, matching_keywords, is_insensitive 
            FROM correspondents 
            WHERE matching_keywords IS NOT NULL AND matching_keywords != ''
        ")->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($correspondents as $corr) {
            if ($this->matchesKeywords($text, $corr)) {
                return ['id' => $corr['id'], 'name' => $corr['name']];
            }
        }
        
        return null;
    }
    
    /**
     * Matcher le type de document
     */
    public function matchDocumentType(string $text, ?string $consumeSubfolder = null): ?array
    {
        // 1. D'abord par sous-dossier consume
        if ($consumeSubfolder) {
            $stmt = $this->db->prepare("
                SELECT id, label FROM document_types 
                WHERE consume_subfolder = ?
                LIMIT 1
            ");
            $stmt->execute([$consumeSubfolder]);
            $match = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($match) return $match;
        }
        
        // 2. Par mots-clés
        $types = $this->db->query("
            SELECT id, label, matching_algorithm, matching_keywords, is_insensitive 
            FROM document_types 
            WHERE matching_keywords IS NOT NULL AND matching_keywords != ''
        ")->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($types as $type) {
            if ($this->matchesKeywords($text, $type)) {
                return ['id' => $type['id'], 'label' => $type['label']];
            }
        }
        
        return null;
    }
    
    /**
     * Matcher les tags
     */
    public function matchTags(string $text): array
    {
        $matchedTags = [];
        
        $tags = $this->db->query("
            SELECT id, name, matching_algorithm, matching_keywords, is_insensitive 
            FROM tags 
            WHERE matching_keywords IS NOT NULL AND matching_keywords != ''
        ")->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($tags as $tag) {
            if ($this->matchesKeywords($text, $tag)) {
                $matchedTags[] = ['id' => $tag['id'], 'name' => $tag['name']];
            }
        }
        
        return $matchedTags;
    }
    
    /**
     * Vérifie si le texte matche selon l'algorithme
     */
    private function matchesKeywords(string $text, array $entity): bool
    {
        $keywords = $entity['matching_keywords'] ?? '';
        if (empty($keywords)) return false;
        
        $algorithm = $entity['matching_algorithm'] ?? 'any';
        $insensitive = $entity['is_insensitive'] ?? true;
        
        if ($insensitive) {
            $text = mb_strtolower($text);
            $keywords = mb_strtolower($keywords);
        }
        
        // Séparer les mots-clés (par virgule ou nouvelle ligne)
        $keywordList = preg_split('/[,\n]+/', $keywords);
        $keywordList = array_map('trim', $keywordList);
        $keywordList = array_filter($keywordList);
        
        if (empty($keywordList)) return false;
        
        switch ($algorithm) {
            case 'any':
                // Un seul mot-clé suffit
                foreach ($keywordList as $kw) {
                    if (mb_strpos($text, $kw) !== false) {
                        return true;
                    }
                }
                return false;
                
            case 'all':
                // Tous les mots-clés requis
                foreach ($keywordList as $kw) {
                    if (mb_strpos($text, $kw) === false) {
                        return false;
                    }
                }
                return true;
                
            case 'literal':
                // Le premier "mot-clé" est une phrase exacte
                $phrase = $keywordList[0];
                return mb_strpos($text, $phrase) !== false;
                
            case 'regex':
                // Le premier "mot-clé" est une regex
                $pattern = $keywordList[0];
                if (@preg_match($pattern, '') === false) {
                    // Pattern invalide, essayer de l'entourer de délimiteurs
                    $pattern = '/' . $pattern . '/';
                    if ($insensitive) $pattern .= 'i';
                }
                return (bool)preg_match($pattern, $text);
                
            case 'fuzzy':
                // Correspondance approximative (Levenshtein)
                $words = preg_split('/\s+/', $text);
                foreach ($keywordList as $kw) {
                    foreach ($words as $word) {
                        $distance = levenshtein($kw, $word);
                        $threshold = max(1, strlen($kw) / 4); // 25% de tolérance
                        if ($distance <= $threshold) {
                            return true;
                        }
                    }
                }
                return false;
                
            default:
                return false;
        }
    }
    
    /**
     * Appliquer les suggestions à un document
     */
    public function applyClassification(int $documentId, array $classification): bool
    {
        $updates = [];
        $params = [];
        
        if (!empty($classification['correspondent_id'])) {
            $updates[] = 'correspondent_id = ?';
            $params[] = $classification['correspondent_id'];
        }
        
        if (!empty($classification['document_type_id'])) {
            $updates[] = 'document_type_id = ?';
            $params[] = $classification['document_type_id'];
        }
        
        if (!empty($classification['document_date'])) {
            $updates[] = 'document_date = ?';
            $params[] = $classification['document_date'];
        }
        
        if (!empty($classification['amount'])) {
            $updates[] = 'amount = ?';
            $params[] = $classification['amount'];
        }
        
        if (!empty($classification['currency'])) {
            $updates[] = 'currency = ?';
            $params[] = $classification['currency'];
        }
        
        if (!empty($updates)) {
            $params[] = $documentId;
            $sql = "UPDATE documents SET " . implode(', ', $updates) . " WHERE id = ?";
            $this->db->prepare($sql)->execute($params);
        }
        
        // Ajouter les tags
        if (!empty($classification['tag_ids'])) {
            foreach ($classification['tag_ids'] as $tagId) {
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                    ->execute([$documentId, $tagId]);
            }
        }
        
        return true;
    }
    
    private function getDocument(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
```

---

### 3. ConsumeFolderService.php simplifié

**Fichier** : `app/Services/ConsumeFolderService.php`

```php
<?php
namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;

class ConsumeFolderService
{
    private string $consumePath;
    private string $processedPath;
    private string $documentsPath;
    private $db;
    
    public function __construct()
    {
        $basePath = dirname(__DIR__, 2) . '/storage';
        $config = Config::load();
        
        $this->consumePath = $config['storage']['consume'] ?? $basePath . '/consume';
        $this->processedPath = $config['storage']['processed'] ?? $basePath . '/processed';
        $this->documentsPath = $config['storage']['documents'] ?? $basePath . '/documents';
        $this->db = Database::getInstance();
        
        // Créer les dossiers
        foreach ([$this->consumePath, $this->processedPath, $this->documentsPath] as $path) {
            if (!is_dir($path)) @mkdir($path, 0755, true);
        }
    }
    
    /**
     * Scanner et importer les fichiers
     */
    public function scan(): array
    {
        $results = [
            'scanned' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
            'documents' => []
        ];
        
        if (!is_dir($this->consumePath)) {
            $results['errors'][] = "Dossier inexistant: {$this->consumePath}";
            return $results;
        }
        
        // Scanner récursivement (pour les sous-dossiers = types)
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->consumePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'gif', 'webp'];
        
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            
            $results['scanned']++;
            $filePath = $file->getPathname();
            $ext = strtolower($file->getExtension());
            
            if (!in_array($ext, $allowedExt)) {
                $results['skipped']++;
                continue;
            }
            
            // Checksum pour éviter les doublons
            $checksum = md5_file($filePath);
            $stmt = $this->db->prepare("SELECT id FROM documents WHERE checksum = ?");
            $stmt->execute([$checksum]);
            if ($stmt->fetch()) {
                $results['skipped']++;
                $this->moveToProcessed($filePath);
                continue;
            }
            
            try {
                // Déterminer le sous-dossier (pour type auto)
                $relativePath = str_replace($this->consumePath, '', dirname($filePath));
                $subfolder = trim($relativePath, '/\\') ?: null;
                
                $docResult = $this->importFile($filePath, $subfolder);
                $results['imported']++;
                $results['documents'][] = $docResult;
            } catch (\Exception $e) {
                $results['errors'][] = basename($filePath) . ": " . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    private function importFile(string $filePath, ?string $subfolder = null): array
    {
        $filename = basename($filePath);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $checksum = md5_file($filePath);
        $fileSize = filesize($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        
        // Nom unique
        $uniqueName = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        $destPath = $this->documentsPath . '/' . $uniqueName;
        
        if (!copy($filePath, $destPath)) {
            throw new \Exception("Copie impossible");
        }
        
        // Créer en base
        $stmt = $this->db->prepare("
            INSERT INTO documents (
                title, filename, original_filename, file_path,
                file_size, mime_type, checksum, status, consume_subfolder,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
        ");
        
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $stmt->execute([
            $title, $uniqueName, $filename, $destPath,
            $fileSize, $mimeType, $checksum, $subfolder
        ]);
        
        $documentId = $this->db->lastInsertId();
        
        // Déplacer vers processed
        $this->moveToProcessed($filePath);
        
        // Traitement : OCR + Classification auto
        $result = ['id' => $documentId, 'filename' => $filename, 'status' => 'imported'];
        
        try {
            // 1. OCR
            $processor = new DocumentProcessor();
            $processor->process($documentId);
            
            // 2. Classification par règles (SANS IA)
            $classifier = new AutoClassifierService();
            $classification = $classifier->classify($documentId);
            
            if ($classification && empty($classification['error'])) {
                // Sauvegarder les suggestions pour validation
                $this->db->prepare("UPDATE documents SET ai_suggestions = ? WHERE id = ?")
                    ->execute([json_encode($classification), $documentId]);
                
                // Auto-appliquer si confiance élevée ? Ou laisser pour validation
                // Pour l'instant on garde pour validation manuelle
                $result['suggestions'] = $classification;
                $result['status'] = 'needs_review';
            } else {
                $result['status'] = 'processed';
            }
            
            // 3. BONUS : Classification IA si Claude configuré
            try {
                $aiClassifier = new AIClassifierService();
                if ($aiClassifier->isAvailable()) {
                    $aiSuggestions = $aiClassifier->classify($documentId);
                    if ($aiSuggestions) {
                        $result['ai_suggestions'] = $aiSuggestions;
                        $result['status'] = 'needs_review';
                    }
                }
            } catch (\Exception $e) {
                // IA non disponible, pas grave
            }
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    private function moveToProcessed(string $filePath): bool
    {
        $dest = $this->processedPath . '/' . date('Ymd_His') . '_' . basename($filePath);
        return @rename($filePath, $dest);
    }
    
    public function getPendingDocuments(): array
    {
        return $this->db->query("
            SELECT d.*, c.name as correspondent_name, dt.label as document_type_name
            FROM documents d
            LEFT JOIN correspondents c ON d.correspondent_id = c.id
            LEFT JOIN document_types dt ON d.document_type_id = dt.id
            WHERE d.status IN ('pending', 'needs_review')
            ORDER BY d.created_at DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public function validateDocument(int $documentId, array $data): bool
    {
        $updates = ['status' => 'validated'];
        $params = [];
        
        foreach (['title', 'correspondent_id', 'document_type_id', 'document_date', 'amount'] as $field) {
            if (isset($data[$field])) {
                $updates[$field] = $data[$field] ?: null;
            }
        }
        
        $setClauses = [];
        foreach ($updates as $col => $val) {
            $setClauses[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $documentId;
        
        $this->db->prepare("UPDATE documents SET " . implode(', ', $setClauses) . " WHERE id = ?")
            ->execute($params);
        
        // Tags
        if (isset($data['tags']) && is_array($data['tags'])) {
            $this->db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$documentId]);
            foreach ($data['tags'] as $tagId) {
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")
                    ->execute([$documentId, $tagId]);
            }
        }
        
        return true;
    }
}
```

---

### 4. Migration SQL

**Fichier** : `migrations/add_matching_columns.sql`

```sql
-- Colonnes de matching pour correspondants
ALTER TABLE correspondents 
ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any',
ADD COLUMN IF NOT EXISTS matching_keywords TEXT,
ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

-- Colonnes de matching pour tags
ALTER TABLE tags 
ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any',
ADD COLUMN IF NOT EXISTS matching_keywords TEXT,
ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE;

-- Colonnes de matching pour types de documents
ALTER TABLE document_types 
ADD COLUMN IF NOT EXISTS matching_algorithm VARCHAR(20) DEFAULT 'any',
ADD COLUMN IF NOT EXISTS matching_keywords TEXT,
ADD COLUMN IF NOT EXISTS is_insensitive BOOLEAN DEFAULT TRUE,
ADD COLUMN IF NOT EXISTS consume_subfolder VARCHAR(100);

-- Colonne pour stocker les suggestions
ALTER TABLE documents
ADD COLUMN IF NOT EXISTS ai_suggestions JSON,
ADD COLUMN IF NOT EXISTS consume_subfolder VARCHAR(100);

-- Exemples de règles de matching
UPDATE correspondents SET matching_keywords = 'swisscom, swisscom.ch' WHERE name LIKE '%Swisscom%';
UPDATE correspondents SET matching_keywords = 'sunrise, sunrise.ch' WHERE name LIKE '%Sunrise%';
UPDATE document_types SET matching_keywords = 'facture, invoice, rechnung, montant dû' WHERE code = 'invoice';
UPDATE document_types SET matching_keywords = 'contrat, contract, convention' WHERE code = 'contract';
UPDATE tags SET matching_keywords = 'urgent, priorité, asap' WHERE name = 'Urgent';
```

---

## 📋 RÉSUMÉ

| Composant | Sans IA | Avec Claude |
|-----------|---------|-------------|
| OCR | ✅ pdftotext/Tesseract | ✅ Idem |
| Date | ✅ Patterns regex | ✅ + IA |
| Montant | ✅ Patterns regex | ✅ + IA |
| Correspondant | ✅ Mots-clés | ✅ + IA |
| Type | ✅ Sous-dossier + mots-clés | ✅ + IA |
| Tags | ✅ Mots-clés | ✅ + IA |
| Split PDF | ❌ Non | ✅ Oui |

---

## 🚀 COMMANDE CURSOR

```
Lis docs/CURSOR_CONSUME_FOLDER.md et implémente :

1. La migration SQL pour les colonnes matching
2. AutoClassifierService.php (classification par règles)
3. ConsumeFolderService.php (scan + import)
4. ConsumeController.php + template
5. Les routes dans index.php

Teste avec storage/consume/Courrier au Tribunal civil - envoyé.pdf
```
