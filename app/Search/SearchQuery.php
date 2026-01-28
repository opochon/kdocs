<?php
/**
 * K-Docs - SearchQuery
 * Objet représentant une requête de recherche
 */

namespace KDocs\Search;

class SearchQuery
{
    public ?string $text = null;
    public ?int $correspondentId = null;
    public ?string $correspondentName = null;
    public ?int $documentTypeId = null;
    public ?string $documentTypeName = null;
    public array $tagIds = [];
    public array $tagNames = [];
    public bool $tagsMatchAll = false;
    public ?string $createdAfter = null;
    public ?string $createdBefore = null;
    public ?string $addedAfter = null;
    public ?string $addedBefore = null;
    public ?float $amountMin = null;
    public ?float $amountMax = null;
    public ?string $category = null;
    public ?string $mimeType = null;
    public bool $hasContent = false;
    public ?int $ownerId = null;

    // Advanced search options
    public string $searchScope = 'all'; // 'name', 'content', 'all'
    public ?int $folderId = null;       // Search in specific folder
    public ?string $dateFrom = null;    // Date range start
    public ?string $dateTo = null;      // Date range end

    // Sorting
    public string $orderBy = 'created_at';
    public string $orderDir = 'DESC';

    // Pagination
    public int $page = 1;
    public int $perPage = 25;
    public int $offset = 0;

    // Aggregations
    public bool $withFacets = true;
    public bool $withAggregations = false;
    public array $aggregations = [];

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'correspondent_id' => $this->correspondentId,
            'correspondent_name' => $this->correspondentName,
            'document_type_id' => $this->documentTypeId,
            'document_type_name' => $this->documentTypeName,
            'tag_ids' => $this->tagIds,
            'tag_names' => $this->tagNames,
            'tags_match_all' => $this->tagsMatchAll,
            'created_after' => $this->createdAfter,
            'created_before' => $this->createdBefore,
            'added_after' => $this->addedAfter,
            'added_before' => $this->addedBefore,
            'amount_min' => $this->amountMin,
            'amount_max' => $this->amountMax,
            'category' => $this->category,
            'mime_type' => $this->mimeType,
            'has_content' => $this->hasContent,
            'owner_id' => $this->ownerId,
            'search_scope' => $this->searchScope,
            'folder_id' => $this->folderId,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'order_by' => $this->orderBy,
            'order_dir' => $this->orderDir,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];
    }

    public static function fromArray(array $data): self
    {
        $query = new self();

        $query->text = $data['text'] ?? $data['q'] ?? null;
        $query->correspondentId = isset($data['correspondent_id']) ? (int) $data['correspondent_id'] : null;
        $query->correspondentName = $data['correspondent_name'] ?? null;
        $query->documentTypeId = isset($data['document_type_id']) ? (int) $data['document_type_id'] : null;
        $query->documentTypeName = $data['document_type_name'] ?? null;
        $query->tagIds = $data['tag_ids'] ?? [];
        $query->tagNames = $data['tag_names'] ?? [];
        $query->tagsMatchAll = $data['tags_match_all'] ?? false;
        $query->createdAfter = $data['created_after'] ?? null;
        $query->createdBefore = $data['created_before'] ?? null;
        $query->addedAfter = $data['added_after'] ?? null;
        $query->addedBefore = $data['added_before'] ?? null;
        $query->amountMin = isset($data['amount_min']) ? (float) $data['amount_min'] : null;
        $query->amountMax = isset($data['amount_max']) ? (float) $data['amount_max'] : null;
        $query->category = $data['category'] ?? null;
        $query->mimeType = $data['mime_type'] ?? null;
        $query->hasContent = $data['has_content'] ?? false;
        $query->ownerId = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
        $query->searchScope = $data['search_scope'] ?? $data['scope'] ?? 'all';
        $query->folderId = isset($data['folder_id']) ? (int) $data['folder_id'] : null;
        $query->dateFrom = $data['date_from'] ?? null;
        $query->dateTo = $data['date_to'] ?? null;
        $query->orderBy = $data['order_by'] ?? $data['sort'] ?? 'created_at';
        $query->orderDir = strtoupper($data['order_dir'] ?? $data['sort_dir'] ?? 'DESC');
        $query->page = max(1, (int) ($data['page'] ?? 1));
        $query->perPage = min(100, max(1, (int) ($data['per_page'] ?? $data['limit'] ?? 25)));
        $query->offset = ($query->page - 1) * $query->perPage;
        $query->withFacets = $data['with_facets'] ?? true;
        $query->withAggregations = $data['with_aggregations'] ?? false;
        $query->aggregations = $data['aggregations'] ?? [];

        return $query;
    }
}
