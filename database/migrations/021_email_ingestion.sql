-- Email ingestion enhancement
-- Migration: 021_email_ingestion.sql
-- Adds ingestion-specific fields to existing mail_accounts table and creates logs table

-- Add new columns for email ingestion to existing mail_accounts table
ALTER TABLE mail_accounts
    ADD COLUMN IF NOT EXISTS folder VARCHAR(100) DEFAULT 'INBOX' COMMENT 'IMAP folder to monitor',
    ADD COLUMN IF NOT EXISTS processed_folder VARCHAR(100) DEFAULT NULL COMMENT 'Move processed emails here (null = keep)',
    ADD COLUMN IF NOT EXISTS check_interval INT DEFAULT 300 COMMENT 'Check interval in seconds',
    ADD COLUMN IF NOT EXISTS last_uid INT DEFAULT 0 COMMENT 'Last processed email UID',
    ADD COLUMN IF NOT EXISTS filter_from VARCHAR(255) DEFAULT NULL COMMENT 'Filter: only from these senders',
    ADD COLUMN IF NOT EXISTS filter_subject VARCHAR(255) DEFAULT NULL COMMENT 'Filter: subject pattern',
    ADD COLUMN IF NOT EXISTS default_correspondent_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS default_document_type_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS default_folder_id INT DEFAULT NULL;

-- Create email ingestion logs table
CREATE TABLE IF NOT EXISTS email_ingestion_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    email_uid INT NOT NULL,
    email_from VARCHAR(255),
    email_subject VARCHAR(500),
    email_date DATETIME,
    attachments_count INT DEFAULT 0,
    documents_created INT DEFAULT 0,
    status ENUM('success', 'error', 'skipped') NOT NULL DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account_date (account_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
