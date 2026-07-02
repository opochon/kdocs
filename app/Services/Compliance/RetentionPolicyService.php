<?php
/**
 * Politiques de rétention légale (P2 conformité CH).
 *
 * Référence : CO 958f (10 ans pièces comptables), GeBüV. La durée est
 * déterminée par le libellé du type documentaire ; défaut prudent = 10 ans.
 */

namespace KDocs\Services\Compliance;

class RetentionPolicyService
{
    /** Durée par défaut (années) — pièces comptables CO 958f. */
    public const DEFAULT_RETENTION_YEARS = 10;

    /** Durées spécifiques par type documentaire (libellé normalisé → années). */
    private const RETENTION_YEARS_BY_TYPE = [
        'facture'         => 10,
        'note de crédit'  => 10,
        'reçu'            => 10,
        'contrat'         => 10,
        'courrier'        => 10,
        'bulletin de salaire' => 10,
        'dossier rh'      => 10,
    ];

    /**
     * Durée de rétention (années) pour un type documentaire donné.
     */
    public function retentionYears(?string $typeLabel): int
    {
        if ($typeLabel === null || $typeLabel === '') {
            return self::DEFAULT_RETENTION_YEARS;
        }

        $normalized = mb_strtolower(trim($typeLabel));

        return self::RETENTION_YEARS_BY_TYPE[$normalized] ?? self::DEFAULT_RETENTION_YEARS;
    }

    /**
     * Échéance de rétention d'un document : date de référence + durée du type.
     *
     * La date de référence est `document_date` si présente, sinon `created_at`,
     * sinon aujourd'hui (document sans date = départ au scellement).
     *
     * @param array $document Ligne documents (document_date?, created_at?, type_label?)
     */
    public function dueDate(array $document): \DateTimeImmutable
    {
        $reference = $document['document_date']
            ?? $document['created_at']
            ?? 'now';

        try {
            $start = new \DateTimeImmutable((string) $reference);
        } catch (\Exception $e) {
            $start = new \DateTimeImmutable();
        }

        $years = $this->retentionYears($document['type_label'] ?? null);

        return $start->add(new \DateInterval("P{$years}Y"));
    }
}
