<?php
/**
 * Tests for SearchService class
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Search\SearchQuery;
use KDocs\Search\SearchResult;

class SearchServiceTest extends TestCase
{
    public function testSearchQueryDefaultValues(): void
    {
        $query = new SearchQuery();

        $this->assertNull($query->text);
        $this->assertEquals(1, $query->page);
        $this->assertEquals(25, $query->perPage);
        $this->assertEquals('DESC', $query->orderDir);
    }

    public function testSearchQuerySetsText(): void
    {
        $query = new SearchQuery();
        $query->text = 'facture électricité';

        $this->assertEquals('facture électricité', $query->text);
    }

    public function testSearchQueryPagination(): void
    {
        $query = SearchQuery::fromArray([
            'page' => 3,
            'per_page' => 10,
        ]);

        $this->assertEquals(3, $query->page);
        $this->assertEquals(10, $query->perPage);
        $this->assertEquals(20, $query->offset);
    }

    public function testSearchQueryFilters(): void
    {
        $query = new SearchQuery();
        $query->correspondentId = 5;
        $query->documentTypeId = 3;
        $query->tagIds = [1, 2, 3];

        $this->assertEquals(5, $query->correspondentId);
        $this->assertEquals(3, $query->documentTypeId);
        $this->assertEquals([1, 2, 3], $query->tagIds);
    }

    public function testSearchQueryDateFilters(): void
    {
        $query = new SearchQuery();
        $query->createdAfter = '2024-01-01';
        $query->createdBefore = '2024-12-31';

        $this->assertEquals('2024-01-01', $query->createdAfter);
        $this->assertEquals('2024-12-31', $query->createdBefore);
    }

    public function testSearchResultDefaultValues(): void
    {
        $result = new SearchResult();

        $this->assertEquals([], $result->documents);
        $this->assertEquals(0, $result->total);
        $this->assertEquals(1, $result->page);
        $this->assertEquals(25, $result->perPage);
        $this->assertEquals(0, $result->totalPages);
    }

    public function testSearchResultPagination(): void
    {
        $result = new SearchResult();
        $result->total = 100;
        $result->perPage = 25;
        $result->totalPages = (int) ceil($result->total / $result->perPage);

        $this->assertEquals(4, $result->totalPages);
    }

    public function testSearchResultWithFacets(): void
    {
        $result = new SearchResult();
        $result->correspondentFacets = [
            ['id' => 1, 'name' => 'EDF', 'count' => 10],
            ['id' => 2, 'name' => 'Orange', 'count' => 5],
        ];

        $this->assertCount(2, $result->correspondentFacets);
        $this->assertEquals('EDF', $result->correspondentFacets[0]['name']);
    }

    public function testSearchResultSearchTime(): void
    {
        $result = new SearchResult();
        $result->searchTime = 0.125;

        $this->assertEquals(0.125, $result->searchTime);
    }

    public function testSearchQueryFromArray(): void
    {
        $query = SearchQuery::fromArray([
            'q' => 'facture',
            'correspondent_id' => 5,
            'page' => 2,
            'per_page' => 50,
        ]);

        $this->assertEquals('facture', $query->text);
        $this->assertEquals(5, $query->correspondentId);
        $this->assertEquals(2, $query->page);
        $this->assertEquals(50, $query->perPage);
    }

    public function testSearchQueryToArray(): void
    {
        $query = new SearchQuery();
        $query->text = 'test';
        $query->correspondentId = 3;

        $array = $query->toArray();

        $this->assertEquals('test', $array['text']);
        $this->assertEquals(3, $array['correspondent_id']);
    }

    public function testSearchResultHasResults(): void
    {
        $result = new SearchResult();
        $this->assertFalse($result->hasResults());

        $result->total = 5;
        $this->assertTrue($result->hasResults());
    }

    public function testSearchResultHasMorePages(): void
    {
        $result = new SearchResult();
        $result->page = 1;
        $result->totalPages = 3;

        $this->assertTrue($result->hasMorePages());

        $result->page = 3;
        $this->assertFalse($result->hasMorePages());
    }

    public function testSearchResultHasPreviousPage(): void
    {
        $result = new SearchResult();
        $result->page = 1;

        $this->assertFalse($result->hasPreviousPage());

        $result->page = 2;
        $this->assertTrue($result->hasPreviousPage());
    }

    public function testSearchResultToArray(): void
    {
        $result = new SearchResult();
        $result->total = 100;
        $result->documents = [['id' => 1, 'title' => 'Test']];
        $result->searchTime = 0.05;

        $array = $result->toArray();

        $this->assertEquals(100, $array['total']);
        $this->assertCount(1, $array['documents']);
        $this->assertEquals(0.05, $array['search_time']);
        $this->assertArrayHasKey('facets', $array);
        $this->assertArrayHasKey('aggregations', $array);
    }

    public function testSearchQueryPerPageLimits(): void
    {
        // Max 100
        $query = SearchQuery::fromArray(['per_page' => 200]);
        $this->assertEquals(100, $query->perPage);

        // Min 1
        $query = SearchQuery::fromArray(['per_page' => 0]);
        $this->assertEquals(1, $query->perPage);
    }

    public function testSearchQueryPageMinimum(): void
    {
        $query = SearchQuery::fromArray(['page' => 0]);
        $this->assertEquals(1, $query->page);

        $query = SearchQuery::fromArray(['page' => -5]);
        $this->assertEquals(1, $query->page);
    }
}
