<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Compliance;

use KDocs\Services\Compliance\RetentionPolicyService;
use PHPUnit\Framework\TestCase;

/**
 * GAP-021 — politiques de rétention (10 ans compta CO 958f).
 */
class RetentionPolicyTest extends TestCase
{
    private RetentionPolicyService $service;

    protected function setUp(): void
    {
        $this->service = new RetentionPolicyService();
    }

    public function testDueDateFactureAuMoinsDixAns(): void
    {
        $due = $this->service->dueDate([
            'document_date' => '2026-01-15',
            'type_label' => 'Facture',
        ]);

        $this->assertSame('2036-01-15', $due->format('Y-m-d'));
    }

    public function testDueDateSansTypeUtiliseDefautDixAns(): void
    {
        $due = $this->service->dueDate([
            'document_date' => '2020-06-30',
            'type_label' => null,
        ]);

        $this->assertSame('2030-06-30', $due->format('Y-m-d'));
    }

    public function testDueDateRetombeSurCreatedAt(): void
    {
        $due = $this->service->dueDate([
            'created_at' => '2025-03-01 10:00:00',
            'type_label' => 'Contrat',
        ]);

        $this->assertSame('2035-03-01', $due->format('Y-m-d'));
    }

    public function testDueDateSansAucuneDatePartDaujourdhui(): void
    {
        $due = $this->service->dueDate([]);
        $minimum = (new \DateTimeImmutable())->add(new \DateInterval('P10Y'))->modify('-1 day');

        $this->assertGreaterThanOrEqual($minimum, $due);
    }

    public function testRetentionYearsJamaisInferieurADixAns(): void
    {
        foreach (['Facture', 'Note de crédit', 'Reçu', 'Contrat', 'Courrier', 'inconnu', null] as $type) {
            $this->assertGreaterThanOrEqual(
                10,
                $this->service->retentionYears($type),
                "type {$type}"
            );
        }
    }

    public function testRetentionYearsInsensibleCasse(): void
    {
        $this->assertSame(
            $this->service->retentionYears('FACTURE'),
            $this->service->retentionYears('facture')
        );
    }
}
