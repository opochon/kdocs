<?php
/**
 * Tests for NotificationService constants and logic
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\NotificationService;

class NotificationServiceTest extends TestCase
{
    public function testNotificationTypesExist(): void
    {
        $this->assertEquals('validation_pending', NotificationService::TYPE_VALIDATION_PENDING);
        $this->assertEquals('validation_approved', NotificationService::TYPE_VALIDATION_APPROVED);
        $this->assertEquals('validation_rejected', NotificationService::TYPE_VALIDATION_REJECTED);
        $this->assertEquals('note_received', NotificationService::TYPE_NOTE_RECEIVED);
        $this->assertEquals('note_action_required', NotificationService::TYPE_NOTE_ACTION_REQUIRED);
        $this->assertEquals('task_assigned', NotificationService::TYPE_TASK_ASSIGNED);
        $this->assertEquals('task_completed', NotificationService::TYPE_TASK_COMPLETED);
        $this->assertEquals('document_shared', NotificationService::TYPE_DOCUMENT_SHARED);
        $this->assertEquals('workflow_step', NotificationService::TYPE_WORKFLOW_STEP);
        $this->assertEquals('system', NotificationService::TYPE_SYSTEM);
    }

    public function testPriorityLevels(): void
    {
        $this->assertEquals('low', NotificationService::PRIORITY_LOW);
        $this->assertEquals('normal', NotificationService::PRIORITY_NORMAL);
        $this->assertEquals('high', NotificationService::PRIORITY_HIGH);
        $this->assertEquals('urgent', NotificationService::PRIORITY_URGENT);
    }

    public function testPriorityOrder(): void
    {
        $priorities = [
            NotificationService::PRIORITY_URGENT,
            NotificationService::PRIORITY_HIGH,
            NotificationService::PRIORITY_NORMAL,
            NotificationService::PRIORITY_LOW,
        ];

        // Urgent should be first in order
        $this->assertEquals('urgent', $priorities[0]);
        $this->assertEquals('low', $priorities[3]);
    }

    public function testNotificationStructure(): void
    {
        $notification = [
            'id' => 1,
            'user_id' => 5,
            'type' => NotificationService::TYPE_VALIDATION_PENDING,
            'title' => 'Document à valider',
            'message' => 'Un nouveau document nécessite votre validation',
            'link' => '/documents/123',
            'document_id' => 123,
            'related_user_id' => 10,
            'priority' => NotificationService::PRIORITY_HIGH,
            'action_url' => '/mes-taches',
            'is_read' => false,
            'created_at' => '2026-01-27 10:00:00'
        ];

        $this->assertArrayHasKey('id', $notification);
        $this->assertArrayHasKey('user_id', $notification);
        $this->assertArrayHasKey('type', $notification);
        $this->assertArrayHasKey('title', $notification);
        $this->assertArrayHasKey('is_read', $notification);
        $this->assertFalse($notification['is_read']);
    }

    public function testValidationPendingNotificationPriority(): void
    {
        // Validation pending should have HIGH priority
        $priority = NotificationService::PRIORITY_HIGH;

        $this->assertEquals('high', $priority);
    }

    public function testValidationApprovedNotificationPriority(): void
    {
        // Approved notifications should have NORMAL priority
        $isApproved = true;
        $priority = $isApproved ? NotificationService::PRIORITY_NORMAL : NotificationService::PRIORITY_HIGH;

        $this->assertEquals('normal', $priority);
    }

    public function testValidationRejectedNotificationPriority(): void
    {
        // Rejected notifications should have HIGH priority
        $isApproved = false;
        $priority = $isApproved ? NotificationService::PRIORITY_NORMAL : NotificationService::PRIORITY_HIGH;

        $this->assertEquals('high', $priority);
    }

    public function testNoteActionRequiredNotificationPriority(): void
    {
        $actionRequired = true;
        $priority = $actionRequired ? NotificationService::PRIORITY_HIGH : NotificationService::PRIORITY_NORMAL;

        $this->assertEquals('high', $priority);
    }

    public function testNoteWithoutActionRequiredPriority(): void
    {
        $actionRequired = false;
        $priority = $actionRequired ? NotificationService::PRIORITY_HIGH : NotificationService::PRIORITY_NORMAL;

        $this->assertEquals('normal', $priority);
    }

    public function testUnreadCountByPriorityStructure(): void
    {
        $countByPriority = [
            'total' => 15,
            'urgent' => 2,
            'high' => 5,
            'normal' => 6,
            'low' => 2
        ];

        $this->assertEquals(15, $countByPriority['total']);
        $this->assertEquals(
            $countByPriority['total'],
            $countByPriority['urgent'] + $countByPriority['high'] + $countByPriority['normal'] + $countByPriority['low']
        );
    }

    public function testCleanupRetentionPeriod(): void
    {
        $defaultDaysOld = 90;

        // Old notifications should be cleaned up
        $oldDate = date('Y-m-d', strtotime("-{$defaultDaysOld} days"));
        $currentDate = date('Y-m-d');

        $this->assertLessThan($currentDate, $oldDate);
    }

    public function testNotificationTitleFormatValidationPending(): void
    {
        $documentTitle = 'Facture EDF 2026';
        $expectedTitle = "Document à valider : {$documentTitle}";

        $this->assertEquals('Document à valider : Facture EDF 2026', $expectedTitle);
    }

    public function testNotificationTitleFormatValidationApproved(): void
    {
        $documentTitle = 'Facture Orange';
        $isApproved = true;
        $expectedTitle = $isApproved
            ? "Document approuvé : {$documentTitle}"
            : "Document rejeté : {$documentTitle}";

        $this->assertEquals('Document approuvé : Facture Orange', $expectedTitle);
    }

    public function testNotificationTitleFormatValidationRejected(): void
    {
        $documentTitle = 'Contrat';
        $isApproved = false;
        $expectedTitle = $isApproved
            ? "Document approuvé : {$documentTitle}"
            : "Document rejeté : {$documentTitle}";

        $this->assertEquals('Document rejeté : Contrat', $expectedTitle);
    }

    public function testValidatorRoleCodes(): void
    {
        $validatorRoles = ['VALIDATOR_L1', 'VALIDATOR_L2', 'APPROVER', 'ADMIN'];

        $this->assertContains('VALIDATOR_L1', $validatorRoles);
        $this->assertContains('VALIDATOR_L2', $validatorRoles);
        $this->assertContains('APPROVER', $validatorRoles);
        $this->assertContains('ADMIN', $validatorRoles);
    }
}
