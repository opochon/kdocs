<?php
/**
 * K-Docs - PDFSplitterService
 * Service pour séparer les PDFs multi-pages en documents distincts
 * Basé sur l'analyse IA du contenu de chaque page
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Services\AIProviderService;
use KDocs\Helpers\SystemHelper;

class PDFSplitterService
{
    private $db;
    private $tempDir;
    private $documentsPath;
    /** Fournisseur IA actif (cascade Infomaniak > Claude > Ollama), remplace l'ancienne dépendance directe à ClaudeService. */
    private AIProviderService $aiProvider;
    /** Méthode utilisée par la dernière analyse de pages : 'ai' | 'rules' | 'none'. Sert à annoter le résultat du split. */
    private string $lastSplitMethod = 'none';
    /** Pages rasterisées du document en cours (index 0-based -> PNG), rendues une seule fois. */
    private ?array $pageRenderCache = null;
    /** Répertoire temporaire des pages rasterisées, purgé en fin d'analyse. */
    private ?string $pageRenderDir = null;

    public function __construct(?AIProviderService $aiProvider = null)
    {
        $this->db = Database::getInstance();
        $config = Config::load();
        $this->tempDir = $config['storage']['temp'] ?? __DIR__ . '/../../storage/temp';
        $this->documentsPath = $config['storage']['documents'] ?? __DIR__ . '/../../storage/documents';
        $this->aiProvider = $aiProvider ?? new AIProviderService();
        
        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0755, true);
        }
    }
    
    /**
     * Détection légère (sans appel IA) : le document est-il candidat à la séparation ?
     * Utilisée par {@see \KDocs\Services\PdfSplit\PdfSplitService::detectPageGroups()}.
     * La décision définitive (nombre de documents distincts, page_groups) est prise par
     * analyzeAndSplit() qui fait l'analyse réelle (IA ou règles en dur).
     *
     * @return array{should_split: bool, page_groups: array, source: string, audit: array}
     */
    public function detectCandidate(int $documentId): array
    {
        $audit = ['document_id' => $documentId];

        $stmt = $this->db->prepare("SELECT mime_type, file_path FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$document) {
            return ['should_split' => false, 'page_groups' => [], 'source' => 'not_found', 'audit' => $audit];
        }

        if ($document['mime_type'] !== 'application/pdf') {
            return ['should_split' => false, 'page_groups' => [], 'source' => 'not_pdf', 'audit' => $audit];
        }

        $config = Config::load();
        if (!($config['classification']['ai_split_enabled'] ?? false)) {
            return ['should_split' => false, 'page_groups' => [], 'source' => 'disabled', 'audit' => $audit];
        }

        $filePath = $document['file_path'];
        if (!file_exists($filePath)) {
            $filePath = $this->documentsPath . '/' . basename($filePath);
        }
        if (!file_exists($filePath)) {
            return ['should_split' => false, 'page_groups' => [], 'source' => 'file_missing', 'audit' => $audit];
        }

        $numPages = $this->countPDFPages($filePath);
        $audit['pages'] = $numPages;

        if ($numPages <= 1) {
            return ['should_split' => false, 'page_groups' => [], 'source' => 'single_page', 'audit' => $audit];
        }

        return [
            'should_split' => true,
            'page_groups' => [],
            'source' => $this->aiProvider->isAIAvailable() ? 'ai' : 'rules',
            'audit' => $audit,
        ];
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

        // Moteur partage cmd4_ingest : meme code que CMD v4 (codes de pli, QR-facture,
        // folio, escalade vision). Le pipeline PHP historique reste en repli.
        $shared = $this->splitViaSharedEngine($documentId, $document, $filePath);
        if ($shared !== null) {
            return $shared;
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
        
        // Repli sur les règles en dur (heuristiques POC : indicateur de page, date, expéditeur, type)
        // si aucun fournisseur IA n'est disponible — ne bloque plus la séparation (voir docs/IA-ROADMAP.md).
        $aiAvailable = $this->aiProvider->isAIAvailable();
        if (!$aiAvailable) {
            error_log("PDFSplitterService: aucun fournisseur IA disponible, repli sur les règles en dur (heuristiques)");
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
            
            // Texte OCR des pages du groupe : deja extrait pendant l'analyse, on le
            // reporte sur l'enfant au lieu de le jeter (evite un second OCR et un
            // classement sur document vide).
            $groupText = '';
            foreach ($pageGroup as $p) {
                $groupText .= (string) ($pageAnalyses[$p]['_text'] ?? '') . "\n\n";
            }

            $newDocId = $this->createDocumentFromSplit(
                $splitFile,
                $document,
                $pageGroup,
                $analysis,
                $documentId, // parent_id
                $this->lastSplitMethod,
                trim($groupText)
            );

            if ($newDocId) {
                $createdDocuments[] = [
                    'id' => $newDocId,
                    'pages' => $pageGroup,
                    'analysis' => $analysis
                ];
            }
        }

        // Marquer le document original comme "split" (split_into_count n'existe pas en colonne
        // dans ce schéma ; le compte reste disponible via split_count ci-dessous / classification_suggestions)
        $this->db->prepare("UPDATE documents SET status = 'split' WHERE id = ?")
            ->execute([$documentId]);

        return [
            'parent_id' => $documentId,
            'split_count' => count($createdDocuments),
            'method_used' => $this->lastSplitMethod, // 'ai' | 'rules' — l'aval distingue un split IA d'un split par règles
            'documents' => $createdDocuments,
            'created_documents' => array_column($createdDocuments, 'id'), // compat KDocs\Services\PdfSplit\PdfSplitService::split()
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
     * Analyse chaque page du PDF avec le fournisseur IA actif (repli sur les règles en dur si indisponible)
     */
    private function analyzePages(string $filePath, int $documentId, int $numPages): array
    {
        $analyses = [];
        $ocrService = new OCRService();
        
        // Plafond d'analyse : 0 = toutes les pages. Le plafond de 20 pages code en dur
        // laissait 83 pages sur 103 hors du découpage pour un scan de courrier du matin.
        $configuredMax = (int) Config::get('classification.split_max_pages', 0);
        $maxPages = $configuredMax > 0 ? min($numPages, $configuredMax) : $numPages;

        // Une seule rasterisation pour tout le document : les pages sans couche texte
        // sont OCRisées à la demande depuis ce cache (voir extractPageText()).
        $this->pageRenderCache = null;

        $successCount = 0;
        $errorCount = 0;
        
        for ($pageNum = 0; $pageNum < $maxPages; $pageNum++) {
            try {
                // Extraire le texte de cette page spécifique
                $pageText = $this->extractPageText($filePath, $pageNum);
                
                if (empty($pageText) || strlen(trim($pageText)) < 50) {
                    // Page sans texte exploitable (verso vierge, photo pleine page).
                    // Elle est rattachee au document courant plutot qu'ignoree : une page
                    // absente de tout groupe n'est ecrite dans AUCUN PDF de sortie, donc
                    // perdue silencieusement du lot.
                    $analyses[$pageNum] = $this->continuationAnalysis($pageNum, (string) $pageText, 'blank');
                    continue;
                }

                // Analyser avec le fournisseur IA actif (retombe sur les heuristiques si indisponible)
                $analysis = $this->analyzePageWithAI($pageText, $pageNum + 1);

                if ($analysis) {
                    $analysis['page_num'] = $pageNum;
                    // Texte conserve : il devient le contenu indexable du document decoupe,
                    // sinon les enfants naissent sans `content` et sont classes a l'aveugle.
                    $analysis['_text'] = $pageText;
                    $analyses[$pageNum] = $analysis;
                    $successCount++;
                    error_log("PDFSplitterService: Page " . ($pageNum + 1) . " analysée: " . ($analysis['correspondent'] ?? 'N/A'));
                } else {
                    // Analyse échouée (API non disponible, timeout, etc.) : la page est
                    // rattachee au document courant, jamais abandonnee — une page absente
                    // de tout groupe n'est ecrite dans aucun PDF de sortie.
                    $errorCount++;
                    $analyses[$pageNum] = $this->continuationAnalysis($pageNum, $pageText);
                }
            } catch (\Exception $e) {
                $errorCount++;
                error_log("PDFSplitterService: Erreur analyse page " . ($pageNum + 1) . ": " . $e->getMessage());
                $analyses[$pageNum] = $this->continuationAnalysis($pageNum, $pageText ?? '');
                continue; // Continuer avec les autres pages
            }
        }
        
        // Si aucune analyse n'a réussi, logger et retourner vide
        if ($successCount === 0 && $errorCount > 0) {
            error_log("PDFSplitterService: Aucune page analysée avec succès (API non disponible ou erreurs). Traitement normal du document.");
        }

        // Déterminer la méthode dominante (annonce IA vs règles dans le résultat final)
        $aiPages = 0;
        $rulesPages = 0;
        foreach ($analyses as $analysis) {
            if (($analysis['_source'] ?? null) === 'ai') {
                $aiPages++;
            } elseif (($analysis['_source'] ?? null) === 'rules') {
                $rulesPages++;
            }
        }
        $this->lastSplitMethod = $aiPages > 0 ? 'ai' : ($rulesPages > 0 ? 'rules' : 'none');

        $this->purgeRenderedPages();

        return $analyses;
    }

    /**
     * Analyse minimale marquant une page comme suite du document courant.
     * Filet de securite : toute page non exploitable reste rattachee a un groupe,
     * donc presente dans un PDF de sortie. Zero page perdue sur un lot scanne.
     *
     * @return array<string, mixed>
     */
    private function continuationAnalysis(int $pageNum, string $pageText, string $source = 'unanalyzed'): array
    {
        return [
            'page_num' => $pageNum,
            'is_first_page' => false,
            'doc_page' => 2, // > 1 : areSameDocument() y voit une continuation
            '_source' => $source,
            '_text' => $pageText,
        ];
    }

    private function purgeRenderedPages(): void
    {
        if ($this->pageRenderDir !== null && is_dir($this->pageRenderDir)) {
            foreach (glob($this->pageRenderDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->pageRenderDir);
        }
        $this->pageRenderDir = null;
        $this->pageRenderCache = null;
    }
    
    /**
     * Extrait le texte d'une page spécifique du PDF
     */
    private function extractPageText(string $filePath, int $pageNum): ?string
    {
        $text = $this->extractPageTextLayer($filePath, $pageNum);

        // Couche texte absente ou vide (scan) : OCR de cette page uniquement.
        if (!OCRService::hasReadableText($text, 20)) {
            $ocrText = $this->ocrPage($filePath, $pageNum);
            if (OCRService::hasReadableText($ocrText, 20)) {
                return $ocrText;
            }
        }

        return $text;
    }

    /**
     * Rasterise le PDF une seule fois puis OCRise la page demandée.
     * Sans ce repli, un PDF 100% image ne rend aucun texte de page et le découpage
     * est abandonné en silence (0 analyse -> analyzeAndSplit() retourne null).
     */
    private function ocrPage(string $filePath, int $pageNum): ?string
    {
        $pages = $this->renderPages($filePath);
        $pageFile = $pages[$pageNum] ?? null;
        if ($pageFile === null || !file_exists($pageFile)) {
            return null;
        }

        try {
            return (new OCRService())->extractText($pageFile);
        } catch (\Throwable $e) {
            error_log('PDFSplitterService: OCR page ' . ($pageNum + 1) . ' : ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return array<int, string> index 0-based de page -> fichier PNG
     */
    private function renderPages(string $filePath): array
    {
        if ($this->pageRenderCache !== null) {
            return $this->pageRenderCache;
        }

        $this->pageRenderCache = [];

        $config = Config::load();
        $pdftoppmPath = SystemHelper::findExecutable(
            'pdftoppm',
            array_filter([$config['tools']['pdftoppm'] ?? null, ...SystemHelper::getDefaultPaths('pdftoppm')])
        );
        if (!$pdftoppmPath) {
            error_log('PDFSplitterService: pdftoppm introuvable, OCR page par page impossible');

            return $this->pageRenderCache;
        }

        $dir = $this->tempDir . '/' . uniqid('split_pages_');
        @mkdir($dir, 0755, true);
        $this->pageRenderDir = $dir;

        $dpi = (int) Config::get('ocr.split_dpi', 200);
        exec(
            escapeshellarg($pdftoppmPath) . " -png -r {$dpi} " . escapeshellarg($filePath) . ' ' . escapeshellarg($dir . '/page') . ' 2>&1',
            $out,
            $code
        );
        if ($code !== 0) {
            error_log('PDFSplitterService: pdftoppm code ' . $code . ' : ' . implode("\n", $out));

            return $this->pageRenderCache;
        }

        $files = glob($dir . '/page*.png') ?: [];
        sort($files, SORT_NATURAL);
        $this->pageRenderCache = array_values($files);

        return $this->pageRenderCache;
    }

    /**
     * Texte de la couche PDF pour une page (sans OCR).
     */
    private function extractPageTextLayer(string $filePath, int $pageNum): ?string
    {
        $pdfCmd = escapeshellarg($filePath);
        $outputFile = $this->tempDir . '/' . uniqid('page_text_') . '.txt';
        $outputCmd = escapeshellarg($outputFile);

        $config = Config::load();
        $pdftotextPath = SystemHelper::findExecutable(
            'pdftotext',
            array_filter([$config['tools']['pdftotext'] ?? null, ...SystemHelper::getDefaultPaths('pdftotext')])
        );

        // Utiliser pdftotext avec option -f et -l pour une page spécifique
        if ($pdftotextPath) {
            $pageOneIndexed = $pageNum + 1; // pdftotext utilise 1-indexed
            $binCmd = escapeshellarg($pdftotextPath);
            exec("{$binCmd} -f {$pageOneIndexed} -l {$pageOneIndexed} -layout {$pdfCmd} {$outputCmd} 2>&1", $output, $returnCode);
            
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
     * Analyse une page avec le fournisseur IA actif pour déterminer son type de document
     * Enhanced with POC heuristics for page indicators and date extraction (repli si IA indisponible)
     */
    private function analyzePageWithAI(string $pageText, int $pageNumber): ?array
    {
        // POC Enhancement: Always extract heuristics first (works without AI)
        $heuristics = $this->extractPageHeuristics($pageText);

        if (!$this->aiProvider->isAIAvailable()) {
            // Repli règles en dur (heuristiques POC) si aucun fournisseur IA n'est disponible
            if (!empty($heuristics)) {
                $heuristics['_source'] = 'rules';
                return $heuristics;
            }
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
            // Vérifier qu'un fournisseur IA est toujours disponible (peut changer entre les appels)
            if (!$this->aiProvider->isAIAvailable()) {
                return null; // Aucun fournisseur configuré, ignorer silencieusement
            }

            $response = $this->aiProvider->complete($systemPrompt . "\n\n" . $prompt, ['max_tokens' => 600]);
            if (!$response || empty($response['text'])) {
                // Aucun fournisseur n'a répondu (timeout, erreur réseau, etc.) : repli sur les heuristiques
                // Ne pas logger à chaque page pour éviter le spam, seulement si toutes les pages échouent
                if (!empty($heuristics)) {
                    $heuristics['_source'] = 'rules';
                    return $heuristics;
                }
                return null;
            }

            $text = $response['text'];

            // Nettoyer le JSON
            $text = preg_replace('/^```json\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim($text);

            $result = json_decode($text, true);
            if (!$result || json_last_error() !== JSON_ERROR_NONE) {
                // JSON invalide : repli sur les heuristiques pour cette page
                if (!empty($heuristics)) {
                    $heuristics['_source'] = 'rules';
                    return $heuristics;
                }
                return null;
            }
            
            if (isset($result['is_relevant']) && $result['is_relevant']) {
                // Merge AI result with heuristics
                return array_merge($heuristics, [
                    'correspondent' => $result['correspondent'] ?? null,
                    'document_type' => $result['document_type'] ?? null,
                    'date' => $result['date'] ?? $heuristics['date'] ?? null,
                    'amount' => isset($result['amount']) ? (float)$result['amount'] : null,
                    '_source' => 'ai',
                ]);
            }
        } catch (\Exception $e) {
            // Erreur lors de l'appel API (timeout, rate limit, etc.) : repli sur les heuristiques de cette page
            if (!empty($heuristics)) {
                $heuristics['_source'] = 'rules';
                return $heuristics;
            }
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
     * Enhanced with POC heuristics (page indicators, date detection)
     */
    private function areSameDocument(array $analysis1, array $analysis2): bool
    {
        // POC Heuristic 1: Check page indicators (e.g., "Page 1/2")
        // If current page is marked as "first page", it's a new document
        if (!empty($analysis2['is_first_page'])) {
            return false;
        }

        // POC Heuristic 2: If current has continuation indicator (page x/y where x > 1)
        if (!empty($analysis2['doc_page']) && $analysis2['doc_page'] > 1) {
            return true; // Continuation of previous document
        }

        // POC Heuristic 3: Previous was last page of multi-page doc
        $prevPage = $analysis1['doc_page'] ?? 0;
        $prevTotal = $analysis1['doc_total'] ?? 0;
        if ($prevPage > 0 && $prevPage === $prevTotal) {
            return false; // Previous was the last page, this is a new doc
        }

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
     * Detect page indicator patterns like "Page 1/2", "1 of 3", "Seite 1 von 2"
     * From POC 06_consume_flow.php
     */
    private function detectPageIndicator(string $text): array
    {
        $patterns = [
            '/page\s*:?\s*(\d+)\s*[\/\|]\s*(\d+)/i',        // Page 1/2, Page: 1|2
            '/page\s*(\d+)\s+(?:of|sur|de)\s+(\d+)/i',      // Page 1 of 2
            '/seite\s*(\d+)\s*von\s*(\d+)/i',               // Seite 1 von 2 (German)
            '/(\d+)\s*[\/\|]\s*(\d+)\s*$/m',                // 1/2 at end of line
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $current = (int)$m[1];
                $total = (int)$m[2];
                if ($current > 0 && $total > 0 && $current <= $total) {
                    return [
                        'current' => $current,
                        'total' => $total,
                        'is_first' => ($current === 1),
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Extract date from text with 2-digit year support
     * From POC helpers
     */
    private function extractDateFromText(string $text): ?string
    {
        // Pattern 1: DD/MM/YYYY or DD.MM.YYYY or DD-MM-YYYY
        if (preg_match('/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})/', $text, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // Pattern 2: DD/MM/YY (2-digit year)
        if (preg_match('/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2})(?!\d)/', $text, $m)) {
            $year = (int)$m[3];
            $year = $year <= 30 ? 2000 + $year : 1900 + $year;
            return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
        }

        // Pattern 3: YYYY-MM-DD (ISO format)
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }

        return null;
    }

    /**
     * Extract heuristics from page text (works without AI)
     * From POC 06_consume_flow.php
     */
    private function extractPageHeuristics(string $text): array
    {
        $heuristics = [];

        // Detect page indicators
        $pageIndicator = $this->detectPageIndicator($text);
        if (!empty($pageIndicator)) {
            $heuristics['doc_page'] = $pageIndicator['current'];
            $heuristics['doc_total'] = $pageIndicator['total'];
            $heuristics['is_first_page'] = $pageIndicator['is_first'];
        }

        // Extract date
        $date = $this->extractDateFromText($text);
        if ($date) {
            $heuristics['date'] = $date;
        }

        // Detect sender/correspondent from common patterns
        $senderPatterns = [
            '/(?:de|from|von|expéditeur)\s*:\s*(.+)/i',
            '/^([A-Z][a-zA-Z\s]+(?:SA|AG|GmbH|SARL|SAS|Inc|Ltd))/m',
        ];
        foreach ($senderPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $sender = trim($m[1]);
                if (strlen($sender) > 3 && strlen($sender) < 100) {
                    $heuristics['correspondent'] = $sender;
                    break;
                }
            }
        }

        // Detect document type from keywords
        $typeKeywords = [
            'facture' => ['facture', 'invoice', 'rechnung'],
            'contrat' => ['contrat', 'contract', 'vertrag', 'convention'],
            'courrier' => ['madame', 'monsieur', 'cher', 'dear', 'sehr geehrte'],
            'rapport' => ['rapport', 'report', 'bericht', 'analyse'],
            'devis' => ['devis', 'offre', 'quotation', 'angebot'],
        ];
        $textLower = mb_strtolower($text);
        foreach ($typeKeywords as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($textLower, $kw)) {
                    $heuristics['document_type'] = $type;
                    break 2;
                }
            }
        }

        return $heuristics;
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
     * Decoupage par le moteur partage `cmd4_ingest`.
     *
     * Rend null si le moteur n'est pas installe (repli sur le pipeline PHP) ou s'il
     * ne voit qu'un seul document. Chaque enfant recoit son texte OCR, ses pages, et
     * sa qualification facture (emetteur, montant, IBAN, reference, statut paye) —
     * exactement ce que CMD v4 obtient, puisque c'est le meme code.
     */
    private function splitViaSharedEngine(int $documentId, array $document, string $filePath): ?array
    {
        $client = new \KDocs\Services\Ingest\Cmd4IngestClient();
        if (!$client->isAvailable()) {
            return null;
        }

        $outDir = rtrim($this->tempDir, "/\\") . DIRECTORY_SEPARATOR . 'cmd4split_' . $documentId;

        // La taxonomie GED est passee au moteur : il choisit DANS cette liste au lieu
        // d'inventer un libelle libre qui ne correspondrait a aucun type existant.
        $labels = $this->db->query('SELECT label FROM document_types ORDER BY label')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $options = $labels ? ['types' => implode(',', $labels)] : [];

        $result = $client->ingest($filePath, $outDir, $options);
        if (!is_array($result) || empty($result['documents'])) {
            return null;
        }

        $docs = $result['documents'];
        if (count($docs) <= 1) {
            return null;   // un seul document : rien a decouper
        }

        $this->lastSplitMethod = 'cmd4_ingest';
        $created = [];

        foreach ($docs as $doc) {
            $file = $doc['file'] ?? null;
            if (!$file || !file_exists($file)) {
                continue;
            }

            $pages0 = array_map(static fn ($p) => ((int) $p) - 1, $doc['pages'] ?? []);
            $invoice = $doc['invoice'] ?? [];

            // Pour une facture, le QR fait foi ; sinon on prend la description du
            // moteur (type contraint a la taxonomie GED, emetteur, date).
            $seen = is_array($doc['classify'] ?? null) ? $doc['classify'] : [];

            $analysis = [
                'correspondent' => ($invoice['issuer'] ?? '') ?: ($seen['issuer'] ?? null),
                'document_type' => !empty($invoice['is_invoice'])
                    ? 'facture'
                    : ($seen['document_type'] ?? null),
                'date' => ($invoice['invoice_date'] ?? '')
                    ?: ($invoice['due_date'] ?? '')
                    ?: ($seen['date'] ?? null),
                'amount' => $invoice['amount'] ?? null,
                '_source' => 'cmd4_ingest',
            ];

            $newId = $this->createDocumentFromSplit(
                $file, $document, $pages0, $analysis, $documentId,
                'cmd4_ingest', (string) ($doc['text'] ?? '')
            );
            if (!$newId) {
                continue;
            }

            $this->persistInvoiceFacts((int) $newId, $invoice);
            $this->flagUncertainCut((int) $newId, $pages0, $result['boundaries'] ?? []);
            $created[] = ['id' => $newId, 'pages' => $pages0, 'analysis' => $analysis];
        }

        if (!$created) {
            return null;
        }

        $this->db->prepare("UPDATE documents SET status = 'split' WHERE id = ?")->execute([$documentId]);

        return [
            'parent_id' => $documentId,
            'split_count' => count($created),
            'method_used' => 'cmd4_ingest',
            'engine' => [
                'vision_calls' => $result['vision_calls'] ?? 0,
                'page_total' => $result['page_total'] ?? null,
                'boundaries' => $result['boundaries'] ?? [],
            ],
            'documents' => $created,
            'created_documents' => array_column($created, 'id'),
        ];
    }

    /**
     * Marque un document dont la coupe n'est pas sure, pour relecture humaine.
     *
     * Une frontiere tranchee par un code de pli ou une pagination imprimee est un fait.
     * Une frontiere tranchee par un modele, ou pas tranchee du tout, est une opinion :
     * le document porte alors `needs_review = 1` et la raison, au lieu de se fondre
     * silencieusement dans le lot. C'est la seule facon de savoir OU regarder.
     */
    private function flagUncertainCut(int $documentId, array $pages0, array $boundaries): void
    {
        if (!$pages0) {
            return;
        }

        // Une coupe est sure quand elle vient de l'emetteur ou de l'imprimeur.
        $trusted = ['code_pli', 'pagination', 'folio', 'qr_facture', 'reference'];
        $doubts = [];

        $first = (int) $pages0[0];
        $afterLast = ((int) $pages0[count($pages0) - 1]) + 1;

        foreach ($boundaries as $b) {
            $page = (int) ($b['page'] ?? -1);
            // La frontiere qui OUVRE ce document, et celle qui le FERME.
            if ($page !== $first && $page !== $afterLast) {
                continue;
            }
            $rule = (string) ($b['rule'] ?? '');
            $confidence = (float) ($b['confidence'] ?? 0);
            if (in_array($rule, $trusted, true) && $confidence >= 0.8) {
                continue;
            }

            $doubts[] = [
                'page' => $page + 1,           // 1-based, comme dans le PDF d'origine
                'position' => $page === $first ? 'debut' : 'fin',
                'rule' => $rule,
                'confidence' => $confidence,
                'detail' => (string) ($b['detail'] ?? ''),
            ];
        }

        if (!$doubts) {
            return;
        }

        $stmt = $this->db->prepare('SELECT classification_suggestions FROM documents WHERE id = ?');
        $stmt->execute([$documentId]);
        $sugg = json_decode((string) $stmt->fetchColumn(), true);
        if (!is_array($sugg)) {
            $sugg = [];
        }
        $sugg['cut_review'] = [
            'certain' => false,
            'reason' => 'coupe non confirmee par un signal imprime',
            'boundaries' => $doubts,
        ];

        $this->db->prepare(
            'UPDATE documents SET needs_review = 1, classification_suggestions = ? WHERE id = ?'
        )->execute([json_encode($sugg, JSON_UNESCAPED_UNICODE), $documentId]);
    }

    /**
     * Reporte la qualification facture sur le document : montant et flag facture en
     * colonnes, le detail (IBAN, reference, statut paye, manuscrit) en suggestions.
     */
    private function persistInvoiceFacts(int $documentId, array $invoice): void
    {
        if (!$invoice) {
            return;
        }

        $sets = [];
        $params = [];

        if (!empty($invoice['is_invoice'])) {
            $stmt = $this->db->prepare("SELECT id FROM document_types WHERE code = 'FACTURE' OR label LIKE 'Facture%' LIMIT 1");
            $stmt->execute();
            if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $sets[] = 'document_type_id = ?';
                $params[] = (int) $row['id'];
            }
        }

        if (isset($invoice['amount']) && is_numeric($invoice['amount'])) {
            $sets[] = 'amount = ?';
            $params[] = (float) $invoice['amount'];
        }
        if (!empty($invoice['currency'])) {
            $sets[] = 'currency = ?';
            $params[] = substr((string) $invoice['currency'], 0, 3);
        }

        $stmt = $this->db->prepare('SELECT classification_suggestions FROM documents WHERE id = ?');
        $stmt->execute([$documentId]);
        $existing = json_decode((string) $stmt->fetchColumn(), true);
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing['invoice'] = $invoice;
        $existing['method_used'] = 'cmd4_ingest';
        $sets[] = 'classification_suggestions = ?';
        $params[] = json_encode($existing, JSON_UNESCAPED_UNICODE);

        $params[] = $documentId;
        $this->db->prepare('UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /**
     * Reporte l'analyse de page en colonnes exploitables par le rangement.
     * Correspondant cree s'il n'existe pas (meme convention que DocumentProcessor).
     *
     * @param array<string, mixed>|null $analysis
     */
    private function applySplitMetadata(int $documentId, ?array $analysis): void
    {
        if ($analysis === null) {
            return;
        }

        $sets = [];
        $params = [];

        if (!empty($analysis['date'])) {
            $ts = strtotime((string) $analysis['date']);
            if ($ts !== false) {
                $sets[] = 'doc_date = ?';
                $params[] = date('Y-m-d', $ts);
                $sets[] = 'document_date = ?';
                $params[] = date('Y-m-d', $ts);
            }
        }

        if (isset($analysis['amount']) && is_numeric($analysis['amount'])) {
            $sets[] = 'amount = ?';
            $params[] = (float) $analysis['amount'];
        }

        $correspondent = trim((string) ($analysis['correspondent'] ?? ''));
        if ($correspondent !== '' && mb_strlen($correspondent) <= 190) {
            $sets[] = 'correspondent_id = ?';
            $params[] = $this->resolveCorrespondent($correspondent);
        }

        $type = trim((string) ($analysis['document_type'] ?? ''));
        if ($type !== '') {
            // Rapprochement souple : le libelle IA ("decompte des prestations") ne correspond
            // pas toujours a un type existant ; on ne cree jamais de type, on laisse null.
            $stmt = $this->db->prepare('SELECT id FROM document_types WHERE label = ? OR code = ? OR label LIKE ? LIMIT 1');
            $stmt->execute([$type, strtoupper($type), '%' . $type . '%']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $sets[] = 'document_type_id = ?';
                $params[] = (int) $row['id'];
            }
        }

        if ($sets === []) {
            return;
        }

        $params[] = $documentId;
        $this->db->prepare('UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /**
     * Retrouve un correspondant existant avant d'en creer un.
     *
     * Sans ce rapprochement, un meme emetteur se dedouble a chaque variante lue par
     * l'OCR : "Generali Assurances" et "Generali Assurances Generales SA" creaient
     * deux fiches, "Freddy Rumo" quatre. La cle de rapprochement retire accents,
     * ponctuation et forme juridique.
     */
    private function resolveCorrespondent(string $name): int
    {
        $key = self::correspondentKey($name);

        // Correspondance exacte d'abord (cas majoritaire, une seule requete).
        $stmt = $this->db->prepare('SELECT id FROM correspondents WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            return (int) $row['id'];
        }

        if ($key !== '') {
            foreach ($this->db->query('SELECT id, name FROM correspondents')->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $other = self::correspondentKey((string) $c['name']);
                if ($other === '' || !self::sameEntity($key, $other)) {
                    continue;
                }
                return (int) $c['id'];
            }
        }

        $this->db->prepare('INSERT INTO correspondents (name) VALUES (?)')->execute([$name]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Deux cles designent-elles le meme emetteur ?
     *
     * L'egalite stricte ne suffit pas : le meme expediteur signe "Generali Assurances"
     * puis "Generali Assurances Generales SA", "Freddy Rumo" puis "Etude d'avocats
     * Freddy Rumo". L'inclusion les rapproche. Seuil a 8 caracteres pour ne pas
     * fusionner deux entites sur un radical trop court.
     */
    public static function sameEntity(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;

        return mb_strlen($short) >= 8 && str_contains($long, $short);
    }

    /** Cle de rapprochement : minuscules, sans accent, sans forme juridique. */
    public static function correspondentKey(string $name): string
    {
        $value = mb_strtolower(trim($name));
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        // Formes juridiques et civilites : elles varient d'un courrier a l'autre.
        $value = preg_replace(
            '/\b(sa|ag|sarl|sarl\.|s\.?a\.?r\.?l\.?|gmbh|sas|sasu|ltd|inc|societe|'
            . 'societe cooperative|cooperative|holding|group|groupe|me|maitre|dr|docteur|'
            . 'monsieur|madame|etude|cabinet|assurances?|versicherungen?)\b/u',
            ' ',
            $value
        ) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;

        // Trop court apres nettoyage : on refuse de rapprocher, au risque de fusionner
        // deux emetteurs distincts.
        return mb_strlen($value) >= 6 ? $value : '';
    }

    /**
     * Nom de fichier et titre d'un document issu du découpage.
     *
     * Le fichier porte le nom qu'il gardera après validation : createAndMoveToPath()
     * reutilise `filename` tel quel pour le rangement final. Sans cela les PDF decoupes
     * arrivent en `20260827_081514_6a8ff212c2d53.pdf`, ranges mais illisibles.
     *
     * @param array<int, int>          $pageGroup pages 0-based du groupe
     * @param array<string, mixed>|null $analysis analyse de la premiere page du groupe
     * @param array<string, mixed>      $parentDoc
     *
     * @return array{filename: string, title: string}
     */
    private function buildSplitName(array $pageGroup, ?array $analysis, array $parentDoc): array
    {
        $first = ((int) $pageGroup[0]) + 1;
        $last = ((int) $pageGroup[count($pageGroup) - 1]) + 1;
        $pageLabel = $first === $last
            ? sprintf('p%03d', $first)
            : sprintf('p%03d-%03d', $first, $last);

        $date = null;
        if (!empty($analysis['date'])) {
            $ts = strtotime((string) $analysis['date']);
            if ($ts !== false) {
                $date = date('Y-m-d', $ts);
            }
        }

        $correspondent = $this->cleanNameSegment((string) ($analysis['correspondent'] ?? ''));
        $type = $this->cleanNameSegment((string) ($analysis['document_type'] ?? ''));

        $parts = array_values(array_filter([$date, $correspondent, $type]));
        if ($parts === []) {
            // Rien d'exploitable : on retombe sur le nom du lot d'origine.
            $parts[] = $this->cleanNameSegment(pathinfo((string) ($parentDoc['original_filename'] ?? 'document'), PATHINFO_FILENAME));
        }
        $parts[] = $pageLabel;

        $stem = implode('_', $parts);
        $filename = $stem . '.pdf';

        // Collision possible entre deux groupes du meme lot (meme date/correspondant/type).
        $target = $this->documentsPath . '/pending/' . $filename;
        if (file_exists($target)) {
            $filename = $stem . '_' . substr(uniqid(), -5) . '.pdf';
        }

        $titleParts = array_values(array_filter([
            $correspondent !== '' ? str_replace('_', ' ', $correspondent) : null,
            $type !== '' ? str_replace('_', ' ', $type) : null,
            $date !== null ? date('d.m.Y', strtotime($date)) : null,
        ]));
        $title = $titleParts === []
            ? pathinfo((string) ($parentDoc['original_filename'] ?? 'Document'), PATHINFO_FILENAME)
            : implode(' — ', $titleParts);

        $title .= $first === $last ? " (p. {$first})" : " (p. {$first}-{$last})";

        return ['filename' => $filename, 'title' => $title];
    }

    /**
     * Segment de nom de fichier sûr : accents aplatis, ponctuation en underscore.
     */
    private function cleanNameSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Table explicite : iconv('ASCII//TRANSLIT') depend de la libc et rend "d'ecompte"
        // sous Windows la ou glibc rend "decompte" — d'ou des noms type "d_ecompte".
        $value = strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'Ç' => 'C',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ñ' => 'N',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Æ' => 'AE', 'Œ' => 'OE',
        ]);
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        return mb_substr($value, 0, 40);
    }

    /**
     * Crée un nouveau document à partir d'un PDF séparé
     */
    private function createDocumentFromSplit(string $splitFile, array $parentDoc, array $pageGroup, ?array $analysis, int $parentId, string $splitMethod = 'none', ?string $content = null): ?int
    {
        try {
            // Copier le fichier vers un dossier pending/ pour les fichiers séparés en attente de validation
            // Ces fichiers n'ont pas d'original dans consume/, ils sont créés à partir d'un PDF parent
            $naming = $this->buildSplitName($pageGroup, $analysis, $parentDoc);
            $unique = $naming['filename'];
            $pendingPath = $this->documentsPath . '/pending';
            if (!is_dir($pendingPath)) {
                @mkdir($pendingPath, 0755, true);
            }
            $dest = $pendingPath . '/' . $unique;
            
            if (!copy($splitFile, $dest)) {
                throw new \Exception("Impossible de copier le fichier séparé");
            }
            
            // Créer le document
            $title = $naming['title'];
            
            // Filiation (parent_document_id, split_pages, split_method) : colonnes dédiées, pas
            // classification_suggestions — cette colonne JSON est écrasée à chaque classement
            // automatique de l'enfant (UnifiedClassifier), la filiation ne doit jamais y transiter.
            $stmt = $this->db->prepare("
                INSERT INTO documents (
                    title, filename, original_filename, file_path, file_size, mime_type,
                    checksum, status, parent_document_id, split_pages, split_method, uploaded_at, created_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW(), NOW(), NOW())
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
                json_encode($pageGroup),
                $splitMethod,
            ]);

            $newDocId = $this->db->lastInsertId();

            // Suggestions de classification (correspondant/type/date/montant devinés lors du split)
            $suggestions = [
                'correspondent' => $analysis['correspondent'] ?? null,
                'document_type' => $analysis['document_type'] ?? null,
                'date' => $analysis['date'] ?? null,
                'amount' => $analysis['amount'] ?? null,
            ];

            $this->db->prepare("UPDATE documents SET classification_suggestions = ? WHERE id = ?")
                ->execute([json_encode($suggestions), $newDocId]);

            // Contenu indexable herite des pages du groupe (OCR deja fait a l'analyse).
            if ($content !== null && $content !== '') {
                $stored = OCRService::truncateForTextColumn($content);
                $this->db->prepare('UPDATE documents SET content = ?, ocr_text = ?, ocr_status = ? WHERE id = ?')
                    ->execute([$stored, $stored, 'done', $newDocId]);
            }

            // Materialiser date / correspondant / type en colonnes : StoragePathGenerator
            // range sur `doc_date` et `correspondent_id`. Sans cela tout retombe dans
            // l'annee d'upload et aucun dossier de correspondant n'est cree.
            $this->applySplitMetadata((int) $newDocId, $analysis);

            // Supprimer le fichier temporaire
            @unlink($splitFile);
            
            return $newDocId;
        } catch (\Exception $e) {
            error_log("PDFSplitterService: Erreur création document depuis split: " . $e->getMessage());
            return null;
        }
    }
}
