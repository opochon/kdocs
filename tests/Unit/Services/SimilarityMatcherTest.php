<?php
/**
 * Tests for SimilarityMatcher
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\Learning\FeatureExtractor;

class SimilarityMatcherTest extends TestCase
{
    private FeatureExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FeatureExtractor();
    }

    /**
     * Test similarity scoring with same correspondent
     */
    public function testSimilarityWithSameCorrespondent(): void
    {
        $features1 = [
            'correspondent_id' => 5,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => [],
            'tag_ids' => [],
            'file_type' => 'pdf'
        ];

        $features2 = [
            'correspondent_id' => 5,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => [],
            'tag_ids' => [],
            'file_type' => 'pdf'
        ];

        $similarity = $this->extractor->calculateSimilarity($features1, $features2);

        // Should get at least the correspondent weight (0.30) + file_type (0.05)
        $this->assertGreaterThanOrEqual(0.35, $similarity);
    }

    /**
     * Test similarity with same document type
     */
    public function testSimilarityWithSameDocumentType(): void
    {
        $features1 = [
            'correspondent_id' => null,
            'document_type_id' => 3,
            'amount_range' => null,
            'keywords' => [],
            'tag_ids' => [],
            'file_type' => 'pdf'
        ];

        $features2 = [
            'correspondent_id' => null,
            'document_type_id' => 3,
            'amount_range' => null,
            'keywords' => [],
            'tag_ids' => [],
            'file_type' => 'pdf'
        ];

        $similarity = $this->extractor->calculateSimilarity($features1, $features2);

        // Should get document_type weight (0.25) + file_type (0.05)
        $this->assertGreaterThanOrEqual(0.30, $similarity);
    }

    /**
     * Test keyword overlap calculation
     */
    public function testKeywordOverlapSimilarity(): void
    {
        $features1 = [
            'correspondent_id' => null,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => ['facture', 'services', 'consulting', 'informatique'],
            'tag_ids' => [],
            'file_type' => 'other'
        ];

        $features2 = [
            'correspondent_id' => null,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => ['facture', 'services', 'maintenance', 'annuelle'],
            'tag_ids' => [],
            'file_type' => 'other'
        ];

        $similarity = $this->extractor->calculateSimilarity($features1, $features2);

        // 2 common keywords out of 6 unique = 0.33 overlap
        // 0.33 * 0.15 (weight) = ~0.05
        $this->assertGreaterThan(0.0, $similarity);
        $this->assertLessThan(0.5, $similarity);
    }

    /**
     * Test tag overlap calculation
     */
    public function testTagOverlapSimilarity(): void
    {
        $features1 = [
            'correspondent_id' => null,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => [],
            'tag_ids' => [1, 2, 3],
            'file_type' => 'other'
        ];

        $features2 = [
            'correspondent_id' => null,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => [],
            'tag_ids' => [2, 3, 4],
            'file_type' => 'other'
        ];

        $similarity = $this->extractor->calculateSimilarity($features1, $features2);

        // 2 common tags out of 4 unique = 0.5 overlap
        // 0.5 * 0.10 (weight) = 0.05
        $this->assertGreaterThan(0.0, $similarity);
    }

    /**
     * Test comprehensive similarity
     */
    public function testComprehensiveSimilarity(): void
    {
        // Two very similar invoices
        $features1 = [
            'correspondent_id' => 5,
            'document_type_id' => 3, // Facture
            'amount_range' => '1k-5k',
            'keywords' => ['facture', 'services', 'consulting', 'mensuel'],
            'tag_ids' => [1, 2],
            'file_type' => 'pdf'
        ];

        $features2 = [
            'correspondent_id' => 5,
            'document_type_id' => 3, // Facture
            'amount_range' => '1k-5k',
            'keywords' => ['facture', 'services', 'consulting', 'trimestriel'],
            'tag_ids' => [1, 2],
            'file_type' => 'pdf'
        ];

        $similarity = $this->extractor->calculateSimilarity($features1, $features2);

        // Same correspondent (0.30) + same type (0.25) + same amount range (0.15)
        // + similar keywords (~0.10) + same tags (0.10) + same file type (0.05)
        // Total should be close to 0.95
        $this->assertGreaterThan(0.85, $similarity);
    }

    /**
     * Test weighted voting simulation
     */
    public function testWeightedVoting(): void
    {
        // Simulate votes with weights
        $votes = [
            ['value' => 'ADMIN', 'weight' => 0.9],
            ['value' => 'ADMIN', 'weight' => 0.85],
            ['value' => 'ADMIN', 'weight' => 0.7],
            ['value' => 'PROD', 'weight' => 0.6],
            ['value' => 'PROD', 'weight' => 0.5],
        ];

        $groupedVotes = [];
        foreach ($votes as $vote) {
            $value = $vote['value'];
            if (!isset($groupedVotes[$value])) {
                $groupedVotes[$value] = ['total_weight' => 0, 'count' => 0];
            }
            $groupedVotes[$value]['total_weight'] += $vote['weight'];
            $groupedVotes[$value]['count']++;
        }

        // Calculate confidence
        $totalWeight = array_sum(array_column($groupedVotes, 'total_weight'));
        $winner = null;
        $maxWeight = 0;

        foreach ($groupedVotes as $value => $data) {
            if ($data['total_weight'] > $maxWeight) {
                $maxWeight = $data['total_weight'];
                $winner = $value;
            }
        }

        $confidence = $maxWeight / $totalWeight;

        $this->assertEquals('ADMIN', $winner);
        $this->assertGreaterThan(0.5, $confidence);
    }

    /**
     * Test empty features handling
     */
    public function testEmptyFeaturesHandling(): void
    {
        $emptyFeatures = [
            'correspondent_id' => null,
            'document_type_id' => null,
            'amount_range' => null,
            'keywords' => [],
            'tag_ids' => [],
            'file_type' => 'other'
        ];

        $similarity = $this->extractor->calculateSimilarity($emptyFeatures, $emptyFeatures);

        // Even empty features should have some similarity (file_type)
        $this->assertGreaterThanOrEqual(0.0, $similarity);
    }
}
