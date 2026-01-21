-- Migration pour le partage de documents (Priorité 3.3)
CREATE TABLE IF NOT EXISTS document_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    shared_by INT NOT NULL,
    shared_with INT,
    share_token VARCHAR(64) UNIQUE,
    expires_at DATETIME,
    can_view BOOLEAN DEFAULT TRUE,
    can_download BOOLEAN DEFAULT FALSE,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_document_id (document_id),
    INDEX idx_share_token (share_token)
);
