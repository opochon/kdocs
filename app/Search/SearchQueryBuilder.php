<?php
/**
 * K-Docs - SearchQueryBuilder
 * Builder fluide pour construire des requêtes de recherche
 */

namespace KDocs\Search;

class SearchQueryBuilder
{
    private SearchQuery $query;
    
    public function __construct()
    {
        $this->query = new SearchQuery();
    }
    
    public static function create(): self
    {
        return new self();
    }
    
    public function whereText(string $text): self
    {
        $this->query->text = $text;
        return $this;
    }
    
    public function whereCorrespondent(int $id): self
    {
        $this->query->correspondentId = $id;
        return $this;
    }
    
    public function whereCorrespondentName(string $name): self
    {
        $this->query->correspondentName = $name;
        return $this;
    }
    
    public function whereDocumentType(int $id): self
    {
        $this->query->documentTypeId = $id;
        return $this;
    }
    
    public function whereDocumentTypeName(string $name): self
    {
        $this->query->documentTypeName = $name;
        return $this;
    }
    
    public function whereHasTag(int $tagId): self
    {
        if (!in_array($tagId, $this->query->tagIds)) {
            $this->query->tagIds[] = $tagId;
        }
        return $this;
    }
    
    public function whereHasTags(array $tagIds, bool $matchAll = false): self
    {
        $this->query->tagIds = array_unique(array_merge($this->query->tagIds, $tagIds));
        $this->query->tagsMatchAll = $matchAll;
        return $this;
    }
    
    public function whereTagName(string $name): self
    {
        if (!in_array($name, $this->query->tagNames)) {
            $this->query->tagNames[] = $name;
        }
        return $this;
    }
    
    public function whereCreatedAfter(string $date): self
    {
        $this->query->createdAfter = $date;
        return $this;
    }
    
    public function whereCreatedBefore(string $date): self
    {
        $this->query->createdBefore = $date;
        return $this;
    }
    
    public function whereCreatedBetween(string $start, string $end): self
    {
        $this->query->createdAfter = $start;
        $this->query->createdBefore = $end;
        return $this;
    }
    
    public function whereAddedAfter(string $date): self
    {
        $this->query->addedAfter = $date;
        return $this;
    }
    
    public function whereAddedBefore(string $date): self
    {
        $this->query->addedBefore = $date;
        return $this;
    }
    
    public function whereAmountMin(float $amount): self
    {
        $this->query->amountMin = $amount;
        return $this;
    }
    
    public function whereAmountMax(float $amount): self
    {
        $this->query->amountMax = $amount;
        return $this;
    }
    
    public function whereAmountBetween(float $min, float $max): self
    {
        $this->query->amountMin = $min;
        $this->query->amountMax = $max;
        return $this;
    }
    
    public function whereCategory(string $category): self
    {
        $this->query->category = $category;
        return $this;
    }
    
    public function whereMimeType(string $mimeType): self
    {
        $this->query->mimeType = $mimeType;
        return $this;
    }
    
    public function whereHasContent(): self
    {
        $this->query->hasContent = true;
        return $this;
    }
    
    public function whereOwner(int $ownerId): self
    {
        $this->query->ownerId = $ownerId;
        return $this;
    }
    
    public function orderBy(string $field, string $direction = 'DESC'): self
    {
        $allowedFields = ['created_at', 'added_at', 'modified_at', 'title', 'asn', 'relevance'];
        if (in_array($field, $allowedFields)) {
            $this->query->orderBy = $field;
        }
        $this->query->orderDir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        return $this;
    }
    
    public function page(int $page, int $perPage = 25): self
    {
        $this->query->page = max(1, $page);
        $this->query->perPage = min(100, max(1, $perPage));
        $this->query->offset = ($this->query->page - 1) * $this->query->perPage;
        return $this;
    }
    
    public function withFacets(bool $enabled = true): self
    {
        $this->query->withFacets = $enabled;
        return $this;
    }
    
    public function withAggregations(array $aggregations = []): self
    {
        $this->query->withAggregations = true;
        $this->query->aggregations = $aggregations;
        return $this;
    }
    
    public function build(): SearchQuery
    {
        return $this->query;
    }
    
    public function getQuery(): SearchQuery
    {
        return $this->query;
    }
}
