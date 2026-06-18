<?php

declare(strict_types=1);

namespace KDocs\Jobs;

use KDocs\Core\Database;
use KDocs\Services\Classification\IngestClassificationService;
use KDocs\Services\QueueService;

/**
 * Job async classify_document — traité par queue_worker ou fallback table dédiée.
 */
class ClassifyDocumentJob
{
    private int $documentId;

    public function __construct(int $documentId)
    {
        $this->documentId = $documentId;
    }

    public function handle(): bool
    {
        try {
            $service = new IngestClassificationService();
            $service->classify($this->documentId);
            return true;
        } catch (\Throwable $e) {
            error_log("ClassifyDocumentJob #{$this->documentId}: " . $e->getMessage());
            $this->markFailed($e->getMessage());
            return false;
        }
    }

    public static function dispatch(int $documentId): bool
    {
        if ($documentId <= 0) {
            return false;
        }

        if (class_exists(QueueService::class)) {
            return QueueService::queueClassification($documentId);
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO classification_jobs (document_id, status, created_at)
                VALUES (?, 'pending', NOW())
                ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0, created_at = NOW()
            ");
            $stmt->execute([$documentId]);
            return true;
        } catch (\Throwable $e) {
            error_log('ClassifyDocumentJob::dispatch fallback: ' . $e->getMessage());
            $job = new self($documentId);
            return $job->handle();
        }
    }

    /** @return array{processed: int, failed: int, total: int} */
    public static function processPending(int $limit = 10): array
    {
        $processed = 0;
        $failed = 0;

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT id, document_id
                FROM classification_jobs
                WHERE status = 'pending'
                ORDER BY created_at ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $jobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return ['processed' => 0, 'failed' => 0, 'total' => 0];
        }

        foreach ($jobs as $job) {
            $update = $db->prepare("
                UPDATE classification_jobs
                SET status = 'processing', started_at = NOW(), attempts = attempts + 1
                WHERE id = ?
            ");
            $update->execute([$job['id']]);

            $worker = new self((int) $job['document_id']);
            $success = $worker->handle();

            $update = $db->prepare("
                UPDATE classification_jobs
                SET status = ?, completed_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$success ? 'completed' : 'failed', $job['id']]);

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

    private function markFailed(string $error): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('SELECT classification_suggestions FROM documents WHERE id = ?');
            $stmt->execute([$this->documentId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $suggestions = json_decode($row['classification_suggestions'] ?? '{}', true);
            if (!is_array($suggestions)) {
                $suggestions = [];
            }
            $suggestions['pending_classification'] = false;
            $suggestions['classification_error'] = $error;
            $suggestions['failed_at'] = date('c');

            $db->prepare('UPDATE documents SET classification_suggestions = ? WHERE id = ?')
                ->execute([json_encode($suggestions, JSON_UNESCAPED_UNICODE), $this->documentId]);
        } catch (\Throwable $e) {
            error_log('ClassifyDocumentJob::markFailed: ' . $e->getMessage());
        }
    }
}
