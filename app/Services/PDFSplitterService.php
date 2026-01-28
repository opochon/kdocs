<?php
/**
 * K-Docs - PDFSplitterService
 * Service pour séparer les PDFs multi-pages en documents distincts
 * Basé sur l'analyse IA du contenu de chaque page
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\ClaudeService;
use KDocs\Helpers\SystemHelper;

class PDFSplitterService
{
    private $db;
    private $tempDir;
    private $documentsPath;
    private $claude;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $config = Config::load();
        $this->tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../storage/temp';
        $this->documentsPath = $config['storage']['documents'] ?? __DIR__ . '/../../storage/documents';
        $this->claude = new ClaudeService();
        
        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0755, true);
        }
    }
    
    /**
     * Analyse un PDF multi-pages et le sépare si nécessaire
     * 
     * @param int $documentId ID du document à analyser
     * @return array Résultat avec les documents créés ou null si pas de séparation nécessaire
     */
    public function analyzeAndSplit(int $documentId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$document) {
            throw new \Exception("Document introuvable: {$documentId}");
        }
        
        // Vérifier que c'est un PDF
        if ($document['mime_type'] !== 'application/pdf') {
            return null;
        }
        
        $filePath = $document['file_path'];
        if (!file_exists($filePath)) {
            // Construire le chemin complet
            $filePath = $this->documentsPath . '/' . basename($filePath);
            if (!file_exists($filePath)) {
                throw new \Exception("Fichier PDF introuvable pour document {$documentId}");
            }
        }
        
        // Compter les pages du PDF
        $numPages = $this->countPDFPages($filePath);
        if ($numPages <= 1) {
            return null; // Pas de séparation nécessaire
        }
        
        // Vérifier si le mode AI est activé pour la séparation
        $config = Config::load();
        $splitEnabled = $config['classification']['ai_split_enabled'] ?? false;
        if (!$splitEnabled) {
            return null;
        }
        
        // Vérifier que Claude est configuré
        if (!$this->claude->isConfigured()) {
            error_log("PDFSplitterService: Claude non configuré, séparation désactivée");
            return null;
        }
        
        error_log("PDFSplitterService: PDF multi-pages détecté ({$numPages} pages) pour document {$documentId}");
        
        // Analyser chaque page
        try {
            $pageAnalyses = $this->analyzePages($filePath, $documentId, $numPages);
        } catch (\Exception $e) {
            // Si l'analyse échoue complètement (API non disponible, timeout, etc.), continuer le traitement normal
            error_log("PDFSplitterService: Erreur lors de l'analyse des pages pour document {$documentId}: " . $e->getMessage());
            return null; // Retourner null pour continuer le traitement normal du document
        }
        
        if (empty($pageAnalyses) || count($pageAnalyses) <= 1) {
            // Pas assez d'analyses réussies (API non disponible ou pages non pertinentes)
            // Le document sera traité normalement sans séparation
            error_log("PDFSplitterService: Pas assez d'analyses réussies pour document {$documentId} (" . count($pageAnalyses) . " analyses), traitement normal");
            return null;
        }
        
        // Grouper les pages par document
        $pageGroups = $this->groupPagesByDocument($pageAnalyses);
        
        if (count($pageGroups) <= 1) {
            return null; // Toutes les pages appartiennent au même document
        }
        
        error_log("PDFSplitterService: Détection de " . count($pageGroups) . " document(s) distinct(s)");
        
        // Séparer le PDF
        $splitFiles = $this->splitPDF($filePath, $pageGroups, $document['original_filename']);
        
        if (empty($splitFiles)) {
            error_log("PDFSplitterService: Erreur lors de la séparation du PDF");
            return null;
        }
        
        // Créer les nouveaux documents pour chaque partie
        $createdDocuments = [];
        foreach ($splitFiles as $groupIdx => $splitFile) {
            $pageGroup = $pageGroups[$groupIdx];
            $firstPageNum = $pageGroup[0];
            $analysis = $pageAnalyses[$firstPageNum] ?? null;
            
            $newDocId = $this->createDocumentFromSplit(
                $splitFile,
                $document,
                $pageGroup,
                $analysis,
                $documentId // parent_id
            );
            
            if ($newDocId) {
                $createdDocuments[] = [
                    'id' => $newDocId,
                    'pages' => $pageGroup,
                    'analysis' => $analysis
                ];
            }
        }
        
        // Marquer le document original comme "split"
        $this->db->prepare("UPDATE documents SET status = 'split', split_into_count = ? WHERE id = ?")
            ->execute([count($createdDocuments), $documentId]);
        
        return [
            'parent_id' => $documentId,
            'split_count' => count($createdDocuments),
            'documents' => $createdDocuments
        ];
    }
    
    /**
     * Compte le nombre de pages d'un PDF
     */
    private function countPDFPages(string $filePath): int
    {
        // Utiliser pdftk ou pdftotext pour compter les pages
        $pdfCmd = escapeshellarg($filePath);
        
        // Méthode 1: pdftk (si disponible)
        if (SystemHelper::commandExists('pdftk')) {
            exec("pdftk $pdfCmd dump_data 2>&1", $output, $returnCode);
            if ($returnCode === 0) {
                foreach ($output as $line) {
                    if (preg_match('/NumberOfPages:\s*(\d+)/', $line, $matches)) {
                        return (int)$matches[1];
                    }
                }
            }
        }
        
        // Méthode 2: Utiliser Python avec PyPDF2 ou pdfplumber (si disponible)
        $pythonScript = $this->tempDir . '/count_pages.py';
        $scriptContent = <<<'PYTHON'
import sys
try:
    import pdfplumber
    with pdfplumber.open(sys.argv[1]) as pdf:
        print(len(pdf.pages))
except:
    try:
        import PyPDF2
        with open(sys.argv[1], 'rb') as f:
            pdf = PyPDF2.PdfReader(f)
            print(len(pdf.pages))
    except:
        print("0")
PYTHON;
        file_put_contents($pythonScript, $scriptContent);
        
        exec("python " . escapeshellarg($pythonScript) . " " . $pdfCmd . " 2>&1", $output, $returnCode);
        @unlink($pythonScript);
        
        if ($returnCode === 0 && !empty($output) && is_numeric($output[0])) {
            return (int)$output[0];
        }
        
        // Méthode 3: Fallback - utiliser Ghostscript
        if (SystemHelper::commandExists('gs')) {
            exec("gs -q -dNODISPLAY -c \"({$filePath}) (r) file runpdfbegin pdfpagecount = quit\" 2>&1", $output, $returnCode);
            if ($returnCode === 0 && !empty($output) && is_numeric($output[0])) {
                return (int)$output[0];
            }
        }
        
        // Par défaut, supposer 1 page
        return 1;
    }
    
    /**
     * Analyse chaque page du PDF avec Claude IA
     */
    private function analyzePages(string $filePath, int $documentId, int $numPages): array
    {
        $analyses = [];
        $ocrService = new OCRService();
        
        // Limiter à 20 pages max pour performance
        $maxPages = min($numPages, 20);
        
        $successCount = 0;
        $errorCount = 0;
        
        for ($pageNum = 0; $pageNum < $maxPages; $pageNum++) {
            try {
                // Extraire le texte de cette page spécifique
                $pageText = $this->extractPageText($filePath, $pageNum);
                
                if (empty($pageText) || strlen(trim($pageText)) < 50) {
                    // Page vide ou peu de texte, ignorer
                    continue;
                }
                
                // Analyser avec Claude IA (peut retourner null si API non disponible)
                $analysis = $this->analyzePageWithAI($pageText, $pageNum + 1);
                
                if ($analysis) {
                    $analysis['page_num'] = $pageNum;
                    $analyses[$pageNum] = $analysis;
                    $successCount++;
                    error_log("PDFSplitterService: Page " . ($pageNum + 1) . " analysée: " . ($analysis['correspondent'] ?? 'N/A'));
                } else {
                    // Analyse échouée (API non disponible, timeout, etc.)
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                error_log("PDFSplitterService: Erreur analyse page " . ($pageNum + 1) . ": " . $e->getMessage());
                continue; // Continuer avec les autres pages
            }
        }
        
        // Si aucune analyse n'a réussi, logger et retourner vide
        if ($successCount === 0 && $errorCount > 0) {
            error_log("PDFSplitterService: Aucune page analysée avec succès (API non disponible ou erreurs). Traitement normal du document.");
        }
        
        return $analyses;
    }
    
    /**
     * Extrait le texte d'une page spécifique du PDF
     */
    private function extractPageText(string $filePath, int $pageNum): ?string
    {
        $pdfCmd = escapeshellarg($filePath);
        $outputFile = $this->tempDir . '/' . uniqid('page_text_') . '.txt';
        $outputCmd = escapeshellarg($outputFile);
        
        // Utiliser pdftotext avec option -f et -l pour une page spécifique
        if (SystemHelper::commandExists('pdftotext')) {
            $pageOneIndexed = $pageNum + 1; // pdftotext utilise 1-indexed
            exec("pdftotext -f {$pageOneIndexed} -l {$pageOneIndexed} -layout {$pdfCmd} {$outputCmd} 2>&1", $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile)) {
                $text = file_get_contents($outputFile);
                @unlink($outputFile);
                return trim($text);
            }
        }
        
        // Fallback: utiliser Python avec pdfplumber
        $pythonScript = $this->tempDir . '/extract_page.py';
        $scriptContent = <<<PYTHON
import sys
try:
    import pdfplumber
    with pdfplumber.open(sys.argv[1]) as pdf:
        if int(sys.argv[2]) < len(pdf.pages):
            page = pdf.pages[int(sys.argv[2])]
            text = page.extract_text()
            print(text if text else "")
        else:
            print("")
except Exception as e:
    print("")
PYTHON;
        file_put_contents($pythonScript, $scriptContent);
        
        exec("python " . escapeshellarg($pythonScript) . " " . $pdfCmd . " " . $pageNum . " 2>&1", $output, $returnCode);
        @unlink($pythonScript);
        
        if ($returnCode === 0 && !empty($output)) {
            return trim(implode("\n", $output));
        }
        
        return null;
    }
    
    /**
     * Analyse une page avec Claude IA pour déterminer son type de document
     */
    private function analyzePageWithAI(string $pageText, int $pageNumber): ?array
    {
        if (!$this->claude->isConfigured()) {
            return null;
        }
        
        $systemPrompt = <<<PROMPT
Tu es un assistant spécialisé dans l'analyse de pages de documents PDF multi-pages.
Tu dois déterminer si une page est un document pertinent à classer séparément ou si elle fait partie d'un document précédent.
Réponds UNIQUEMENT en JSON valide.
PROMPT;
        
        $prompt = "Analyse cette page (page {$pageNumber} d'un PDF multi-pages) et extrais les informations suivantes au format JSON:\n\n";
        $prompt .= "{\n";
        $prompt .= '  "correspondent": "nom de l\'expéditeur ou fournisseur ou null",' . "\n";
        $prompt .= '  "document_type": "type de document (facture, courrier, contrat, etc.) ou null",' . "\n";
        $prompt .= '  "date": "YYYY-MM-DD ou null",' . "\n";
        $prompt .= '  "amount": montant numérique ou null,' . "\n";
        $prompt .= '  "is_relevant": true ou false (true si c\'est un document pertinent à classer, false si page blanche ou non pertinente)' . "\n";
        $prompt .= "}\n\n";
        $prompt .= "TEXTE DE LA PAGE:\n" . substr($pageText, 0, 8000);
        
        try {
            // Vérifier que Claude est toujours configuré (peut changer entre les appels)
            if (!$this->claude->isConfigured()) {
                return null; // API non configurée, ignorer silencieusement
            }
            
            $response = $this->claude->sendMessage($prompt, $systemPrompt);
            if (!$response) {
                // API n'a pas répondu (timeout, erreur réseau, etc.)
                // Ne pas logger à chaque page pour éviter le spam, seulement si toutes les pages échouent
                return null;
            }
            
            $text = $this->claude->extractText($response);
            if (empty($text)) {
                return null;
            }
            
            // Nettoyer le JSON
            $text = preg_replace('/^```json\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
            
            $result = json_decode($text, true);
            if (!$result || json_last_error() !== JSON_ERROR_NONE) {
                // JSON invalide, ignorer cette page
                return null;
            }
            
            if (isset($result['is_relevant']) && $result['is_relevant']) {
                return [
                    'correspondent' => $result['correspondent'] ?? null,
                    'document_type' => $result['document_type'] ?? null,
                    'date' => $result['date'] ?? null,
                    'amount' => isset($result['amount']) ? (float)$result['amount'] : null,
                ];
            }
        } catch (\Exception $e) {
            // Erreur lors de l'appel API (timeout, rate limit, etc.)
            // Ne pas logger à chaque page pour éviter le spam
            // Le log sera fait au niveau supérieur si toutes les pages échouent
            return null;
        }
        
        return null;
    }
    
    /**
     * Groupe les pages par document en comparant les analyses
     */
    private function groupPagesByDocument(array $pageAnalyses): array
    {
        if (empty($pageAnalyses)) {
            return [];
        }
        
        $groups = [];
        $currentGroup = [];
        $prevAnalysis = null;
        
        foreach ($pageAnalyses as $pageNum => $analysis) {
            if ($prevAnalysis === null) {
                // Première page
                $currentGroup[] = $pageNum;
                $prevAnalysis = $analysis;
            } elseif ($this->areSameDocument($prevAnalysis, $analysis)) {
                // Même document, ajouter au groupe
                $currentGroup[] = $pageNum;
            } else {
                // Nouveau document, sauvegarder le groupe précédent
                if (!empty($currentGroup)) {
                    $groups[] = $currentGroup;
                }
                $currentGroup = [$pageNum];
                $prevAnalysis = $analysis;
            }
        }
        
        // Ajouter le dernier groupe
        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }
        
        return $groups;
    }
    
    /**
     * Détermine si deux analyses correspondent au même document
     */
    private function areSameDocument(array $analysis1, array $analysis2): bool
    {
        // Critère 1: Même correspondant
        $corr1 = strtolower(trim($analysis1['correspondent'] ?? ''));
        $corr2 = strtolower(trim($analysis2['correspondent'] ?? ''));
        if ($corr1 && $corr2 && $corr1 !== $corr2) {
            return false;
        }
        
        // Critère 2: Même type de document
        $type1 = strtolower(trim($analysis1['document_type'] ?? ''));
        $type2 = strtolower(trim($analysis2['document_type'] ?? ''));
        if ($type1 && $type2 && $type1 !== $type2) {
            return false;
        }
        
        // Critère 3: Dates proches (même jour ou jour suivant)
        $date1 = $analysis1['date'] ?? null;
        $date2 = $analysis2['date'] ?? null;
        if ($date1 && $date2) {
            try {
                $d1 = new \DateTime($date1);
                $d2 = new \DateTime($date2);
                $diff = abs($d1->diff($d2)->days);
                if ($diff > 1) {
                    return false;
                }
            } catch (\Exception $e) {
                // Dates invalides, ignorer ce critère
            }
        }
        
        // Si on arrive ici et qu'on a au moins le correspondant, c'est le même document
        return !empty($corr1) || !empty($corr2);
    }
    
    /**
     * Sépare un PDF en plusieurs fichiers selon les groupes de pages
     */
    private function splitPDF(string $filePath, array $pageGroups, string $originalFilename): array
    {
        $splitFiles = [];
        $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
        
        // Utiliser Python avec PyPDF2 ou pdfplumber pour séparer
        $pythonScript = $this->tempDir . '/split_pdf.py';
        $scriptContent = <<<PYTHON
import sys
import json
try:
    import pdfplumber
    from PyPDF2 import PdfReader, PdfWriter
    
    pdf_path = sys.argv[1]
    page_groups_json = sys.argv[2]
    output_dir = sys.argv[3]
    base_name = sys.argv[4]
    
    page_groups = json.loads(page_groups_json)
    
    reader = PdfReader(pdf_path)
    output_files = []
    
    for group_idx, pages in enumerate(page_groups):
        writer = PdfWriter()
        for page_num in pages:
            writer.add_page(reader.pages[page_num])
        
        output_file = f"{output_dir}/{base_name}_part{group_idx + 1}.pdf"
        with open(output_file, 'wb') as f:
            writer.write(f)
        output_files.append(output_file)
    
    print(json.dumps(output_files))
except Exception as e:
    print(json.dumps([]))
PYTHON;
        file_put_contents($pythonScript, $scriptContent);
        
        $pdfCmd = escapeshellarg($filePath);
        $groupsJson = escapeshellarg(json_encode($pageGroups));
        $outputDir = escapeshellarg($this->tempDir);
        $baseName = escapeshellarg($baseName);
        
        exec("python " . escapeshellarg($pythonScript) . " {$pdfCmd} {$groupsJson} {$outputDir} {$baseName} 2>&1", $output, $returnCode);
        @unlink($pythonScript);
        
        if ($returnCode === 0 && !empty($output)) {
            $files = json_decode(implode("\n", $output), true);
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        $splitFiles[] = $file;
                    }
                }
            }
        }
        
        return $splitFiles;
    }
    
    /**
     * Crée un nouveau document à partir d'un PDF séparé
     */
    private function createDocumentFromSplit(string $splitFile, array $parentDoc, array $pageGroup, ?array $analysis, int $parentId): ?int
    {
        try {
            // Copier le fichier vers un dossier pending/ pour les fichiers séparés en attente de validation
            // Ces fichiers n'ont pas d'original dans consume/, ils sont créés à partir d'un PDF parent
            $unique = date('Ymd_His') . '_' . uniqid() . '.pdf';
            $pendingPath = $this->documentsPath . '/pending';
            if (!is_dir($pendingPath)) {
                @mkdir($pendingPath, 0755, true);
            }
            $dest = $pendingPath . '/' . $unique;
            
            if (!copy($splitFile, $dest)) {
                throw new \Exception("Impossible de copier le fichier séparé");
            }
            
            // Créer le document
            $title = pathinfo($parentDoc['original_filename'], PATHINFO_FILENAME) . ' (pages ' . ($pageGroup[0] + 1) . '-' . ($pageGroup[count($pageGroup) - 1] + 1) . ')';
            
            $stmt = $this->db->prepare("
                INSERT INTO documents (
                    title, filename, original_filename, file_path, file_size, mime_type, 
                    checksum, status, parent_document_id, split_pages, uploaded_at, created_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW(), NOW(), NOW())
            ");
            
            $stmt->execute([
                $title,
                $unique,
                basename($splitFile),
                $dest,
                filesize($dest),
                'application/pdf',
                md5_file($dest),
                $parentId,
                json_encode($pageGroup)
            ]);
            
            $newDocId = $this->db->lastInsertId();
            
            // Appliquer les suggestions de classification si disponibles
            if ($analysis) {
                $suggestions = [
                    'correspondent' => $analysis['correspondent'] ?? null,
                    'document_type' => $analysis['document_type'] ?? null,
                    'date' => $analysis['date'] ?? null,
                    'amount' => $analysis['amount'] ?? null,
                ];
                
                $this->db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?")
                    ->execute([json_encode($suggestions), $newDocId]);
            }
            
            // Supprimer le fichier temporaire
            @unlink($splitFile);
            
            return $newDocId;
        } catch (\Exception $e) {
            error_log("PDFSplitterService: Erreur création document depuis split: " . $e->getMessage());
            return null;
        }
    }
}
