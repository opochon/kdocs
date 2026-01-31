<?php
/**
 * K-Docs - SearchResult
 * Résultat d'une recherche avec facets et aggregations
 */

namespace KDocs\Search;

class SearchResult
{
    /** @var array */
    public array $documents = [];
    public int $total = 0;
    public int $page = 1;
    public int $perPage = 25;
    public int $totalPages = 0;
    
    // Facets
    public array $correspondentFacets = [];
    public array $documentTypeFacets = [];
    public array $tagFacets = [];
    public array $yearFacets = [];
    public array $categoryFacets = [];
    
    // Aggregations
    public ?float $totalAmount = null;
    public ?float $avgAmount = null;
    public ?int $documentCount = null;
    
    // Search metadata
    public float $searchTime = 0.0;
    public ?string $query = null;
    public ?string $aiResponse = null;
    public bool $semanticUsed = false;
    public ?string $error = null;
    
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total_pages' => $this->totalPages,
            'facets' => [
                'correspondents' => $this->correspondentFacets,
                'document_types' => $this->documentTypeFacets,
                'tags' => $this->tagFacets,
                'years' => $this->yearFacets,
                'categories' => $this->categoryFacets,
            ],
            'aggregations' => [
                'total_amount' => $this->totalAmount,
                'avg_amount' => $this->avgAmount,
                'document_count' => $this->documentCount,
            ],
            'search_time' => $this->searchTime,
            'query' => $this->query,
            'ai_response' => $this->aiResponse,
            'semantic_used' => $this->semanticUsed,
            'error' => $this->error,
        ];
    }
    
    public function hasResults(): bool
    {
        return $this->total > 0;
    }
    
    public function hasMorePages(): bool
    {
        return $this->page < $this->totalPages;
    }
    
    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }
}
