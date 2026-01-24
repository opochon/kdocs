<?php
/**
 * K-Docs - Logger de débogage (DÉSACTIVÉ pour performance)
 * 
 * Les appels file_put_contents avec LOCK_EX prenaient ~500ms par appel !
 */

namespace KDocs\Core;

class DebugLogger
{
    /**
     * Log un événement - DÉSACTIVÉ
     */
    public static function log(string $location, string $message, array $data = [], ?string $hypothesisId = null): void
    {
        // DÉSACTIVÉ - trop lent (500ms par appel avec LOCK_EX)
        return;
    }
    
    /**
     * Log une exception - DÉSACTIVÉ
     */
    public static function logException(\Throwable $e, string $location, ?string $hypothesisId = null): void
    {
        // DÉSACTIVÉ
        return;
    }
}
