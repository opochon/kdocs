<?php
/**
 * K-Docs - Script cron pour l'indexation des dossiers
 * 
 * À exécuter via cron ou automatiquement par l'application :
 * - Automatiquement sur détection de queue
 * - Toutes les 10 minutes
 * - À l'ouverture de l'app si dernier crawl > 10 minutes
 */

require_once __DIR__ . '/../app/autoload.php';
require_once __DIR__ . '/../app/workers/folder_crawler.php';

use KDocs\Services\CrawlerAutoTrigger;

try {
    $autoTrigger = new CrawlerAutoTrigger();
    
    $crawler = new FolderCrawler();
    
    // Traiter une tâche de la queue (une seule à la fois)
    $processed = $crawler->processQueue();
    
    // Mettre à jour le timestamp du dernier crawl
    $autoTrigger->updateLastCrawl();
    
    if ($processed > 0) {
        echo "Processed 1 queue task\n";
        exit(0);
    } else {
        echo "No queue tasks to process\n";
        exit(0);
    }
    
} catch (\Exception $e) {
    error_log("Cron folder_crawler error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    
    // Libérer le lock en cas d'erreur
    try {
        $autoTrigger = new CrawlerAutoTrigger();
        $autoTrigger->releaseLock();
    } catch (\Exception $e2) {
        // Ignorer les erreurs de libération du lock
    }
    
    exit(1);
}
