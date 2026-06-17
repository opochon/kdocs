<?php
/**
 * Migration: create_error_logs_table
 * Created: 2026-02-04
 */

return new class {
    public function up(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS error_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(20) NOT NULL DEFAULT 'ERROR',
                message TEXT NOT NULL,
                context JSON,
                file VARCHAR(500),
                line INT,
                trace JSON,
                request_uri VARCHAR(500),
                request_method VARCHAR(10),
                user_id INT UNSIGNED,
                ip_address VARCHAR(45),
                user_agent VARCHAR(500),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_level (level),
                INDEX idx_created_at (created_at),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS error_logs");
    }
};
