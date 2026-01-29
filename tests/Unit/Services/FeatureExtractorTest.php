<?php
/**
 * Tests for FeatureExtractor
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\Learning\FeatureExtractor;

class FeatureExtractorTest extends TestCase
{
    private FeatureExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FeatureExtractor();
    }

    /**
     * Test keyword extraction
     */
    public function testExtractKeywords(): void
    {
        $text = "Facture pour services de consulting informatique. Le montant total est de 5000 CHF.";

        $keywords = $this->extractor->extractKeywords($text);

        $this->assertIsArray($keywords);
        $this->assertContains('facture', $keywords);
        $this->assertContains('services', $keywords);
        $this->assertContains('consulting', $keywords);
        $this->assertContains('informatique', $keywords);
        $this->assertContains('montant', $keywords);

        // Stopwords should be filtered out
        $this->assertNotContains('le', $keywords);
        $this->assertNotContains('de', $keywords);
        $this->assertNotContains('est', $keywords);
    }

    /**
     * Test keyword extraction limits results
     */
    public function testExtractKeywordsLimit(): void
    {
        $text = str_repeat("mot unique différent chaque fois ", 100);

        $keywords = $this->extractor->extractKeywords($text, 10);

        $this->assertLessThanOrEqual(10, count($keywords));
    }

    /**
     * Test empty text returns empty array
     */
    public function testExtractKeywordsEmptyText(): void
    {
        $keywords = $this->extractor->extractKeywords('');
        $this->assertEmpty($keywords);
    }

    /**
     * Test feature extraction from document data
     */
    public function testExtractFromData(): void
    {
        $document = [
            'id' => 1,
            'correspondent_id' => 5,
            'document_type_id' => 3,
            'amount' => 1500.00,
            'ocr_content' => 'Test content with keywords',
            'mime_type' => 'application/pdf',
            'title' => 'Test Document'
        ];

        $features = $this->extractor->extractFromData($document);

        $this->assertArrayHasKey('correspondent_id', $features);
        $this->assertArrayHasKey('document_type_id', $features);
        $this->assertArrayHasKey('amount_range', $features);
        $this->assertArrayHasKey('keywords', $features);
        $this->assertArrayHasKey('content_hash', $features);
        $this->assertArrayHasKey('file_type', $features);

        $this->assertEquals(5, $features['correspondent_id']);
        $this->assertEquals(3, $features['document_type_id']);
        $this->assertEquals('1k-5k', $features['amount_range']);
        $this->assertEquals('pdf', $features['file_type']);
    }

    /**
     * Test amount range calculation
     */
    public function testAmountRanges(): void
    {
        $ranges = [
            50.00 => '0-100',
            250.00 => '100-500',
            750.00 => '500-1k',
            3000.00 => '1k-5k',
            7500.00 => '5k-10k',
            15000.00 => '10k+'
        ];

        foreach ($ranges as $amount => $expectedRange) {
            $document = ['amount' => $amount];
            $features = $this->extractor->extractFromData($document);
            $this->assertEquals($expectedRange, $features['amount_range'], "Amount $amount should be in range $expectedRange");
        }
    }

    /**
     * Test similarity calculation
     */
    public function testCalculateSimilarity(): void
    {
        $features1 = [
            'correspondent_id' => 5,
            'document_type_id' => 3,
            'amount_range' => '1k-5k',
            'keywords' => ['facture', 'services', 'consulting'],
            'tag_ids' => [1, 2],
            'file_type' => 'pdf'
        ];

        $features2 = [
            'correspondent_id' => 5,
            'document_type_id' => 3,
            'amount_range' => '1k-5k',
            'keywords' => ['facture', 'services', 'maintenance'],
            'tag_ids' => [1, 3],
            'file_type' => 'pdf'
        ];

        $similarity = $this->extractor->calculateSimilarity($features1, $features2);

        $this->assertGreaterThan(0.5, $similarity);
        $this->assertLessThanOrEqual(1.0, $similarity);
    }

    /**
     * Test identical documents have perfect similarity
     */
    public function testIdenticalDocumentsSimilarity(): void
    {
        $features = [
            'correspondent_id' => 5,
            'document_type_id' => 3,
            'amount_range' => '1k-5k',
            'keywords' => ['facture', 'services'],
            'tag_ids' => [1, 2],
            'file_type' => 'pdf'
        ];

        $similarity = $this->extractor->calculateSimilarity($features, $features);

        $this->assertEquals(1.0, $similarity);
    }

    /**
     * Test completely different documents have low similarity
     */
    public function testDifferentDocumentsSimilarity(): void
    {
        $features1 = [
            'correspondent_id' => 1,
            'document_type_id' => 1,
            'amount_range' => '0-100',
            'keywords' => ['contrat', 'location'],
            'tag_ids' => [10, 11],
            'file_type' => 'pdf'
        ];

        $features2 = [
            'correspondent_id' => 99,
            'document_type_id' => 99,
            'amount_range' => '10k+',
            'keywords' => ['facture', 'achat'],
            'tag_ids' => [20, 21],
            'file_type' => 'excel'
        ];

        $similarity = $this->extractor->calculateSimilarity($features1, $features2);

        $this->assertLessThan(0.3, $similarity);
    }

    /**
     * Test file type detection
     */
    public function testFileTypeDetection(): void
    {
        $testCases = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'image',
            'image/png' => 'image',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
            'document.pdf' => 'pdf',
            'photo.jpg' => 'image',
            'report.docx' => 'word',
            'data.xlsx' => 'excel'
        ];

        foreach ($testCases as $input => $expected) {
            $document = ['mime_type' => $input, 'filename' => $input];
            $features = $this->extractor->extractFromData($document);
            $this->assertEquals($expected, $features['file_type'], "Input '$input' should detect as '$expected'");
        }
    }
}
