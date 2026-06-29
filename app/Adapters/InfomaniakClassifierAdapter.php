<?php

declare(strict_types=1);

namespace KDocs\Adapters;

use KDocs\Contracts\ClassifierInterface;
use KDocs\Core\Database;
use KDocs\Services\InfomaniakAIService;

/**
 * Classification documentaire via Infomaniak AI Tools (cloud CH).
 *
 * Remplace Claude lorsque INFOMANIAK_AI_ENABLED + clé + secret (product_id) sont renseignés.
 *
 * @see docs/INFOMANIAK-AI-CONNECTOR.md
 */
class InfomaniakClassifierAdapter implements ClassifierInterface
{
    private InfomaniakAIService $ai;

    public function __construct(?InfomaniakAIService $ai = null)
    {
        $this->ai = $ai ?? new InfomaniakAIService();
    }

    public function getName(): string
    {
        return 'infomaniak-ai';
    }

    public function isAvailable(): bool
    {
        return $this->ai->isAvailable();
    }

    public function classify(array $context): array
    {
        if (!$this->isAvailable()) {
            return $this->emptyResult('not_available');
        }

        $text = trim((string) ($context['text'] ?? ''));
        if ($text === '') {
            return $this->emptyResult('missing_text');
        }

        $filename = (string) ($context['original_filename'] ?? $context['file_path'] ?? 'document');
        $prompt = $this->buildClassificationPrompt($text, $filename, $context);
        $response = $this->ai->complete($prompt, [
            'max_tokens' => 1500,
            'temperature' => 0.1,
            'system' => 'Tu analyses des documents pour une GED fiduciaire suisse. Réponds UNIQUEMENT en JSON valide.',
        ]);

        if ($response === null || ($response['text'] ?? '') === '') {
            return $this->emptyResult('infomaniak_error');
        }

        $parsed = InfomaniakAIService::parseJsonResponse($response['text']);
        if ($parsed === null) {
            return $this->emptyResult('invalid_json');
        }

        $confidence = (float) ($parsed['confidence'] ?? 0.75);
        $tagNames = array_values(array_map('strval', $parsed['new_tags'] ?? $parsed['tags'] ?? []));

        return [
            'category' => $parsed['title'] ?? $parsed['document_type_name'] ?? null,
            'tags' => $tagNames,
            'externalIds' => [],
            'suggestions' => [
                'document_type_id' => $parsed['document_type_id'] ?? null,
                'correspondent_id' => $parsed['correspondent_id'] ?? null,
                'correspondent_name' => $parsed['correspondent_name'] ?? null,
                'tag_ids' => $parsed['tag_ids'] ?? [],
                'new_tags' => $parsed['new_tags'] ?? [],
                'title' => $parsed['title'] ?? null,
                'document_date' => $parsed['document_date'] ?? null,
                'amount' => $parsed['amount'] ?? null,
                'summary' => $parsed['summary'] ?? null,
            ],
            'confidence' => min(0.99, max(0.0, $confidence)),
            'source' => $this->getName(),
            'raw' => [
                'provider' => $response['provider'] ?? 'infomaniak',
                'model' => $response['model'] ?? null,
                'parsed' => $parsed,
            ],
            'audit' => [
                'adapter' => 'InfomaniakClassifierAdapter',
                'document_id' => $context['document_id'] ?? null,
            ],
        ];
    }

    public function syncTaxonomy(): array
    {
        $health = $this->ai->health();

        return [
            'source' => $this->getName(),
            'status' => ($health['ok'] ?? false) ? 'connected' : 'unavailable',
            'products' => $health['products'] ?? [],
            'model' => $this->ai->getModel(),
        ];
    }

    /** @param array<string, mixed> $context */
    private function buildClassificationPrompt(string $content, string $filename, array $context): string
    {
        $db = Database::getInstance();

        $types = $db->query('SELECT id, label FROM document_types ORDER BY label')->fetchAll(\PDO::FETCH_ASSOC);
        $correspondents = $db->query('SELECT id, name FROM correspondents ORDER BY name LIMIT 50')->fetchAll(\PDO::FETCH_ASSOC);
        $tags = $db->query('SELECT id, name FROM tags ORDER BY name LIMIT 50')->fetchAll(\PDO::FETCH_ASSOC);

        $typesList = implode(', ', array_map(static fn(array $t): string => "{$t['label']} (ID:{$t['id']})", $types));
        $corrList = implode(', ', array_map(static fn(array $c): string => "{$c['name']} (ID:{$c['id']})", $correspondents));
        $tagsList = implode(', ', array_map(static fn(array $t): string => "{$t['name']} (ID:{$t['id']})", $tags));

        $contentPreview = mb_substr($content, 0, 3000);
        $taxonomyHint = '';
        if (!empty($context['taxonomy']) && is_array($context['taxonomy'])) {
            $taxonomyHint = "\nTaxonomie projet (indices) : " . json_encode($context['taxonomy'], JSON_UNESCAPED_UNICODE);
        }

        return <<<PROMPT
Analyse ce document et propose une classification.

Fichier: {$filename}
Contenu:
{$contentPreview}
{$taxonomyHint}

Types disponibles: {$typesList}
Correspondants existants: {$corrList}
Tags existants: {$tagsList}

Réponds UNIQUEMENT en JSON valide avec ce format:
{
  "document_type_id": <int ou null>,
  "correspondent_id": <int ou null>,
  "correspondent_name": "<nom si nouveau>",
  "tag_ids": [<int>, ...],
  "new_tags": ["<nom>", ...],
  "title": "<titre suggéré>",
  "document_date": "<YYYY-MM-DD ou null>",
  "amount": <decimal ou null>,
  "summary": "<résumé 2-3 phrases>",
  "confidence": <0.0-1.0>
}
PROMPT;
    }

    /** @return array<string, mixed> */
    private function emptyResult(string $reason): array
    {
        return [
            'category' => null,
            'tags' => [],
            'externalIds' => [],
            'suggestions' => [],
            'confidence' => 0.0,
            'source' => $this->getName(),
            'raw' => ['reason' => $reason],
            'audit' => ['adapter' => 'InfomaniakClassifierAdapter', 'reason' => $reason],
        ];
    }
}
