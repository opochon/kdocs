<?php

declare(strict_types=1);

namespace KDocs\Services;

/**
 * Verdict de reconciliation « lignes + TVA = total » (D-GED-02, SV-13).
 *
 * L'extraction elle-meme (lecture des lignes depuis le texte OCR par l'IA
 * active, persistance) vit dans
 * KDocs\Services\Extraction\InvoiceLineItemExtractor + le modele
 * KDocs\Models\InvoiceLineItem (invoice_line_items) — deja routee,
 * app/Controllers/Api/InvoiceLineItemsApiController.php. Ce service
 * N'EXTRAIT RIEN : il RECALCULE le verdict a partir de lignes deja
 * obtenues, quelle qu'en soit la source.
 *
 * Constat du 2026-08-28 (lot facture-lignes-ged-t2) : une premiere version
 * de ce fichier reimplementait l'extraction en ignorant
 * InvoiceLineItemExtractor (regle 1 EcosystemK, « ne rien reproduire qui
 * existe »). Corrige : InvoiceLineItemExtractor pointait sur ClaudeService
 * (Anthropic) sans repli — donc mort des qu'aucune cle Claude n'est
 * configuree — repointe sur AIProviderService (cascade multi-fournisseurs)
 * dans le meme lot, avec la meme cause qui rendait aussi son chargement du
 * texte OCR toujours vide (colonne `ocr_content` inexistante).
 *
 * L'IA peut halluciner un montant : l'egalite lignes + TVA = total n'est
 * JAMAIS affirmee par le modele lui-meme. reconcile() est une fonction pure,
 * sans reseau — c'est ELLE qui rend le verdict.
 */
class InvoiceLineExtractionService
{
    /** Tolerance d'arrondi (centimes) sur l'egalite lignes+TVA=total. */
    private const TOLERANCE = 0.05;

    /**
     * Recalcule l'egalite lignes + TVA = total a partir de lignes deja
     * extraites (format KDocs\Models\InvoiceLineItem::create() : quantity,
     * unit_price, tax_rate, line_total, description) et des totaux imprimes
     * sur la facture.
     *
     * @param array<int, array<string, mixed>> $rawLines
     * @return array{
     *   lines: list<array{description: string, quantity: float|null, unit_price: float|null, tax_rate: float|null, line_total: float|null}>,
     *   total_ht: float|null, total_tva: float|null, total_ttc: float|null,
     *   lines_sum: float, tva_computed: float, reconciled_total: float,
     *   matches_total: bool, delta: float|null
     * }
     */
    public static function reconcile(array $rawLines, mixed $rawTotalHt, mixed $rawTotalTva, mixed $rawTotalTtc): array
    {
        $normalizedLines = [];
        $linesSum = 0.0;
        $tvaComputed = 0.0;

        foreach ($rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineTotal = is_numeric($line['line_total'] ?? null) ? (float) $line['line_total'] : null;
            $taxRate = is_numeric($line['tax_rate'] ?? null) ? (float) $line['tax_rate'] : null;

            $normalizedLines[] = [
                'description' => trim((string) ($line['description'] ?? '')),
                'quantity' => is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : null,
                'unit_price' => is_numeric($line['unit_price'] ?? null) ? (float) $line['unit_price'] : null,
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
            ];

            if ($lineTotal !== null) {
                $linesSum += $lineTotal;
                if ($taxRate !== null) {
                    $tvaComputed += $lineTotal * ($taxRate / 100);
                }
            }
        }

        $totalHt = is_numeric($rawTotalHt) ? (float) $rawTotalHt : null;
        $totalTva = is_numeric($rawTotalTva) ? (float) $rawTotalTva : null;
        $totalTtc = is_numeric($rawTotalTtc) ? (float) $rawTotalTtc : null;

        // Le total de reference est celui IMPRIME sur la facture (total_ttc),
        // jamais la somme recalculee — sinon l'egalite serait tautologique.
        $reconciledTotal = $linesSum + ($totalTva ?? $tvaComputed);
        $delta = $totalTtc !== null ? round(abs($reconciledTotal - $totalTtc), 2) : null;
        $matchesTotal = $normalizedLines !== [] && $totalTtc !== null && $delta <= self::TOLERANCE;

        return [
            'lines' => $normalizedLines,
            'total_ht' => $totalHt,
            'total_tva' => $totalTva,
            'total_ttc' => $totalTtc,
            'lines_sum' => round($linesSum, 2),
            'tva_computed' => round($tvaComputed, 2),
            'reconciled_total' => round($reconciledTotal, 2),
            'matches_total' => $matchesTotal,
            'delta' => $delta,
        ];
    }
}
