<?php
/**
 * Tests pour InvoiceLineExtractionService::reconcile() — l'egalite lignes +
 * TVA = total (SV-13, D-GED-02). Fonction pure, sans IA : le verdict est
 * TOUJOURS recalcule en PHP, jamais affirme par le modele.
 */

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use KDocs\Services\InvoiceLineExtractionService;

class InvoiceLineExtractionServiceTest extends TestCase
{
    public function testLignesEtTvaConcordantAvecLeTotal(): void
    {
        $r = InvoiceLineExtractionService::reconcile(
            [
                ['description' => 'Prestation A', 'line_total' => 1450.0, 'tax_rate' => 8.1],
                ['description' => 'Prestation B', 'line_total' => 380.0, 'tax_rate' => 8.1],
            ],
            1830.0,
            148.23,
            1978.23
        );

        $this->assertTrue($r['matches_total']);
        $this->assertSame(0.0, $r['delta']);
        $this->assertSame(1830.0, $r['lines_sum']);
    }

    public function testEcartReelEntreLesLignesEtLeTotalNeMentPas(): void
    {
        // Le total imprime (2000) ne correspond pas a lignes+TVA (1978.23) :
        // le verdict doit rougir, pas se caler discretement sur le total.
        $r = InvoiceLineExtractionService::reconcile(
            [
                ['description' => 'Prestation A', 'line_total' => 1450.0, 'tax_rate' => 8.1],
                ['description' => 'Prestation B', 'line_total' => 380.0, 'tax_rate' => 8.1],
            ],
            1830.0,
            148.23,
            2000.0
        );

        $this->assertFalse($r['matches_total']);
        $this->assertEqualsWithDelta(21.77, $r['delta'], 0.01);
    }

    public function testAucuneLigneNeVautJamaisVertMemeSiUnTotalExiste(): void
    {
        // Cas mesure le 2026-08-28 : une facture d'assurance (TVA exoneree
        // en Suisse) ne rend aucune ligne — matches_total ne doit JAMAIS
        // devenir vrai par accident (ex. lines_sum=0 + tva=0 == total=0).
        $r = InvoiceLineExtractionService::reconcile([], null, null, null);

        $this->assertFalse($r['matches_total']);
        $this->assertSame([], $r['lines']);
        $this->assertNull($r['delta']);
    }

    public function testTotalTtcAbsentNeProduitJamaisUnFauxVert(): void
    {
        $r = InvoiceLineExtractionService::reconcile(
            [['description' => 'Prestation A', 'line_total' => 100.0, 'tax_rate' => 8.1]],
            100.0,
            8.1,
            null
        );

        $this->assertFalse($r['matches_total']);
        $this->assertNull($r['delta']);
    }

    public function testTvaComputeeDepuisLesLignesQuandTotalTvaAbsent(): void
    {
        // total_tva n'est pas toujours imprime separement : le repli somme
        // la TVA ligne par ligne plutot que d'abandonner la reconciliation.
        $r = InvoiceLineExtractionService::reconcile(
            [['description' => 'Prestation A', 'line_total' => 100.0, 'tax_rate' => 8.1]],
            100.0,
            null,
            108.1
        );

        $this->assertSame(8.1, $r['tva_computed']);
        $this->assertTrue($r['matches_total']);
    }

    public function testToleranceDArrondiSurQuelquesCentimes(): void
    {
        $r = InvoiceLineExtractionService::reconcile(
            [['description' => 'Prestation A', 'line_total' => 33.33, 'tax_rate' => 7.7]],
            33.33,
            2.57,
            35.91 // 33.33 + 2.57 = 35.90, ecart d'arrondi de 0.01
        );

        $this->assertTrue($r['matches_total']);
    }
}
