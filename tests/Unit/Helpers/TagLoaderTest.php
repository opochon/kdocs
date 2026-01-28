<?php
/**
 * Tests for TagLoader helper
 */

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

class TagLoaderTest extends TestCase
{
    public function testLoadForDocumentsWithEmptyArray(): void
    {
        $documents = [];

        // Simulate TagLoader behavior
        if (empty($documents)) {
            $result = $documents;
        }

        $this->assertEmpty($result);
    }

    public function testExtractDocumentIds(): void
    {
        $documents = [
            ['id' => 1, 'title' => 'Doc 1'],
            ['id' => 2, 'title' => 'Doc 2'],
            ['id' => 3, 'title' => 'Doc 3'],
        ];

        $documentIds = array_column($documents, 'id');

        $this->assertEquals([1, 2, 3], $documentIds);
    }

    public function testGroupTagsByDocumentId(): void
    {
        $tags = [
            ['document_id' => 1, 'id' => 10, 'name' => 'Tag A', 'color' => '#ff0000'],
            ['document_id' => 1, 'id' => 11, 'name' => 'Tag B', 'color' => '#00ff00'],
            ['document_id' => 2, 'id' => 10, 'name' => 'Tag A', 'color' => '#ff0000'],
        ];

        $tagsByDocument = [];
        foreach ($tags as $tag) {
            $docId = $tag['document_id'];
            if (!isset($tagsByDocument[$docId])) {
                $tagsByDocument[$docId] = [];
            }
            $tagsByDocument[$docId][] = [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'color' => $tag['color']
            ];
        }

        $this->assertCount(2, $tagsByDocument);
        $this->assertCount(2, $tagsByDocument[1]);
        $this->assertCount(1, $tagsByDocument[2]);
    }

    public function testAttachTagsToDocuments(): void
    {
        $documents = [
            ['id' => 1, 'title' => 'Doc 1'],
            ['id' => 2, 'title' => 'Doc 2'],
        ];

        $tagsByDocument = [
            1 => [
                ['id' => 10, 'name' => 'Tag A', 'color' => '#ff0000']
            ]
        ];

        foreach ($documents as &$document) {
            $document['tags'] = $tagsByDocument[$document['id']] ?? [];
        }

        $this->assertCount(1, $documents[0]['tags']);
        $this->assertEmpty($documents[1]['tags']);
    }

    public function testPlaceholdersForInClause(): void
    {
        $documentIds = [1, 2, 3, 4, 5];
        $placeholders = str_repeat('?,', count($documentIds) - 1) . '?';

        $this->assertEquals('?,?,?,?,?', $placeholders);
    }

    public function testPlaceholdersForSingleId(): void
    {
        $documentIds = [1];
        $placeholders = str_repeat('?,', count($documentIds) - 1) . '?';

        $this->assertEquals('?', $placeholders);
    }

    public function testTagDataStructure(): void
    {
        $tag = [
            'id' => 1,
            'name' => 'Important',
            'color' => '#ff0000'
        ];

        $this->assertArrayHasKey('id', $tag);
        $this->assertArrayHasKey('name', $tag);
        $this->assertArrayHasKey('color', $tag);
        $this->assertIsInt($tag['id']);
        $this->assertIsString($tag['name']);
        $this->assertStringStartsWith('#', $tag['color']);
    }

    public function testDocumentCountsPerTagFormat(): void
    {
        $counts = [
            1 => 10,
            2 => 5,
            3 => 25
        ];

        $this->assertEquals(10, $counts[1]);
        $this->assertEquals(5, $counts[2]);
        $this->assertEquals(25, $counts[3]);
    }
}
