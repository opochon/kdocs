-- K-Docs Migration 031 : Quittances de lecture (plugin SMQ / phase C.3, GAP-032)
-- Trace l'accusé de lecture d'une version de document par un utilisateur.
-- Une quittance par (document, version, utilisateur) — une nouvelle version exige une nouvelle lecture.

CREATE TABLE IF NOT EXISTS document_read_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,
    user_id INT NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_receipt (document_id, version_number, user_id),
    INDEX idx_receipt_document (document_id),
    INDEX idx_receipt_user (user_id)
);
