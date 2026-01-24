<?php
/**
 * Classification automatique par règles (SANS IA)
 * Patterns regex + mots-clés comme Paperless-ngx
 */

namespace KDocs\Services;

use KDocs\Core\Database;

class AutoClassifierService
{
    private $db;
    
    private array $datePatterns = [
        '/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})/',
        '/(\d{4})-(\d{2})-(\d{2})/',
        '/(\d{1,2})\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+(\d{4})/iu',
    ];
    
    private array $amountPatterns = [
        '/(CHF|Fr\.?)\s*([\d\'\s]+[.,]\d{2})/i',
        '/(EUR|€)\s*([\d\s]+[.,]\d{2})/i',
        '/(USD|\$)\s*([\d,\s]+\.\d{2})/i',
    ];
    
    private array $frenchMonths = [
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12
    ];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    public function classify(int $documentId): array
    {
        $doc = $this->getDocument($documentId);
        if (!$doc) return ['error' => 'Document non trouvé'];
        
        // Construire le texte à analyser (priorité au contenu OCR)
        $textParts = [];
        if (!empty($doc['content'])) $textParts[] = $doc['content'];
        if (!empty($doc['ocr_text'])) $textParts[] = $doc['ocr_text'];
        if (!empty($doc['title'])) $textParts[] = $doc['title'];
        if (!empty($doc['original_filename'])) $textParts[] = $doc['original_filename'];
        
        $text = implode(' ', $textParts);
        
        // Normaliser le texte pour améliorer le matching
        $text = mb_strtolower($text);
        
        $results = [
            'method' => 'rules',
            'correspondent_id' => null,
            'correspondent_name' => null,
            'document_type_id' => null,
            'document_type_name' => null,
            'tag_ids' => [],
            'tag_names' => [],
            'doc_date' => null,
            'amount' => null,
            'currency' => null,
            'confidence' => 0,
        ];
        
        // Extractions automatiques
        $results['doc_date'] = $this->extractDate($text);
        $amount = $this->extractAmount($text);
        if ($amount) {
            $results['amount'] = $amount['value'];
            $results['currency'] = $amount['currency'];
        }
        
        $emails = $this->extractEmails($text);
        
        // Essayer d'abord avec l'IA pour les champs configurés
        $fieldAI = null;
        try {
            $fieldAI = new \KDocs\Services\FieldAIClassifierService();
            if ($fieldAI->isAvailable()) {
                $aiResults = $fieldAI->classifyAllFields($documentId);
                
                // Utiliser les résultats IA pour les champs correspondants
                if (!empty($aiResults['supplier']) || !empty($aiResults['correspondent'])) {
                    $supplierName = $aiResults['supplier'] ?? $aiResults['correspondent'] ?? null;
                    if ($supplierName) {
                        $corr = $this->findCorrespondentByName($supplierName);
                        if ($corr) {
                            $results['correspondent_id'] = $corr['id'];
                            $results['correspondent_name'] = $corr['name'];
                            $results['method'] = 'ai_field';
                        }
                    }
                }
                
                if (!empty($aiResults['type'])) {
                    $typeName = $aiResults['type'];
                    $type = $this->findDocumentTypeByName($typeName);
                    if ($type) {
                        $results['document_type_id'] = $type['id'];
                        $results['document_type_name'] = $type['label'];
                        $results['method'] = 'ai_field';
                    }
                }
                
                if (!empty($aiResults['year'])) {
                    $year = $aiResults['year'];
                    // Si on a déjà une date, vérifier que l'année correspond
                    if ($results['doc_date']) {
                        $dateYear = date('Y', strtotime($results['doc_date']));
                        if ($dateYear != $year) {
                            // Utiliser l'année de l'IA pour créer une date si nécessaire
                        }
                    }
                }
                
                if (!empty($aiResults['date'])) {
                    $aiDate = $aiResults['date'];
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $aiDate)) {
                        $results['doc_date'] = $aiDate;
                        $results['method'] = 'ai_field';
                    }
                }
                
                if (!empty($aiResults['amount'])) {
                    $amount = floatval($aiResults['amount']);
                    if ($amount > 0) {
                        $results['amount'] = $amount;
                        $results['method'] = 'ai_field';
                    }
                }
            }
        } catch (\Exception $e) {
            // Si l'IA échoue, continuer avec les règles
            error_log("AutoClassifierService: IA field classification failed: " . $e->getMessage());
        }
        
        // Matching par règles (si pas déjà trouvé par IA)
        if (empty($results['correspondent_id'])) {
            $corr = $this->matchCorrespondent($text, $emails);
            if ($corr) {
                $results['correspondent_id'] = $corr['id'];
                $results['correspondent_name'] = $corr['name'];
            }
        }
        
        if (empty($results['document_type_id'])) {
            $type = $this->matchDocumentType($text, $doc['consume_subfolder'] ?? null);
            if ($type) {
                $results['document_type_id'] = $type['id'];
                $results['document_type_name'] = $type['label'];
            }
        }
        
        $tags = $this->matchTags($text);
        $results['tag_ids'] = array_column($tags, 'id');
        $results['tag_names'] = array_column($tags, 'name');
        
        // Calcul confiance
        $results['confidence'] = $this->calculateConfidence($results);
        
        return $results;
    }
    
    public function extractDate(string $text): ?string
    {
        foreach ($this->datePatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->parseDate($m);
            }
        }
        return null;
    }
    
    private function parseDate(array $m): ?string
    {
        try {
            // DD/MM/YYYY
            if (isset($m[3]) && strlen($m[3]) === 4 && is_numeric($m[1])) {
                $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
                if ($d > 12 && $mo <= 12) { /* OK */ }
                elseif ($mo > 12 && $d <= 12) { list($d, $mo) = [$mo, $d]; }
                if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
            // Mois en lettres
            if (isset($m[2]) && !is_numeric($m[2])) {
                $d = (int)$m[1];
                $mo = $this->frenchMonths[mb_strtolower($m[2])] ?? null;
                $y = (int)$m[3];
                if ($mo && checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        } catch (\Exception $e) {}
        return null;
    }
    
    public function extractAmount(string $text): ?array
    {
        foreach ($this->amountPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $currency = strtoupper(trim($m[1]));
                $currency = str_replace(['€', '$', 'FR.', 'FR'], ['EUR', 'USD', 'CHF', 'CHF'], $currency);
                $value = preg_replace("/['\s]/", '', $m[2]);
                $value = (float)str_replace(',', '.', $value);
                if ($value > 0) return ['value' => $value, 'currency' => $currency];
            }
        }
        return null;
    }
    
    public function extractEmails(string $text): array
    {
        preg_match_all('/[\w\.-]+@[\w\.-]+\.\w{2,}/', $text, $m);
        return array_unique($m[0] ?? []);
    }
    
    public function matchCorrespondent(string $text, array $emails = []): ?array
    {
        // Par email
        foreach ($emails as $email) {
            $stmt = $this->db->prepare("SELECT id, name FROM correspondents WHERE email = ? OR matching_keywords LIKE ? LIMIT 1");
            $stmt->execute([$email, '%' . $email . '%']);
            if ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) return $r;
        }
        
        // Par nom exact dans le texte (amélioration)
        $rows = $this->db->query("SELECT id, name FROM correspondents")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $name = mb_strtolower($row['name']);
            // Vérifier si le nom apparaît dans le texte (au moins 3 caractères)
            if (mb_strlen($name) >= 3 && mb_strpos($text, $name) !== false) {
                return ['id' => $row['id'], 'name' => $row['name']];
            }
            
            // Vérifier aussi les mots-clés du nom (ex: "tribunal civil" -> "tribunal" ou "civil")
            $nameWords = preg_split('/\s+/', $name);
            if (count($nameWords) >= 2) {
                $foundWords = 0;
                foreach ($nameWords as $word) {
                    if (mb_strlen($word) >= 4 && mb_strpos($text, $word) !== false) {
                        $foundWords++;
                    }
                }
                // Si au moins 2 mots du nom sont trouvés, considérer comme match
                if ($foundWords >= 2) {
                    return ['id' => $row['id'], 'name' => $row['name']];
                }
            }
        }
        
        // Par mots-clés
        $rows = $this->db->query("SELECT id, name, matching_algorithm, matching_keywords, is_insensitive FROM correspondents WHERE matching_keywords IS NOT NULL AND matching_keywords != ''")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($this->matchesKeywords($text, $row)) {
                return ['id' => $row['id'], 'name' => $row['name']];
            }
        }
        return null;
    }
    
    public function matchDocumentType(string $text, ?string $subfolder = null): ?array
    {
        // Par sous-dossier consume
        if ($subfolder) {
            $stmt = $this->db->prepare("SELECT id, label FROM document_types WHERE consume_subfolder = ? LIMIT 1");
            $stmt->execute([$subfolder]);
            if ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) return $r;
        }
        
        // Par nom exact dans le texte (amélioration)
        $rows = $this->db->query("SELECT id, label FROM document_types")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $label = mb_strtolower($row['label']);
            // Vérifier si le label apparaît dans le texte
            if (mb_strpos($text, $label) !== false) {
                return ['id' => $row['id'], 'label' => $row['label']];
            }
        }
        
        // Par mots-clés
        $rows = $this->db->query("SELECT id, label, matching_algorithm, matching_keywords, is_insensitive FROM document_types WHERE matching_keywords IS NOT NULL AND matching_keywords != ''")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($this->matchesKeywords($text, $row)) {
                return ['id' => $row['id'], 'label' => $row['label']];
            }
        }
        
        // Détection par patterns communs
        $patterns = [
            'facture|invoice|rechnung' => 'Facture',
            'note.*crédit|credit.*note' => 'Note de crédit',
            'contrat|contract' => 'Contrat',
            'courrier|lettre|letter' => 'Courrier',
            'reçu|receipt' => 'Reçu',
        ];
        
        foreach ($patterns as $pattern => $typeLabel) {
            if (preg_match('/' . $pattern . '/iu', $text)) {
                $stmt = $this->db->prepare("SELECT id, label FROM document_types WHERE label LIKE ? LIMIT 1");
                $stmt->execute(['%' . $typeLabel . '%']);
                if ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    return $r;
                }
            }
        }
        
        return null;
    }
    
    public function matchTags(string $text): array
    {
        $matched = [];
        $rows = $this->db->query("SELECT id, name, matching_algorithm, matching_keywords, is_insensitive FROM tags WHERE matching_keywords IS NOT NULL AND matching_keywords != ''")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($this->matchesKeywords($text, $row)) {
                $matched[] = ['id' => $row['id'], 'name' => $row['name']];
            }
        }
        return $matched;
    }
    
    private function matchesKeywords(string $text, array $entity): bool
    {
        $keywords = $entity['matching_keywords'] ?? '';
        if (empty($keywords)) return false;
        
        $algo = $entity['matching_algorithm'] ?? 'any';
        $insensitive = $entity['is_insensitive'] ?? true;
        
        if ($insensitive) {
            $text = mb_strtolower($text);
            $keywords = mb_strtolower($keywords);
        }
        
        $list = array_filter(array_map('trim', preg_split('/[,\n]+/', $keywords)));
        if (empty($list)) return false;
        
        switch ($algo) {
            case 'any':
                foreach ($list as $kw) {
                    if (mb_strpos($text, $kw) !== false) return true;
                }
                return false;
            case 'all':
                foreach ($list as $kw) {
                    if (mb_strpos($text, $kw) === false) return false;
                }
                return true;
            case 'literal':
                return mb_strpos($text, $list[0]) !== false;
            case 'regex':
                $p = $list[0];
                if (@preg_match($p, '') === false) $p = '/' . $p . '/' . ($insensitive ? 'i' : '');
                return (bool)@preg_match($p, $text);
            case 'fuzzy':
                foreach ($list as $kw) {
                    foreach (preg_split('/\s+/', $text) as $word) {
                        if (levenshtein($kw, $word) <= max(1, strlen($kw) / 4)) return true;
                    }
                }
                return false;
        }
        return false;
    }
    
    private function calculateConfidence(array $r): float
    {
        $score = 0;
        if (!empty($r['correspondent_id'])) $score++;
        if (!empty($r['document_type_id'])) $score++;
        if (!empty($r['doc_date'])) $score++;
        if (!empty($r['amount'])) $score++;
        if (!empty($r['tag_ids'])) $score++;
        return $score / 5;
    }
    
    public function applyClassification(int $documentId, array $c): bool
    {
        $sets = []; $params = [];
        // Mapper les champs pour correspondre à la structure DB
        $fieldMapping = [
            'correspondent_id' => 'correspondent_id',
            'document_type_id' => 'document_type_id',
            'doc_date' => 'doc_date',
            'amount' => 'amount',
            'currency' => 'currency'
        ];
        
        foreach ($fieldMapping as $key => $dbField) {
            if (isset($c[$key]) && $c[$key] !== null && $c[$key] !== '') {
                $sets[] = "$dbField = ?";
                $params[] = $c[$key];
            }
        }
        
        if ($sets) {
            $params[] = $documentId;
            $this->db->prepare("UPDATE documents SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }
        
        if (!empty($c['tag_ids']) && is_array($c['tag_ids'])) {
            foreach ($c['tag_ids'] as $tid) {
                $this->db->prepare("INSERT IGNORE INTO document_tags (document_id, tag_id) VALUES (?, ?)")->execute([$documentId, $tid]);
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
    
    /**
     * Trouve un correspondant par nom (recherche exacte ou partielle)
     */
    private function findCorrespondentByName(string $name): ?array
    {
        $nameLower = mb_strtolower(trim($name));
        $rows = $this->db->query("SELECT id, name FROM correspondents")->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($rows as $row) {
            $rowNameLower = mb_strtolower($row['name']);
            // Match exact
            if ($rowNameLower === $nameLower) {
                return $row;
            }
            // Match partiel (le nom recherché contient le nom de la base ou vice versa)
            if (mb_strpos($rowNameLower, $nameLower) !== false || mb_strpos($nameLower, $rowNameLower) !== false) {
                return $row;
            }
        }
        
        return null;
    }
    
    /**
     * Trouve un type de document par nom (recherche exacte ou partielle)
     */
    private function findDocumentTypeByName(string $name): ?array
    {
        $nameLower = mb_strtolower(trim($name));
        $rows = $this->db->query("SELECT id, label FROM document_types")->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($rows as $row) {
            $rowLabelLower = mb_strtolower($row['label']);
            // Match exact
            if ($rowLabelLower === $nameLower) {
                return ['id' => $row['id'], 'label' => $row['label']];
            }
            // Match partiel
            if (mb_strpos($rowLabelLower, $nameLower) !== false || mb_strpos($nameLower, $rowLabelLower) !== false) {
                return ['id' => $row['id'], 'label' => $row['label']];
            }
        }
        
        return null;
    }
}
