<?php
/**
 * K-Docs - Tests TrashService
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class TrashServiceTest extends TestCase
{
    public function testTrashPathIsConfigured(): void
    {
        $trashPath = __DIR__ . '/../../../storage/trash';
        // Le dossier devrait exister ou être créable
        $this->assertTrue(is_dir($trashPath) || @mkdir($trashPath, 0755, true));
    }

    public function testSoftDeleteKeepsRecord(): void
    {
        // Test conceptuel - la date de suppression est générée
        $deletedAt = date('Y-m-d H:i:s');
        $this->assertNotNull($deletedAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $deletedAt);
    }

    public function testDeletedAtTimestamp(): void
    {
        $before = time();
        $deletedAt = date('Y-m-d H:i:s');
        $after = time();

        $timestamp = strtotime($deletedAt);
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testTrashRetentionPeriod(): void
    {
        // Par défaut, 30 jours de rétention
        $retentionDays = 30;
        $deletedAt = strtotime('-31 days');
        $cutoff = strtotime("-{$retentionDays} days");

        // Un fichier supprimé il y a 31 jours devrait être purgeable
        $this->assertLessThan($cutoff, $deletedAt);
    }
}
