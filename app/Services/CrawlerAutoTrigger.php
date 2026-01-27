<?php
/**
 * K-Docs - Service de déclenchement automatique du crawler
 * 
 * Gère le déclenchement automatique du crawler :
 * - Sur détection d'une nouvelle queue
 * - Toutes les 10 minutes
 * - À l'ouverture de l'app si dernier crawl > 10 minutes
 */

namespace KDocs\Services;

class CrawlerAutoTrigger
{
    private string $lockFile;
    private string $lastCrawlFile;
    private int $crawlInterval; // En secondes (10 minutes = 600)
    
    public function __construct()
    {
        $storageDir = __DIR__ . '/../../storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        
        $this->lockFile = $storageDir . '/crawler.lock';
        $this->lastCrawlFile = $storageDir . '/crawler_last_run.txt';
        $this->crawlInterval = 600; // 10 minutes
    }
    
    /**
     * Déclenche le crawler en arrière-plan (non bloquant)
     * 
     * @param bool $force Forcer l'exécution même si un lock existe
     * @return bool True si déclenché, false si déjà en cours ou trop récent
     */
    public function trigger(bool $force = false): bool
    {
        // Vérifier le lock (éviter les exécutions multiples)
        if (!$force && $this->isLocked()) {
            return false;
        }
        
        // Vérifier si le dernier crawl est trop récent (sauf si forcé)
        if (!$force && !$this->shouldRun()) {
            return false;
        }
        
        // Créer le lock
        $this->createLock();
        
        // Déclencher le crawler en arrière-plan (non bloquant)
        $this->runCrawlerAsync();
        
        return true;
    }
    
    /**
     * Vérifie si le crawler doit être exécuté
     * (dernier crawl > 10 minutes)
     */
    public function shouldRun(): bool
    {
        if (!file_exists($this->lastCrawlFile)) {
            return true; // Jamais exécuté
        }
        
        $lastRun = (int)@file_get_contents($this->lastCrawlFile);
        $now = time();
        
        return ($now - $lastRun) >= $this->crawlInterval;
    }
    
    /**
     * Vérifie si le crawler est verrouillé (en cours d'exécution)
     */
    private function isLocked(): bool
    {
        if (!file_exists($this->lockFile)) {
            return false;
        }
        
        $lockTime = (int)@file_get_contents($this->lockFile);
        $now = time();
        
        // Si le lock a plus de 5 minutes, le considérer comme expiré
        if (($now - $lockTime) > 300) {
            @unlink($this->lockFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * Crée un fichier de lock
     */
    private function createLock(): void
    {
        @file_put_contents($this->lockFile, time());
    }
    
    /**
     * Supprime le fichier de lock
     */
    public function releaseLock(): void
    {
        @unlink($this->lockFile);
    }
    
    /**
     * Met à jour le timestamp du dernier crawl
     */
    public function updateLastCrawl(): void
    {
        @file_put_contents($this->lastCrawlFile, time());
        $this->releaseLock();
    }
    
    /**
     * Lance le crawler en arrière-plan (non bloquant)
     * Compatible Windows et Linux
     */
    private function runCrawlerAsync(): void
    {
        $scriptPath = __DIR__ . '/../../cron/folder_crawler.php';
        $phpBinary = $this->getPhpBinary();
        
        if (!$phpBinary || !file_exists($scriptPath)) {
            error_log("CrawlerAutoTrigger: Cannot find PHP binary or script");
            $this->releaseLock();
            return;
        }
        
        // Commande à exécuter
        $phpPath = escapeshellarg($phpBinary);
        $scriptPathEscaped = escapeshellarg($scriptPath);
        
        // Windows : utiliser WScript pour lancer en arrière-plan sans fenêtre
        // Linux : utiliser nohup et & pour lancer en arrière-plan
        if (PHP_OS_FAMILY === 'Windows') {
            // Créer un script VBS temporaire pour lancer en arrière-plan
            $vbsScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kdocs_crawler_' . uniqid() . '.vbs';
            $vbsContent = "Set WshShell = CreateObject(\"WScript.Shell\")\n";
            $vbsContent .= "WshShell.Run \"$phpPath $scriptPathEscaped\", 0, False\n";
            $vbsContent .= "Set WshShell = Nothing\n";
            
            @file_put_contents($vbsScript, $vbsContent);
            
            // Lancer le script VBS qui lancera PHP en arrière-plan
            $command = 'cscript.exe //nologo ' . escapeshellarg($vbsScript);
            pclose(popen($command, 'r'));
            
            // Nettoyer le script VBS après un délai (non bloquant)
            // Note: Le script VBS sera supprimé automatiquement après exécution
            // Mais on peut aussi le supprimer immédiatement car il est déjà lancé
            @unlink($vbsScript);
        } else {
            // Linux/Unix : utiliser nohup et &
            $command = 'nohup ' . $phpPath . ' ' . $scriptPathEscaped . ' > /dev/null 2>&1 &';
            exec($command);
        }
    }
    
    /**
     * Trouve le binaire PHP
     */
    private function getPhpBinary(): ?string
    {
        // Essayer PHP_BINARY d'abord
        if (defined('PHP_BINARY') && PHP_BINARY) {
            return PHP_BINARY;
        }
        
        // Essayer php dans le PATH
        $php = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $output = [];
        $return = 0;
        exec($php . ' --version 2>&1', $output, $return);
        
        if ($return === 0) {
            return $php;
        }
        
        // Windows WAMP : chemin par défaut
        if (PHP_OS_FAMILY === 'Windows') {
            $wampPhp = 'C:\\wamp64\\bin\\php\\php8.3.14\\php.exe';
            if (file_exists($wampPhp)) {
                return $wampPhp;
            }
        }
        
        return null;
    }
    
    /**
     * Vérifie s'il y a des queues à traiter
     * Vérifie les deux sources : fichiers JSON ET table job_queue_jobs
     */
    public function hasQueues(): bool
    {
        // 1. Vérifier les fichiers JSON (ancien système)
        $queueDir = __DIR__ . '/../../storage/crawl_queue';
        if (is_dir($queueDir)) {
            $queues = glob($queueDir . '/crawl_*.json');
            if (!empty($queues)) {
                return true;
            }
        }
        
        // 2. Vérifier la table job_queue_jobs (nouveau système via QueueService)
        try {
            if (class_exists('\KDocs\Services\QueueService')) {
                $pendingIndexing = \KDocs\Services\QueueService::countPendingJobs('indexing');
                $pendingIndexingHigh = \KDocs\Services\QueueService::countPendingJobs('indexing_high');
                if ($pendingIndexing > 0 || $pendingIndexingHigh > 0) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs de connexion DB
        }
        
        return false;
    }
}
