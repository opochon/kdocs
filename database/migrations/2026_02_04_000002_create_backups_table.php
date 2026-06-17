<?php
/**
 * Migration: create_backups_table
 * Created: 2026-02-04
 */

return new class {
    public function up(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS backups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                filepath VARCHAR(500) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                tables_count INT UNSIGNED NOT NULL DEFAULT 0,
                checksum VARCHAR(64),
                compressed TINYINT(1) NOT NULL DEFAULT 0,
                verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS backups");
    }
};
