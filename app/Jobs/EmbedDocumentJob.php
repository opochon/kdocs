<?php
/**
 * K-Docs - Embed Document Job
 * Async job to generate and store document embeddings
 */

namespace KDocs\Jobs;

use KDocs\Services\EmbeddingService;
use KDocs\Services\VectorSearchService;
use KDocs\Core\Database;

class EmbedDocumentJob
{
    private int $documentId;
    private string $action;

    public function __construct(int $documentId, string $action = 'upsert')
    {
        $this->documentId = $documentId;
        $this->action = $action; // 'upsert', 'delete'
    }

    /**
     * Execute the job
     */
    public function handle(): bool
    {
        $embeddingService = new EmbeddingService();
        $vectorService = new VectorSearchService();

        // Check if services are available
        if (!$embeddingService->isAvailable()) {
            error_log("EmbedDocumentJob: Embedding service not available");
            return false;
        }

        if (!$vectorService->isAvailable()) {
            error_log("EmbedDocumentJob: Qdrant not available");
            return false;
        }

        try {
            if ($this->action === 'delete') {
                return $this->handleDelete($vectorService);
            }

            return $this->handleUpsert($embeddingService, $vectorService);

        } catch (\Exception $e) {
            error_log("EmbedDocumentJob error: " . $e->getMessage());
            $this->markFailed($e->getMessage());
            return false;
        }
    }

    /**
     * Handle upsert (create or update)
     */
    private function handleUpsert(EmbeddingService $embeddingService, VectorSearchService $vectorService): bool
    {
        // Initialize collection if needed
        $vectorService->initializeCollection();

        // Generate embedding
        $embedding = $embeddingService->embedDocument($this->documentId);

        if (!$embedding) {
            error_log("EmbedDocumentJob: Failed to generate embedding for document {$this->documentId}");
            return false;
        }

        // Get document metadata
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT title, original_filename, document_type_id, correspondent_id
            FROM documents
            WHERE id = ?
        ");
        $stmt->execute([$this->documentId]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        $metadata = [
            'title' => $doc['title'] ?? '',
            'filename' => $doc['original_filename'] ?? '',
            'document_type_id' => $doc['document_type_id'],
            'correspondent_id' => $doc['correspondent_id'],
        ];

        // Upsert to Qdrant
        $success = $vectorService->upsertDocument($this->documentId, $embedding, $metadata);

        if ($success) {
            error_log("EmbedDocumentJob: Successfully embedded document {$this->documentId}");
        }

        return $success;
    }

    /**
     * Handle delete
     */
    private function handleDelete(VectorSearchService $vectorService): bool
    {
        $success = $vectorService->deleteDocument($this->documentId);

        if ($success) {
            error_log("EmbedDocumentJob: Successfully deleted embedding for document {$this->documentId}");
        }

        return $success;
    }

    /**
     * Mark document as failed
     */
    private function markFailed(string $error): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                UPDATE documents
                SET embedding_status = 'failed', embedding_error = ?
                WHERE id = ?
            ");
            $stmt->execute([$error, $this->documentId]);
        } catch (\Exception $e) {
            error_log("Failed to mark document as failed: " . $e->getMessage());
        }
    }

    /**
     * Queue this job for async processing
     */
    public static function dispatch(int $documentId, string $action = 'upsert'): void
    {
        try {
            $db = Database::getInstance();

            // Use the existing job queue table
            $stmt = $db->prepare("
                INSERT INTO embedding_jobs (document_id, priority, status)
                VALUES (?, 'normal', 'pending')
                ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0, created_at = NOW()
            ");
            $stmt->execute([$documentId]);

            // Also mark document as pending
            $stmt = $db->prepare("
                UPDATE documents
                SET embedding_status = 'pending'
                WHERE id = ? AND (embedding_status IS NULL OR embedding_status NOT IN ('processing'))
            ");
            $stmt->execute([$documentId]);

        } catch (\Exception $e) {
            error_log("Failed to dispatch EmbedDocumentJob: " . $e->getMessage());
        }
    }

    /**
     * Dispatch delete action
     */
    public static function dispatchDelete(int $documentId): void
    {
        try {
            // Delete immediately since document is being removed
            $vectorService = new VectorSearchService();
            if ($vectorService->isAvailable()) {
                $vectorService->deleteDocument($documentId);
            }
        } catch (\Exception $e) {
            error_log("Failed to delete embedding: " . $e->getMessage());
        }
    }

    /**
     * Process pending jobs from the queue
     */
    public static function processPending(int $limit = 10): array
    {
        $db = Database::getInstance();
        $processed = 0;
        $failed = 0;

        // Get pending jobs
        $stmt = $db->prepare("
            SELECT id, document_id
            FROM embedding_jobs
            WHERE status = 'pending'
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $jobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($jobs as $job) {
            // Mark as processing
            $updateStmt = $db->prepare("
                UPDATE embedding_jobs
                SET status = 'processing', started_at = NOW(), attempts = attempts + 1
                WHERE id = ?
            ");
            $updateStmt->execute([$job['id']]);

            // Process
            $embedJob = new self($job['document_id'], 'upsert');
            $success = $embedJob->handle();

            // Update status
            $updateStmt = $db->prepare("
                UPDATE embedding_jobs
                SET status = ?, completed_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([
                $success ? 'completed' : 'failed',
                $job['id']
            ]);

            if ($success) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($jobs),
        ];
    }
}
