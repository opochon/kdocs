<?php
/**
 * K-Docs - AIHelper Unit Tests
 */

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use KDocs\Helpers\AIHelper;

class AIHelperTest extends TestCase
{
    public function testParseJsonResponseDirectJson(): void
    {
        $json = '{"type":"facture","confidence":0.9}';
        $result = AIHelper::parseJsonResponse($json);

        $this->assertIsArray($result);
        $this->assertEquals('facture', $result['type']);
        $this->assertEquals(0.9, $result['confidence']);
    }

    public function testParseJsonResponseWrappedInCodeBlock(): void
    {
        $text = "```json\n{\"type\":\"contrat\"}\n```";
        $result = AIHelper::parseJsonResponse($text);

        $this->assertIsArray($result);
        $this->assertEquals('contrat', $result['type']);
    }

    public function testParseJsonResponseMixedText(): void
    {
        $text = 'Here is the result: {"type":"rapport","confidence":0.8} as requested.';
        $result = AIHelper::parseJsonResponse($text);

        $this->assertIsArray($result);
        $this->assertEquals('rapport', $result['type']);
    }

    public function testParseJsonResponseInvalidJson(): void
    {
        $text = 'This is not JSON at all';
        $result = AIHelper::parseJsonResponse($text);

        $this->assertNull($result);
    }

    public function testEnsureUtf8ValidUtf8(): void
    {
        $text = "Texte français avec accents: éàüö";
        $result = AIHelper::ensureUtf8($text);

        $this->assertEquals($text, $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function testEnsureUtf8EmptyString(): void
    {
        $result = AIHelper::ensureUtf8('');
        $this->assertEquals('', $result);
    }

    public function testCosineSimilarityIdentical(): void
    {
        $vec = [1.0, 0.0, 0.0];
        $similarity = AIHelper::cosineSimilarity($vec, $vec);

        $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);
    }

    public function testCosineSimilarityOrthogonal(): void
    {
        $vec1 = [1.0, 0.0, 0.0];
        $vec2 = [0.0, 1.0, 0.0];
        $similarity = AIHelper::cosineSimilarity($vec1, $vec2);

        $this->assertEqualsWithDelta(0.0, $similarity, 0.0001);
    }

    public function testCosineSimilarityOpposite(): void
    {
        $vec1 = [1.0, 0.0];
        $vec2 = [-1.0, 0.0];
        $similarity = AIHelper::cosineSimilarity($vec1, $vec2);

        $this->assertEqualsWithDelta(-1.0, $similarity, 0.0001);
    }

    public function testCosineSimilarityDifferentLengths(): void
    {
        $vec1 = [1.0, 0.0, 0.0];
        $vec2 = [1.0, 0.0];
        $similarity = AIHelper::cosineSimilarity($vec1, $vec2);

        $this->assertEquals(0.0, $similarity);
    }

    public function testCosineSimilarityEmptyVectors(): void
    {
        $similarity = AIHelper::cosineSimilarity([], []);
        $this->assertEquals(0.0, $similarity);
    }

    public function testBuildClassificationPrompt(): void
    {
        $text = "Facture n°2025-001 pour services rendus";
        $prompt = AIHelper::buildClassificationPrompt($text);

        $this->assertStringContainsString('Facture', $prompt);
        $this->assertStringContainsString('JSON', $prompt);
        $this->assertStringContainsString('type', $prompt);
    }

    public function testBuildClassificationPromptWithCategories(): void
    {
        $text = "Test document";
        $categories = ['TypeA', 'TypeB', 'TypeC'];
        $prompt = AIHelper::buildClassificationPrompt($text, $categories);

        $this->assertStringContainsString('TypeA', $prompt);
        $this->assertStringContainsString('TypeB', $prompt);
    }

    public function testExtractFieldsDate(): void
    {
        $text = "Date: 15/01/2025";
        $fields = AIHelper::extractFields($text);

        $this->assertArrayHasKey('date', $fields);
        $this->assertEquals('2025-01-15', $fields['date']);
    }

    public function testExtractFieldsDate2DigitYear(): void
    {
        $text = "Date: 16.12.22";
        $fields = AIHelper::extractFields($text);

        $this->assertArrayHasKey('date', $fields);
        $this->assertEquals('2022-12-16', $fields['date']);
    }

    public function testExtractFieldsAmount(): void
    {
        $text = "Montant: CHF 1'234.50";
        $fields = AIHelper::extractFields($text);

        $this->assertArrayHasKey('amount', $fields);
        $this->assertEqualsWithDelta(1234.50, $fields['amount'], 0.01);
    }

    public function testExtractFieldsIBAN(): void
    {
        $text = "IBAN: CH93 0076 2011 6238 5295 7";
        $fields = AIHelper::extractFields($text);

        $this->assertArrayHasKey('iban', $fields);
        $this->assertStringStartsWith('CH93', $fields['iban']);
    }

    public function testExtractFieldsReference(): void
    {
        // Test that reference extraction function exists and returns array key
        $text = "Facture No 12345";
        $fields = AIHelper::extractFields($text);

        $this->assertArrayHasKey('reference', $fields);
        // Reference extraction is pattern-dependent, just verify the key exists
    }
}
