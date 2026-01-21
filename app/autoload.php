<?php
/**
 * Autoloader simple PSR-4 pour K-Docs
 * Utilisé si Composer n'est pas disponible
 */

spl_autoload_register(function ($class) {
    // Namespace racine : KDocs
    $prefix = 'KDocs\\';
    $baseDir = __DIR__ . '/';
    
    // Vérifier si la classe utilise le namespace KDocs
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Récupérer le nom de classe relatif
    $relativeClass = substr($class, $len);
    
    // Remplacer les namespace separators par des directory separators
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    // Si le fichier existe, le charger
    if (file_exists($file)) {
        require $file;
    }
});
