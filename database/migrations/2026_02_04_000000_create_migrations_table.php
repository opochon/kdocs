<?php
/**
 * Migration: create_migrations_table
 * Created: 2026-02-04
 */

return new class {
    public function up(PDO $db): void
    {
        // Table créée automatiquement par le système de migrations
    }
    
    public function down(PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS migrations");
    }
};
