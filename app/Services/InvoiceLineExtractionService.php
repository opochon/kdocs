<?php

declare(strict_types=1);

namespace KDocs\Services;

/**
 * Extraction des lignes produits d'une facture depuis son texte OCR.
 *
 * D-GED-02 : « lire tous les produits avec leur montant, et que le total des
 * produits + TVA corresponde au total de la facture ». Le QR-facture suisse
 * (cmd4_ingest) ne porte QUE la partie paiement (IBAN, montant, reference) —
 * jamais le detail des lignes, par construction du standard. Ce service
 * comble ce trou cote GED (arbitrage Olivier, 2026-08-28 : « utile pour tout
 * classement », implementation GED plutot que d'attendre une extension de
 * cmd4_ingest/CMD v4, hors depot).
 *
 * L'IA peut halluciner un montant : l'egalite lignes + TVA = total n'est
 * JAMAIS affirmee par le modele lui-meme, elle est RECALCULEE ici en PHP a
 * partir des montants extraits. Le modele ne fait que lire, jamais calculer
 * le verdict.
 */
class InvoiceLineExtractionService
{
    private AIProviderService $ai;

    /** Tolerance d'arrondi (centimes) sur l'egalite lignes+TVA=total. */
    private const TOLERANCE = 0.05;

    public function __construct(?AIProviderService $ai = null)
    {
        $this->ai = $ai ?? new AIProviderService();
    }

    public function isAvailable(): bool
    {
        return $this->ai->isAIAvailable();
    }

    /**
     * @return array{
     *   lines: list<array{description: string, qty: float|null, unit_price: float|null, tva_rate: float|null, line_total: float|null}>,
     *   total_ht: float|null, total_tva: float|null, total_ttc: float|null,
     *   lines_sum: float, tva_computed: float, reconciled_total: float,
     *   matches_total: bool, delta: float, source: string
     * }|null Null si aucun fournisseur IA disponible ou reponse inexploitable.
     */
    public function extract(string $text): ?array
    {
        if (!$this->isAvailable() || trim($text) === '') {
            return null;
        }

        $fields = [
            'lines' => 'array of {description: string, qty: number|null, unit_price: number|null, tva_rate: number|null, line_total: number|null} — UNE entree par ligne produit/prestation visible sur la facture, jamais de ligne inventee si aucun tableau n\'est present',
            'total_ht' => 'number|null — total hors taxes affiche sur la facture, tel qu imprime',
            'total_tva' => 'number|null — montant de TVA affiche sur la facture, tel qu imprime',
            'total_ttc' => 'number|null — total toutes taxes comprises affiche sur la facture, tel qu imprime',
        ];

        $result = $this->ai->extractData($text, $fields);
        if (!is_array($result)) {
            return null;
        }

        return self::reconcile(
            is_array($result['lines'] ?? null) ? $result['lines'] : [],
            $result['total_ht'] ?? null,
            $result['total_tva'] ?? null,
            $result['total_ttc'] ?? null
        );
    }

    /**
     * Recalcule l'egalite lignes + TVA = total a partir de valeurs deja
     * extraites (par l'IA ou toute autre source). Fonction pure, sans appel
     * reseau : c'est ELLE qui rend le verdict, jamais le modele — testable
     * en isolation sur des cas construits (tests/Unit), a la difference de
     * extract() qui exige un fournisseur IA reel et un texte de facture
     * (tests/integration).
     *
     * @param array<int, array<string, mixed>> $rawLines
     * @return array{
     *   lines: list<array{description: string, qty: float|null, unit_price: float|null, tva_rate: float|null, line_total: float|null}>,
     *   total_ht: float|null, total_tva: float|null, total_ttc: float|null,
     *   lines_sum: float, tva_computed: float, reconciled_total: float,
     *   matches_total: bool, delta: float|null, source: string
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
            $tvaRate = is_numeric($line['tva_rate'] ?? null) ? (float) $line['tva_rate'] : null;

            $normalizedLines[] = [
                'description' => trim((string) ($line['description'] ?? '')),
                'qty' => is_numeric($line['qty'] ?? null) ? (float) $line['qty'] : null,
                'unit_price' => is_numeric($line['unit_price'] ?? null) ? (float) $line['unit_price'] : null,
                'tva_rate' => $tvaRate,
                'line_total' => $lineTotal,
            ];

            if ($lineTotal !== null) {
                $linesSum += $lineTotal;
                if ($tvaRate !== null) {
                    $tvaComputed += $lineTotal * ($tvaRate / 100);
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
            'source' => 'ai',
        ];
    }
}
