-- K-Docs Migration: Vector Search Support
-- Adds columns for tracking embedding status and vector synchronization

-- Add embedding tracking columns to documents
ALTER TABLE documents
ADD COLUMN IF NOT EXISTS embedding_status ENUM('pending', 'processing', 'completed', 'failed', 'skipped') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS vector_updated_at DATETIME NULL,
ADD COLUMN IF NOT EXISTS embedding_error TEXT NULL,
ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64) NULL COMMENT 'SHA256 of content for change detection';

-- Index for efficient embedding queue queries
CREATE INDEX IF NOT EXISTS idx_documents_embedding_status ON documents(embedding_status, updated_at);
CREATE INDEX IF NOT EXISTS idx_documents_content_hash ON documents(content_hash);

-- Embedding jobs queue (for async processing)
CREATE TABLE IF NOT EXISTS embedding_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    priority ENUM('high', 'normal', 'low') DEFAULT 'normal',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uk_embedding_jobs_document (document_id),
    INDEX idx_embedding_jobs_status (status, priority, created_at)
);

-- Embedding statistics/logs
CREATE TABLE IF NOT EXISTS embedding_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NULL,
    action ENUM('create', 'update', 'delete', 'sync', 'error') NOT NULL,
    tokens_used INT NULL,
    processing_time_ms INT NULL,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_embedding_logs_document (document_id),
    INDEX idx_embedding_logs_action (action, created_at)
);

-- Settings for embedding configuration
INSERT IGNORE INTO settings (`key`, `value`, `type`, `description`) VALUES
('embedding_provider', 'openai', 'string', 'Embedding provider (openai, local)'),
('embedding_model', 'text-embedding-3-small', 'string', 'Embedding model name'),
('embedding_dimensions', '1536', 'integer', 'Vector dimensions'),
('embedding_auto_sync', '1', 'boolean', 'Auto-generate embeddings on document create/update'),
('qdrant_host', 'localhost', 'string', 'Qdrant server host'),
('qdrant_port', '6333', 'integer', 'Qdrant server port'),
('qdrant_collection', 'kdocs_documents', 'string', 'Qdrant collection name');
