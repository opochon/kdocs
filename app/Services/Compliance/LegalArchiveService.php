<?php
/**
 * Archivage légal WORM (P2 conformité CH — GAP-020/021/024).
 *
 * Un document scellé (documents.legal_sealed=1) est en lecture seule : toute
 * écriture passe par assertWritable() qui lève LegalSealedException (→ 403 API).
 * Le scellement fixe retention_until via RetentionPolicyService (10 ans compta).
 *
 * Hors périmètre (dette tracée PARITE-REDX-TESTS) : horodatage qualifié TSA
 * (GAP-023) et export piste de révision (GAP-022).
 */

namespace KDocs\Services\Compliance;

use KDocs\Core\Database;

class LegalArchiveService
{
    private \PDO $db;
    private RetentionPolicyService $retention;

    public function __construct(?\PDO $db = null, ?RetentionPolicyService $retention = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->retention = $retention ?? new RetentionPolicyService();
    }

    /**
     * Scelle un document (WORM) : legal_sealed=1 + échéance de rétention.
     * Idempotent : re-sceller un document scellé ne change rien.
     *
     * @return array{sealed: bool, retention_until: ?string, already_sealed: bool}
     */
    public function seal(int $documentId, ?int $userId = null): array
    {
        $doc = $this->fetchDocument($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException("Document {$documentId} introuvable");
        }

        if ((int) ($doc['legal_sealed'] ?? 0) === 1) {
            return [
                'sealed' => true,
                'retention_until' => $doc['retention_until'] ?? null,
                'already_sealed' => true,
            ];
        }

        $retentionUntil = $this->retention->dueDate($doc)->format('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'UPDATE documents SET legal_sealed = 1, legal_sealed_at = ?, legal_sealed_by = ?, retention_until = ? WHERE id = ?'
        );
        $stmt->execute([$now, $userId, $retentionUntil, $documentId]);

        return [
            'sealed' => true,
            'retention_until' => $retentionUntil,
            'already_sealed' => false,
        ];
    }

    /**
     * Le document est-il scellé ?
     */
    public function isSealed(int $documentId): bool
    {
        $doc = $this->fetchDocument($documentId);

        return $doc !== null && (int) ($doc['legal_sealed'] ?? 0) === 1;
    }

    /**
     * Garde d'écriture : lève LegalSealedException si le document est scellé.
     *
     * @throws LegalSealedException
     */
    public function assertWritable(int $documentId): void
    {
        if ($this->isSealed($documentId)) {
            throw new LegalSealedException($documentId);
        }
    }

    /**
     * Compat scaffold P2 : archive = sceller.
     *
     * @return array{archived: bool, retention_until: ?string}
     */
    public function archiveDocument(int $documentId): array
    {
        $result = $this->seal($documentId);

        return [
            'archived' => $result['sealed'],
            'retention_until' => $result['retention_until'],
        ];
    }

    /**
     * @return array<string, mixed>|null Ligne documents + type_label
     */
    private function fetchDocument(int $documentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, dt.label AS type_label
             FROM documents d
             LEFT JOIN document_types dt ON dt.id = d.document_type_id
             WHERE d.id = ?'
        );
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
