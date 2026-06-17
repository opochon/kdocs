<?php
/**
 * K-DOCS - Database Migrations
 * Système de migrations versionnées avec rollback
 */

namespace KDocs\Core;

class Migrations
{
    private \PDO $db;
    private string $migrationsPath;
    private string $migrationsTable = 'migrations';
    
    public function __construct(\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->migrationsPath = dirname(__DIR__, 2) . '/database/migrations';
        $this->ensureMigrationsTable();
    }
    
    /**
     * Exécute toutes les migrations en attente
     */
    public function migrate(): array
    {
        $pending = $this->getPending();
        $executed = [];
        
        foreach ($pending as $migration) {
            try {
                $this->runMigration($migration, 'up');
                $executed[] = $migration;
            } catch (\Exception $e) {
                ErrorTracker::capture($e, ['migration' => $migration]);
                throw new \RuntimeException("Migration failed: $migration - " . $e->getMessage());
            }
        }
        
        return $executed;
    }
    
    /**
     * Rollback la dernière migration (ou N migrations)
     */
    public function rollback(int $steps = 1): array
    {
        $executed = $this->getExecuted();
        $toRollback = array_slice($executed, -$steps);
        $rolledBack = [];
        
        foreach (array_reverse($toRollback) as $migration) {
            try {
                $this->runMigration($migration, 'down');
                $rolledBack[] = $migration;
            } catch (\Exception $e) {
                ErrorTracker::capture($e, ['migration' => $migration, 'direction' => 'down']);
                throw new \RuntimeException("Rollback failed: $migration - " . $e->getMessage());
            }
        }
        
        return $rolledBack;
    }
    
    /**
     * Liste les migrations en attente
     */
    public function getPending(): array
    {
        $all = $this->getAllMigrations();
        $executed = $this->getExecuted();
        
        return array_values(array_diff($all, $executed));
    }
    
    /**
     * Liste les migrations exécutées
     */
    public function getExecuted(): array
    {
        $stmt = $this->db->query("SELECT migration FROM {$this->migrationsTable} ORDER BY batch, migration");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    
    /**
     * Statut des migrations
     */
    public function status(): array
    {
        $all = $this->getAllMigrations();
        $executed = $this->getExecuted();
        $pending = array_diff($all, $executed);
        
        return [
            'total' => count($all),
            'executed' => count($executed),
            'pending' => count($pending),
            'pending_list' => array_values($pending),
            'last_batch' => $this->getLastBatch(),
        ];
    }
    
    /**
     * Crée une nouvelle migration
     */
    public function create(string $name): string
    {
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }
        
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $this->migrationsPath . '/' . $filename;
        
        $template = <<<'PHP'
<?php
/**
 * Migration: {{NAME}}
 * Created: {{DATE}}
 */

return new class {
    public function up(PDO $db): void
    {
        // $db->exec("CREATE TABLE ...");
    }
    
    public function down(PDO $db): void
    {
        // $db->exec("DROP TABLE ...");
    }
};
PHP;
        
        $content = str_replace(
            ['{{NAME}}', '{{DATE}}'],
            [$name, date('Y-m-d H:i:s')],
            $template
        );
        
        file_put_contents($filepath, $content);
        
        return $filename;
    }
    
    private function runMigration(string $migration, string $direction): void
    {
        $filepath = $this->migrationsPath . '/' . $migration . '.php';
        
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Migration file not found: $filepath");
        }
        
        $migrationClass = require $filepath;
        
        $this->db->beginTransaction();
        
        try {
            if ($direction === 'up') {
                $migrationClass->up($this->db);
                $this->recordMigration($migration);
            } else {
                $migrationClass->down($this->db);
                $this->removeMigration($migration);
            }
            
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    private function getAllMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }
        
        $files = glob($this->migrationsPath . '/*.php');
        $migrations = [];
        
        foreach ($files as $file) {
            $migrations[] = basename($file, '.php');
        }
        
        sort($migrations);
        return $migrations;
    }
    
    private function ensureMigrationsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
    
    private function getLastBatch(): int
    {
        $stmt = $this->db->query("SELECT MAX(batch) FROM {$this->migrationsTable}");
        return (int) $stmt->fetchColumn() ?: 0;
    }
    
    private function recordMigration(string $migration): void
    {
        $batch = $this->getLastBatch() + 1;
        $stmt = $this->db->prepare("INSERT INTO {$this->migrationsTable} (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);
    }
    
    private function removeMigration(string $migration): void
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->migrationsTable} WHERE migration = ?");
        $stmt->execute([$migration]);
    }
}
