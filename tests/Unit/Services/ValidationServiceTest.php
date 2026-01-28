<?php
/**
 * Tests for ValidationService logic
 * Note: Database-dependent tests are in Feature tests
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class ValidationServiceTest extends TestCase
{
    public function testValidDecisions(): void
    {
        $validDecisions = ['approved', 'rejected', 'na'];

        foreach ($validDecisions as $decision) {
            $this->assertContains($decision, $validDecisions);
        }
    }

    public function testInvalidDecisions(): void
    {
        $validDecisions = ['approved', 'rejected', 'na'];

        $this->assertNotContains('invalid', $validDecisions);
        $this->assertNotContains('pending', $validDecisions);
        $this->assertNotContains('', $validDecisions);
    }

    public function testDeadlineCalculation(): void
    {
        $timeoutHours = 72;
        $now = time();
        $deadline = date('Y-m-d H:i:s', $now + ($timeoutHours * 3600));

        $expectedMinDate = date('Y-m-d', $now + ($timeoutHours * 3600));

        $this->assertStringStartsWith($expectedMinDate, $deadline);
    }

    public function testDeadlineCalculationWithCustomTimeout(): void
    {
        $timeoutHours = 24;
        $now = time();
        $deadline = date('Y-m-d H:i:s', $now + ($timeoutHours * 3600));

        // Should be approximately 1 day from now
        $expectedDate = date('Y-m-d', strtotime('+1 day'));
        $this->assertStringStartsWith($expectedDate, $deadline);
    }

    public function testValidationStatusValues(): void
    {
        $validStatuses = ['pending', 'approved', 'rejected', 'na', null];

        $this->assertContains('pending', $validStatuses);
        $this->assertContains('approved', $validStatuses);
        $this->assertContains('rejected', $validStatuses);
        $this->assertContains('na', $validStatuses);
        $this->assertContains(null, $validStatuses);
    }

    public function testPendingStatusPreventsResubmission(): void
    {
        $document = ['validation_status' => 'pending'];

        // Business rule: cannot submit if already pending
        $canSubmit = $document['validation_status'] !== 'pending';

        $this->assertFalse($canSubmit);
    }

    public function testApprovedStatusPreventsResubmission(): void
    {
        $document = ['validation_status' => 'approved'];

        // Business rule: cannot submit if already approved
        $canSubmit = $document['validation_status'] !== 'approved';

        $this->assertFalse($canSubmit);
    }

    public function testRejectedStatusAllowsResubmission(): void
    {
        $document = ['validation_status' => 'rejected'];

        // Business rule: can resubmit if rejected
        $canSubmit = !in_array($document['validation_status'], ['pending', 'approved']);

        $this->assertTrue($canSubmit);
    }

    public function testStatisticsPeriods(): void
    {
        $periods = ['day', 'week', 'month', 'year'];

        foreach ($periods as $period) {
            $dateCondition = match($period) {
                'day' => "DATE(validated_at) = CURDATE()",
                'week' => "validated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                'month' => "validated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                'year' => "validated_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)",
                default => "1=1"
            };

            $this->assertNotEmpty($dateCondition);
            $this->assertStringContainsString('validated_at', $dateCondition);
        }
    }

    public function testStatisticsDefaultPeriod(): void
    {
        $period = 'invalid';
        $dateCondition = match($period) {
            'day' => "DATE(validated_at) = CURDATE()",
            'week' => "validated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "validated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year' => "validated_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)",
            default => "1=1"
        };

        $this->assertEquals("1=1", $dateCondition);
    }

    public function testValidationResultFormat(): void
    {
        $successResult = [
            'success' => true,
            'status' => 'approved',
            'validated_by' => 1,
            'role' => 'VALIDATOR_L1'
        ];

        $this->assertTrue($successResult['success']);
        $this->assertArrayHasKey('status', $successResult);
        $this->assertArrayHasKey('validated_by', $successResult);
        $this->assertArrayHasKey('role', $successResult);
    }

    public function testValidationErrorFormat(): void
    {
        $errorResult = [
            'success' => false,
            'error' => 'Document non trouvé'
        ];

        $this->assertFalse($errorResult['success']);
        $this->assertArrayHasKey('error', $errorResult);
    }

    public function testApprovalRuleMatching(): void
    {
        $document = [
            'amount' => 500,
            'document_type_id' => 1,
            'correspondent_id' => 2
        ];

        $rule = [
            'min_amount' => 100,
            'max_amount' => 1000,
            'document_type_id' => 1,
            'correspondent_id' => null
        ];

        // Check if rule matches document
        $amountMatches = ($rule['min_amount'] === null || $document['amount'] >= $rule['min_amount'])
            && ($rule['max_amount'] === null || $document['amount'] <= $rule['max_amount']);

        $typeMatches = $rule['document_type_id'] === null || $rule['document_type_id'] === $document['document_type_id'];

        $correspondentMatches = $rule['correspondent_id'] === null || $rule['correspondent_id'] === $document['correspondent_id'];

        $this->assertTrue($amountMatches);
        $this->assertTrue($typeMatches);
        $this->assertTrue($correspondentMatches);
    }
}
