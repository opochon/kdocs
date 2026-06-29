<?php

declare(strict_types=1);

namespace KDocs\Tests\Unit\Services;

use KDocs\Services\DocumentMetadataHeuristics;
use KDocs\Services\Storage\InternalFolderRegistry;
use KDocs\Tests\TestCase;

class DocumentMetadataHeuristicsTest extends TestCase
{
    public function testExtractDateFromYearFolder(): void
    {
        $date = DocumentMetadataHeuristics::extractDate('2024/toclassify/facture_janvier');
        $this->assertSame('2024-01-01', $date);
    }

    public function testExtractDateFromFilename(): void
    {
        $yearOnly = DocumentMetadataHeuristics::extractDate('Arret du 5 juin 2024');
        $this->assertSame('2024-01-01', $yearOnly);
        $iso = DocumentMetadataHeuristics::extractDate('scan_2024-06-29_final');
        $this->assertSame('2024-06-29', $iso);
    }

    public function testInternalFoldersHidden(): void
    {
        $this->assertTrue(InternalFolderRegistry::isHiddenFolderName('toclassify'));
        $this->assertTrue(InternalFolderRegistry::isHiddenPath('2024/toclassify'));
        $this->assertFalse(InternalFolderRegistry::isHiddenPath('2024/clients'));
    }
}
