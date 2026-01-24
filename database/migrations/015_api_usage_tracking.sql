-- Migration 015: Suivi des coûts API Claude
-- Date: 2026-01-22
-- Description: Table pour suivre l'utilisation et les coûts de l'API Claude

CREATE TABLE IF NOT EXISTS api_usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    model VARCHAR(100) NOT NULL COMMENT 'Modèle utilisé (ex: claude-sonnet-4-20250514)',
    request_type ENUM('text', 'file', 'complex') NOT NULL COMMENT 'Type de requête',
    input_tokens INT NOT NULL DEFAULT 0 COMMENT 'Nombre de tokens d''entrée',
    output_tokens INT NOT NULL DEFAULT 0 COMMENT 'Nombre de tokens de sortie',
    total_tokens INT NOT NULL DEFAULT 0 COMMENT 'Total des tokens',
    estimated_cost_usd DECIMAL(10, 6) NOT NULL DEFAULT 0 COMMENT 'Coût estimé en USD',
    document_id INT NULL COMMENT 'ID du document analysé (si applicable)',
    endpoint VARCHAR(255) NULL COMMENT 'Endpoint appelé (ex: /api/documents/123/analyze-with-ai)',
    success BOOLEAN DEFAULT TRUE COMMENT 'Succès de la requête',
    error_message TEXT NULL COMMENT 'Message d''erreur si échec',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_document_id (document_id),
    INDEX idx_model (model),
    INDEX idx_request_type (request_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
