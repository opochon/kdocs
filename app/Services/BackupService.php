<?php
/**
 * K-DOCS - Backup Service
 * Backup automatique de la base de données avec vérification
 */

namespace KDocs\Services;

use KDocs\Core\Database;
use KDocs\Core\Config;
use KDocs\Core\ErrorTracker;

class BackupService
{
    private string $backupPath;
    private \PDO $db;
    private array $config;
    
    public function __construct()
    {
        $this->backupPath = dirname(__DIR__, 2) . '/storage/backups';
        $this->db = Database::getInstance();
        $this->config = Config::load()['database'] ?? [];
        
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }
    
    /**
     * Crée un backup complet
     */
    public function create(string $name = null): array
    {
        $name = $name ?? date('Y-m-d_H-i-s');
        $filename = "backup_{$name}.sql";
        $filepath = $this->backupPath . '/' . $filename;
        
        $dbName = $this->config['database'] ?? $this->config['name'] ?? 'kdocs';
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $user = $this->config['username'] ?? $this->config['user'] ?? 'root';
        $pass = $this->config['password'] ?? '';
        
        // Essayer mysqldump d'abord
        $mysqldump = $this->findMysqldump();
        
        if ($mysqldump) {
            $cmd = sprintf(
                '"%s" --host=%s --port=%d --user=%s %s %s > "%s" 2>&1',
                $mysqldump,
                escapeshellarg($host),
                $port,
                escapeshellarg($user),
                $pass ? '--password=' . escapeshellarg($pass) : '',
                escapeshellarg($dbName),
                $filepath
            );
            
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0) {
                // Fallback sur export PHP
                $this->exportWithPHP($filepath);
            }
        } else {
            // Export PHP si mysqldump non disponible
            $this->exportWithPHP($filepath);
        }
        
        // Vérifier le backup
        if (!file_exists($filepath) || filesize($filepath) < 100) {
            throw new \RuntimeException("Backup failed: file empty or not created");
        }
        
        // Compresser si > 1MB
        $compressed = false;
        if (filesize($filepath) > 1024 * 1024) {
            $gzPath = $filepath . '.gz';
            $this->compress($filepath, $gzPath);
            unlink($filepath);
            $filepath = $gzPath;
            $filename .= '.gz';
            $compressed = true;
        }
        
        // Enregistrer metadata
        $metadata = [
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'compressed' => $compressed,
            'created_at' => date('Y-m-d H:i:s'),
            'tables' => $this->getTableCount(),
            'checksum' => md5_file($filepath),
        ];
        
        file_put_contents(
            $this->backupPath . "/backup_{$name}.json",
            json_encode($metadata, JSON_PRETTY_PRINT)
        );
        
        return $metadata;
    }
    
    /**
     * Vérifie l'intégrité d'un backup
     */
    public function verify(string $filename): array
    {
        $filepath = $this->backupPath . '/' . $filename;
        $metaPath = preg_replace('/\.(sql|sql\.gz)$/', '.json', $filepath);
        
        if (!file_exists($filepath)) {
            return ['valid' => false, 'error' => 'Backup file not found'];
        }
        
        // Vérifier checksum si metadata existe
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            $currentChecksum = md5_file($filepath);
            
            if ($meta['checksum'] !== $currentChecksum) {
                return ['valid' => false, 'error' => 'Checksum mismatch - file corrupted'];
            }
        }
        
        // Vérifier contenu
        $content = $this->readBackup($filepath);
        
        if (strpos($content, 'CREATE TABLE') === false && strpos($content, 'INSERT INTO') === false) {
            return ['valid' => false, 'error' => 'Backup appears empty or invalid'];
        }
        
        // Compter les tables
        preg_match_all('/CREATE TABLE[^`]*`([^`]+)`/', $content, $matches);
        $tables = $matches[1] ?? [];
        
        return [
            'valid' => true,
            'size' => filesize($filepath),
            'tables' => count($tables),
            'table_names' => $tables,
        ];
    }
    
    /**
     * Restaure un backup
     */
    public function restore(string $filename): bool
    {
        $filepath = $this->backupPath . '/' . $filename;
        
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Backup file not found: $filename");
        }
        
        // Vérifier d'abord
        $verification = $this->verify($filename);
        if (!$verification['valid']) {
            throw new \RuntimeException("Backup invalid: " . $verification['error']);
        }
        
        $content = $this->readBackup($filepath);
        
        // Exécuter le restore
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $statements = $this->splitStatements($content);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if (!empty($stmt) && $stmt !== ';') {
                try {
                    $this->db->exec($stmt);
                } catch (\Exception $e) {
                    ErrorTracker::log('warning', "Restore statement failed: " . substr($stmt, 0, 100), [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        return true;
    }
    
    /**
     * Liste les backups disponibles
     */
    public function list(): array
    {
        $files = glob($this->backupPath . '/backup_*.sql*');
        $backups = [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            if (strpos($filename, '.json') !== false) continue;
            
            $metaPath = preg_replace('/\.(sql|sql\.gz)$/', '.json', $file);
            $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
            
            $backups[] = [
                'filename' => $filename,
                'size' => filesize($file),
                'created_at' => $meta['created_at'] ?? date('Y-m-d H:i:s', filemtime($file)),
                'tables' => $meta['tables'] ?? null,
            ];
        }
        
        // Trier par date décroissante
        usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        
        return $backups;
    }
    
    /**
     * Supprime les vieux backups (garde les N derniers)
     */
    public function cleanup(int $keep = 10): int
    {
        $backups = $this->list();
        $toDelete = array_slice($backups, $keep);
        $deleted = 0;
        
        foreach ($toDelete as $backup) {
            $filepath = $this->backupPath . '/' . $backup['filename'];
            $metaPath = preg_replace('/\.(sql|sql\.gz)$/', '.json', $filepath);
            
            if (file_exists($filepath)) {
                unlink($filepath);
                $deleted++;
            }
            if (file_exists($metaPath)) {
                unlink($metaPath);
            }
        }
        
        return $deleted;
    }
    
    private function exportWithPHP(string $filepath): void
    {
        $output = [];
        $output[] = "-- K-Docs Database Backup";
        $output[] = "-- Generated: " . date('Y-m-d H:i:s');
        $output[] = "-- PHP Export (mysqldump not available)";
        $output[] = "";
        $output[] = "SET FOREIGN_KEY_CHECKS = 0;";
        $output[] = "";
        
        // Get tables
        $tables = $this->db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            // Structure
            $create = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_ASSOC);
            $output[] = "DROP TABLE IF EXISTS `$table`;";
            $output[] = $create['Create Table'] . ";";
            $output[] = "";
            
            // Data
            $rows = $this->db->query("SELECT * FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $values = array_map(fn($v) => $v === null ? 'NULL' : $this->db->quote($v), $row);
                $output[] = "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");";
            }
            $output[] = "";
        }
        
        $output[] = "SET FOREIGN_KEY_CHECKS = 1;";
        
        file_put_contents($filepath, implode("\n", $output));
    }
    
    private function readBackup(string $filepath): string
    {
        if (substr($filepath, -3) === '.gz') {
            return gzdecode(file_get_contents($filepath));
        }
        return file_get_contents($filepath);
    }
    
    private function compress(string $source, string $dest): void
    {
        $content = file_get_contents($source);
        file_put_contents($dest, gzencode($content, 9));
    }
    
    private function splitStatements(string $sql): array
    {
        // Split simple par ; en fin de ligne
        return preg_split('/;\s*$/m', $sql);
    }
    
    private function getTableCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    }
    
    private function findMysqldump(): ?string
    {
        $paths = [
            'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql5.7.36\\bin\\mysqldump.exe',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        // Essayer dans PATH
        $result = shell_exec('which mysqldump 2>/dev/null') ?? shell_exec('where mysqldump 2>nul');
        if ($result) {
            return trim($result);
        }
        
        return null;
    }
}
