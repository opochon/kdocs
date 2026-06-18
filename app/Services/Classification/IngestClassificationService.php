<?php



declare(strict_types=1);



namespace KDocs\Services\Classification;



use KDocs\Contracts\PdfSplitInterface;
use KDocs\Core\Database;
use KDocs\DTO\ClassificationResult;
use KDocs\Jobs\ClassifyDocumentJob;
use KDocs\Models\Document;

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

        if (class_exists(QueueService::class) && QueueService::queueClassification($documentId)) {
            return true;
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



        if ($classification->tags !== []) {

            $additional = array_values(array_diff($classification->tags, [$classification->category ?? '']));

            if ($additional !== []) {

                $db->prepare('UPDATE documents SET ai_additional_categories = ? WHERE id = ?')

                    ->execute([json_encode($additional, JSON_UNESCAPED_UNICODE), $documentId]);

            }

        }

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

