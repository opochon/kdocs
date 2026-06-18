<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Core\Database;

/**
 * Mappe les réponses JSON du sidecar ClearMyDocs vers le schéma GED.
 */
class CmdResultMapper
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** @param array<string, mixed> $extract */
    public function applyExtract(int $documentId, array $extract): void
    {
        $text = trim((string) ($extract['text'] ?? ''));
        if ($text === '') {
            return;
        }

        $text = $this->sanitizeText($text);
        $mime = isset($extract['mime']) ? (string) $extract['mime'] : null;

        $sql = 'UPDATE documents SET content = ?, ocr_text = ?';
        $params = [$text, $text];

        if ($mime !== null && $mime !== '') {
            $sql .= ', mime_type = COALESCE(mime_type, ?)';
            $params[] = $mime;
        }

        $sql .= ' WHERE id = ?';
        $params[] = $documentId;

        $this->db->prepare($sql)->execute($params);
    }

    /**
     * @param array<string, mixed> $analyze
     *
     * @return bool true si confiance suffisante pour sauter UnifiedClassifier
     */
    public function applyAnalysis(int $documentId, array $analyze): bool
    {
        $confidence = (float) ($analyze['confidence'] ?? 0.0);
        $minConfidence = (float) env('IA_UNIFIED_MIN_CONFIDENCE', 0.75);
        $category = (string) ($analyze['category'] ?? 'autre');
        $tags = array_values(array_map('strval', (array) ($analyze['tags'] ?? [])));
        $entities = (array) ($analyze['entities'] ?? []);
        $summary = (string) ($analyze['summary'] ?? '');

        $payload = [
            'method_used' => 'clearmydocs_v3_sidecar',
            'clearmydocs' => [
                'category' => $category,
                'tags' => $tags,
                'entities' => $entities,
                'summary' => $summary,
                'confidence' => $confidence,
                'source' => (string) ($analyze['source'] ?? 'clearmydocs-classifier'),
            ],
            'final' => [
                'document_type_name' => $category,
                'tag_names' => $tags,
                'confidence' => $confidence,
                'external_ids' => [],
            ],
            'confidence' => $confidence,
            'should_review' => $confidence < $minConfidence,
            'pending_classification' => false,
            'classified_at' => date('c'),
        ];

        $this->db->prepare('UPDATE documents SET classification_suggestions = ? WHERE id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $documentId]);

        if ($tags !== []) {
            $additional = array_values(array_diff($tags, [$category]));
            if ($additional !== []) {
                $this->db->prepare('UPDATE documents SET ai_additional_categories = ? WHERE id = ?')
                    ->execute([json_encode($additional, JSON_UNESCAPED_UNICODE), $documentId]);
            }
        }

        return $confidence >= $minConfidence;
    }

    /**
     * @param list<int> $childDocumentIds
     * @param array<string, mixed> $segment
     */
    public function applySplitParent(int $documentId, array $segment, array $childDocumentIds): void
    {
        $payload = [
            'method_used' => 'clearmydocs_v3_split_parent',
            'split' => true,
            'child_document_ids' => $childDocumentIds,
            'detection' => $segment,
            'classified_at' => date('c'),
        ];

        $this->db->prepare('UPDATE documents SET classification_suggestions = ? WHERE id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $documentId]);
    }

    private function sanitizeText(string $content): string
    {
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content) ?? $content;
        $maxLength = 65000;
        if (mb_strlen($content) > $maxLength) {
            $content = mb_substr($content, 0, $maxLength);
        }

        return $content;
    }
}
