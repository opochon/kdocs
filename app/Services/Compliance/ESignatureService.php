<?php
/**
 * Service de signature électronique (GAP-043).
 *
 * Calcul du hash :
 *   content_hash = SHA-256(id + title + content)   (concaténation des trois champs)
 *
 * Signature :
 *   signature = hex(HMAC-SHA256(content_hash, secret))
 *   secret = paramètre ou env('APP_KEY', 'kdocs-esign')
 *
 * Idempotence : signer le même (document, user) une deuxième fois retourne
 * la signature existante sans écrire en base (already_signed=true).
 *
 * Chaque nouvelle signature produit une ligne dans audit_logs
 * (action 'document.signed', objet 'document').
 */

namespace KDocs\Services\Compliance;

use KDocs\Core\Database;

class ESignatureService
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Signe un document pour un utilisateur donné.
     *
     * Si le couple (document_id, user_id) est déjà dans document_signatures,
     * retourne l'entrée existante sans écrire de nouveau (idempotent).
     *
     * @param int         $documentId ID du document
     * @param int         $userId     ID de l'utilisateur signataire
     * @param string|null $secret     Clé HMAC (null = env('APP_KEY','kdocs-esign'))
     *
     * @return array{signature: string, content_hash: string, signed_at: string, already_signed: bool}
     * @throws \InvalidArgumentException Si le document est introuvable
     */
    public function sign(int $documentId, int $userId, ?string $secret = null): array
    {
        // 1. Récupérer le document
        $doc = $this->fetchDocument($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException("Document {$documentId} introuvable");
        }

        // 2. Vérifier si déjà signé (idempotence)
        $existing = $this->fetchSignature($documentId, $userId);
        if ($existing !== null) {
            return [
                'signature'    => $existing['signature'],
                'content_hash' => $existing['content_hash'],
                'signed_at'    => $existing['signed_at'],
                'already_signed' => true,
            ];
        }

        // 3. Calculer le hash du contenu (id + title + content)
        $payload     = (string) $doc['id'] . ($doc['title'] ?? '') . ($doc['content'] ?? '');
        $contentHash = hash('sha256', $payload);

        // 4. Calculer la signature HMAC-SHA256
        $key       = $secret ?? (function_exists('env') ? env('APP_KEY', 'kdocs-esign') : 'kdocs-esign');
        $signature = hash_hmac('sha256', $contentHash, $key);

        // 5. Insérer dans document_signatures
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO document_signatures (document_id, user_id, content_hash, signature, signed_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$documentId, $userId, $contentHash, $signature, $now]);

        // 6. Tracer dans audit_logs
        $this->writeAuditLog($documentId, $userId, $doc['title'] ?? null, $now);

        return [
            'signature'      => $signature,
            'content_hash'   => $contentHash,
            'signed_at'      => $now,
            'already_signed' => false,
        ];
    }

    /**
     * Vérifie si la signature d'un document est toujours valide.
     *
     * Recalcule le hash actuel du contenu et compare à celui stocké.
     * Retourne false si le contenu a été modifié depuis la signature.
     *
     * @param int         $documentId
     * @param int         $userId
     * @param string|null $secret Clé HMAC (null = env('APP_KEY','kdocs-esign'))
     * @return bool
     */
    public function verify(int $documentId, int $userId, ?string $secret = null): bool
    {
        $existing = $this->fetchSignature($documentId, $userId);
        if ($existing === null) {
            return false;
        }

        $doc = $this->fetchDocument($documentId);
        if ($doc === null) {
            return false;
        }

        // Recalculer le hash courant
        $payload     = (string) $doc['id'] . ($doc['title'] ?? '') . ($doc['content'] ?? '');
        $currentHash = hash('sha256', $payload);

        // Vérifier que le hash correspond et que la signature HMAC est toujours valide
        if (!hash_equals($existing['content_hash'], $currentHash)) {
            return false;
        }

        $key              = $secret ?? (function_exists('env') ? env('APP_KEY', 'kdocs-esign') : 'kdocs-esign');
        $expectedSignature = hash_hmac('sha256', $currentHash, $key);

        return hash_equals($existing['signature'], $expectedSignature);
    }

    // -------------------------------------------------------------------------
    // Méthodes internes
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDocument(int $documentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, content FROM documents WHERE id = ?'
        );
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSignature(int $documentId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM document_signatures WHERE document_id = ? AND user_id = ?'
        );
        $stmt->execute([$documentId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Insère une ligne dans audit_logs (INSERT portable SQLite/MySQL).
     */
    private function writeAuditLog(int $documentId, int $userId, ?string $docTitle, string $now): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_logs
                    (user_id, action, object_type, object_id, object_name, changes, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                'document.signed',
                'document',
                $documentId,
                $docTitle,
                null,
                null,
                null,
                $now,
            ]);
        } catch (\Throwable $e) {
            // Audit non bloquant : la table peut ne pas exister en dev
            error_log("ESignatureService::writeAuditLog: " . $e->getMessage());
        }
    }
}
