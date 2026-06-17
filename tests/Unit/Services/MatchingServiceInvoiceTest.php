<?php

namespace KDocs\Tests\Unit\Services;

use KDocs\Tests\TestCase;
use KDocs\Services\MatchingService;

class MatchingServiceInvoiceTest extends TestCase
{
    public function testMatchInvoiceToBLFindsCodeMatch(): void
    {
        $invoiceLines = [
            ['code' => 'ART-001', 'designation' => 'Vis M8', 'quantity' => 10, 'unit_price' => 1.5],
        ];
        $blLines = [
            ['code' => 'ART-001', 'designation' => 'Vis M8 inox', 'quantity' => 10, 'unit_price' => 1.5],
            ['code' => 'ART-999', 'designation' => 'Autre', 'quantity' => 1, 'unit_price' => 9.0],
        ];

        $matches = MatchingService::matchInvoiceToBL($invoiceLines, $blLines);

        $this->assertCount(1, $matches);
        $this->assertNotNull($matches[0]['suggestion']);
        $this->assertSame('ART-001', $matches[0]['suggestion']['bl_line']['code']);
        $this->assertGreaterThanOrEqual(40.0, $matches[0]['confidence']);
    }

    public function testMatchInvoiceToBLReturnsNullWhenNoMatch(): void
    {
        $invoiceLines = [
            ['code' => 'X-1', 'designation' => 'Produit inconnu', 'quantity' => 1, 'unit_price' => 1.0],
        ];
        $blLines = [
            ['code' => 'Y-9', 'designation' => 'Totalement différent', 'quantity' => 99, 'unit_price' => 50.0],
        ];

        $matches = MatchingService::matchInvoiceToBL($invoiceLines, $blLines);

        $this->assertNull($matches[0]['suggestion']);
    }
}
