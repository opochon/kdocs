<?php



declare(strict_types=1);



namespace KDocs\Services\Classification;



use KDocs\Contracts\PdfSplitInterface;
use KDocs\Core\Config;
use KDocs\Core\Database;
use KDocs\DTO\ClassificationResult;
use KDocs\Jobs\ClassifyDocumentJob;
use KDocs\Models\Document;

use KDocs\Services\Audit\ClassificationAuditService;
use KDocs\Services\Classifiers\UnifiedClassifier;

use KDocs\Services\PdfSplit\PdfSplitService;
use KDocs\Services\QueueService;



/**

 * Point d'accroche unique ingest : classification + split PDF + persistance.

 */

class IngestClassificationService

{

    private UnifiedClassifier $classifier;

    private PdfSplitInterface $pdfSplit;

    private TaxonomySyncService $taxonomySync;



    public function __construct(

        ?UnifiedClassifier $classifier = null,

        ?PdfSplitInterface $pdfSplit = null,

        ?TaxonomySyncService $taxonomySync = null

    ) {

        $this->classifier = $classifier ?? UnifiedClassifier::createConfigured();

        $this->pdfSplit = $pdfSplit ?? new PdfSplitService();

        $this->taxonomySync = $taxonomySync ?? new TaxonomySyncService();

    }



    /**

     * Enfile la classification (non bloquant HTTP).

     */

    public function queue(int $documentId): bool

    {

        if ($documentId <= 0) {

            return false;

        }



        $this->markPending($documentId);

        // Best-effort : job_queue_jobs sert d'historique, mais rien ne le draine sur ce poste
        // (aucun ordonnanceur actif). n0nag0n\Job_Queue peut même être absent (Error non catché
        // par QueueService) : on ne laisse jamais cette dépendance faire échouer l'ingestion.
        try {
            if (class_exists(QueueService::class) && QueueService::queueClassification($documentId)) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log('IngestClassificationService::queue queueClassification: ' . $e->getMessage());
        }

        return (new ClassifyDocumentJob($documentId))->handle();
    }



    /**

     * Exécute la classification ingest (appelé par le worker ou API test).

     *

     * @return array<string, mixed>

     */

    public function classify(int $documentId): array

    {

        $document = Document::findById($documentId);

        if (!$document) {

            throw new \RuntimeException("Document introuvable: {$documentId}");

        }



        $ocrText = trim((string) ($document['content'] ?? $document['ocr_text'] ?? ''));

        $result = [

            'document_id' => $documentId,

            'split' => false,

            'classification' => null,

            'child_documents' => [],

        ];



        if ($this->shouldAttemptPdfSplit($document)) {

            $splitOutcome = $this->handlePdfSplit($documentId);

            $result = array_merge($result, $splitOutcome);

            if (!empty($splitOutcome['split'])) {

                return $result;

            }

        }



        $classification = $this->runClassifier($document, $ocrText);

        $this->persistClassification($documentId, $classification);

        $result['classification'] = $classification->toArray();



        return $result;

    }



    /** @param array<string, mixed> $document */

    private function shouldAttemptPdfSplit(array $document): bool

    {

        if (!filter_var(env('IA_PDF_SPLIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {

            return false;

        }



        return ($document['mime_type'] ?? '') === 'application/pdf';

    }



    /** @return array<string, mixed> */

    private function handlePdfSplit(int $documentId): array

    {

        $detection = $this->pdfSplit->detectPageGroups($documentId);

        if (empty($detection['should_split'])) {

            return ['split' => false, 'detection' => $detection];

        }



        $childIds = $this->pdfSplit->split($documentId);

        if ($childIds === null || $childIds === []) {

            return ['split' => false, 'detection' => $detection, 'split_error' => 'split_failed'];

        }



        foreach ($childIds as $childId) {

            $this->queue((int) $childId);

        }



        $db = Database::getInstance();

        $db->prepare('UPDATE documents SET classification_suggestions = ? WHERE id = ?')

            ->execute([

                json_encode([

                    'method_used' => 'pdf_split_parent',

                    'split' => true,

                    'child_document_ids' => $childIds,

                    'detection' => $detection,

                    'classified_at' => date('c'),

                ], JSON_UNESCAPED_UNICODE),

                $documentId,

            ]);



        return [

            'split' => true,

            'detection' => $detection,

            'child_documents' => $childIds,

        ];

    }



    /** @param array<string, mixed> $document */

    private function runClassifier(array $document, string $ocrText): ClassificationResult

    {

        $stored = $this->taxonomySync->getStored();

        if ($stored !== null && !empty($stored['taxonomy'])) {

            $document['taxonomy_hint'] = $stored['synced_at'] ?? null;

        }



        return $this->classifier->classifyDocument($document, $ocrText !== '' ? $ocrText : null);

    }



    private function persistClassification(int $documentId, ClassificationResult $classification): void

    {

        $payload = $classification->toPersistencePayload();

        $payload['pending_classification'] = false;



        $db = Database::getInstance();

        $db->prepare('UPDATE documents SET classification_suggestions = ? WHERE id = ?')

            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $documentId]);



        $this->applyCategoryToDocumentType($documentId, $classification);



        if ($classification->tags !== []) {

            $additional = array_values(array_diff($classification->tags, [$classification->category ?? '']));

            if ($additional !== []) {

                $db->prepare('UPDATE documents SET ai_additional_categories = ? WHERE id = ?')

                    ->execute([json_encode($additional, JSON_UNESCAPED_UNICODE), $documentId]);

            }

        }

    }



    /**
     * Tri auto au-dessus du seuil (classement appliqué + tracé dans classification_audit_log),
     * sinon suggestion tracée dans classification_suggestions — jamais de type imposé sous le seuil.
     * Seuil et flag auto_apply : config('classification.*'), fixés par Olivier — non modifiés ici.
     */
    private function applyCategoryToDocumentType(int $documentId, ClassificationResult $classification): void
    {
        $db = Database::getInstance();
        $typeId = $this->resolveDocumentTypeId($db, $classification);
        if ($typeId === null) {
            return;
        }

        $autoApply = filter_var(Config::get('classification.auto_apply', false), FILTER_VALIDATE_BOOLEAN);
        $threshold = (float) Config::get('classification.auto_apply_threshold', 0.8);

        if ($autoApply && $classification->confidence >= $threshold) {
            $stmt = $db->prepare('SELECT document_type_id FROM documents WHERE id = ?');
            $stmt->execute([$documentId]);
            $previousTypeId = $stmt->fetchColumn();

            $db->prepare(
                'UPDATE documents SET document_type_id = ?, classification_confidence = ?, last_classified_at = NOW(), last_classified_by = ?, updated_at = NOW() WHERE id = ?'
            )->execute([$typeId, $classification->confidence, 'ai', $documentId]);

            (new ClassificationAuditService())->log(
                $documentId,
                'document_type_id',
                $previousTypeId !== false ? $previousTypeId : null,
                $typeId,
                'ai',
                ['reason' => 'auto_apply confidence=' . $classification->confidence . ' source=' . $classification->source]
            );

            return;
        }

        // Sous le seuil : suggestion tracée, aucun type imposé au document.
        try {
            $db->prepare(
                'INSERT INTO classification_suggestions
                    (document_id, field_code, suggested_value, confidence, source, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([$documentId, 'document_type_id', (string) $typeId, $classification->confidence, 'ai', 'pending']);
        } catch (\Throwable $e) {
            error_log('IngestClassificationService::applyCategoryToDocumentType suggestion: ' . $e->getMessage());
        }
    }

    /** Résout le document_type_id suggéré, quel que soit l'adaptateur source (suggestions plates, imbriquées, ou label). */
    private function resolveDocumentTypeId(\PDO $db, ClassificationResult $classification): ?int
    {
        $candidate = $classification->suggestions['document_type_id']
            ?? $classification->suggestions['matched']['document_type_id']
            ?? null;

        if (is_numeric($candidate) && (int) $candidate > 0) {
            $stmt = $db->prepare('SELECT id FROM document_types WHERE id = ?');
            $stmt->execute([(int) $candidate]);
            if ($stmt->fetchColumn()) {
                return (int) $candidate;
            }
        }

        $category = trim((string) ($classification->category ?? ''));
        if ($category === '') {
            return null;
        }

        $stmt = $db->prepare('SELECT id FROM document_types WHERE LOWER(label) = LOWER(?) LIMIT 1');
        $stmt->execute([$category]);
        $typeId = $stmt->fetchColumn();

        return $typeId ? (int) $typeId : null;
    }



    private function markPending(int $documentId): void

    {

        try {

            $db = Database::getInstance();

            $stmt = $db->prepare('SELECT classification_suggestions FROM documents WHERE id = ?');

            $stmt->execute([$documentId]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {

                return;

            }



            $suggestions = json_decode($row['classification_suggestions'] ?? '{}', true);

            if (!is_array($suggestions)) {

                $suggestions = [];

            }

            $suggestions['pending_classification'] = true;

            $suggestions['queued_at'] = date('c');



            $db->prepare('UPDATE documents SET classification_suggestions = ? WHERE id = ?')

                ->execute([json_encode($suggestions, JSON_UNESCAPED_UNICODE), $documentId]);

        } catch (\Throwable $e) {

            error_log('IngestClassificationService::markPending: ' . $e->getMessage());

        }

    }

}

