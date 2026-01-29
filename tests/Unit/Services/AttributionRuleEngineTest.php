<?php
/**
 * Tests for AttributionRuleEngine
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\Attribution\RuleConditionEvaluator;

class AttributionRuleEngineTest extends TestCase
{
    private RuleConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new RuleConditionEvaluator();
    }

    /**
     * Test equals operator
     */
    public function testEqualsOperator(): void
    {
        $document = ['correspondent_id' => 5];
        $condition = [
            'field_type' => 'correspondent',
            'operator' => 'equals',
            'value' => '5'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test equals operator with mismatch
     */
    public function testEqualsOperatorMismatch(): void
    {
        $document = ['correspondent_id' => 5];
        $condition = [
            'field_type' => 'correspondent',
            'operator' => 'equals',
            'value' => '10'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertFalse($result['matched']);
    }

    /**
     * Test contains operator for content
     */
    public function testContainsOperator(): void
    {
        $document = [
            'id' => 1,
            'ocr_content' => 'Facture pour services de consulting'
        ];
        $condition = [
            'field_type' => 'content',
            'operator' => 'contains',
            'value' => 'consulting'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test contains operator case insensitive
     */
    public function testContainsOperatorCaseInsensitive(): void
    {
        $document = [
            'id' => 1,
            'ocr_content' => 'FACTURE POUR SERVICES DE CONSULTING'
        ];
        $condition = [
            'field_type' => 'content',
            'operator' => 'contains',
            'value' => 'consulting'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test greater_than operator for amount
     */
    public function testGreaterThanOperator(): void
    {
        $document = ['amount' => 1500.00];
        $condition = [
            'field_type' => 'amount',
            'operator' => 'greater_than',
            'value' => '1000'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test less_than operator
     */
    public function testLessThanOperator(): void
    {
        $document = ['amount' => 500.00];
        $condition = [
            'field_type' => 'amount',
            'operator' => 'less_than',
            'value' => '1000'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test between operator
     */
    public function testBetweenOperator(): void
    {
        $document = ['amount' => 750.00];
        $condition = [
            'field_type' => 'amount',
            'operator' => 'between',
            'value' => json_encode([500, 1000])
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test is_empty operator
     */
    public function testIsEmptyOperator(): void
    {
        $document = ['correspondent_id' => null];
        $condition = [
            'field_type' => 'correspondent',
            'operator' => 'is_empty',
            'value' => ''
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test is_not_empty operator
     */
    public function testIsNotEmptyOperator(): void
    {
        $document = ['correspondent_id' => 5];
        $condition = [
            'field_type' => 'correspondent',
            'operator' => 'is_not_empty',
            'value' => ''
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test starts_with operator
     */
    public function testStartsWithOperator(): void
    {
        $document = [
            'id' => 1,
            'ocr_content' => 'Facture de services professionnels'
        ];
        $condition = [
            'field_type' => 'content',
            'operator' => 'starts_with',
            'value' => 'Facture'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test regex operator
     */
    public function testRegexOperator(): void
    {
        $document = [
            'id' => 1,
            'ocr_content' => 'Facture N° FA-2024-001234'
        ];
        $condition = [
            'field_type' => 'content',
            'operator' => 'regex',
            'value' => '/FA-\d{4}-\d+/'
        ];

        $result = $this->evaluator->evaluate($condition, $document);
        $this->assertTrue($result['matched']);
    }

    /**
     * Test getOperatorsForFieldType returns expected operators
     */
    public function testGetOperatorsForFieldType(): void
    {
        // Amount field should have numeric operators
        $amountOps = RuleConditionEvaluator::getOperatorsForFieldType('amount');
        $this->assertArrayHasKey('greater_than', $amountOps);
        $this->assertArrayHasKey('less_than', $amountOps);
        $this->assertArrayHasKey('between', $amountOps);

        // Content field should have text operators
        $contentOps = RuleConditionEvaluator::getOperatorsForFieldType('content');
        $this->assertArrayHasKey('contains', $contentOps);
        $this->assertArrayHasKey('starts_with', $contentOps);
        $this->assertArrayHasKey('regex', $contentOps);

        // Tag field should have specialized operators
        $tagOps = RuleConditionEvaluator::getOperatorsForFieldType('tag');
        $this->assertArrayHasKey('contains', $tagOps);
        $this->assertArrayHasKey('is_empty', $tagOps);
    }
}
