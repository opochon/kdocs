# KDOCS - Installation DB consolidée
# Usage: .\install-db-consolidated.ps1

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host "  KDOCS - Installation base de donnees consolidee" -ForegroundColor Cyan
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host ""

$KdocsPath = "C:\wamp64\www\kdocs"
$SchemaFile = "$KdocsPath\database\schema_consolidated.sql"

# Trouver PHP
$phpPaths = @(
    "php",
    "C:\wamp64\bin\php\php8.3.14\php.exe",
    "C:\wamp64\bin\php\php8.2.0\php.exe",
    "C:\wamp64\bin\php\php8.1.0\php.exe"
)

$php = $null
foreach ($p in $phpPaths) {
    if (Get-Command $p -ErrorAction SilentlyContinue) {
        $php = $p
        break
    }
    if (Test-Path $p) {
        $php = $p
        break
    }
}

if (-not $php) {
    Write-Host "[ERREUR] PHP non trouve!" -ForegroundColor Red
    exit 1
}

Write-Host "[INFO] PHP: $php" -ForegroundColor Gray
Write-Host "[INFO] Schema: $SchemaFile" -ForegroundColor Gray
Write-Host ""

# Créer un script PHP pour exécuter le SQL
$phpScript = @'
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$configFile = 'C:/wamp64/www/kdocs/config/config.php';
if (!file_exists($configFile)) {
    die("ERREUR: Fichier config non trouve: $configFile\n");
}

$config = require $configFile;
$db = $config['database'];

echo "Connexion a {$db['host']}:{$db['port']}/{$db['name']}...\n";

try {
    // Connexion sans DB d'abord
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}",
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Créer la DB si nécessaire
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db['name']}`");
    
    echo "Base de donnees selectionnee.\n\n";
    
    // Lire le fichier SQL
    $sqlFile = 'C:/wamp64/www/kdocs/database/schema_consolidated.sql';
    if (!file_exists($sqlFile)) {
        die("ERREUR: Fichier SQL non trouve: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Séparer les statements
    // Remplacer les délimiteurs de commentaires pour éviter les problèmes
    $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
    
    // Exécuter statement par statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success = 0;
    $errors = 0;
    
    foreach ($statements as $stmt) {
        if (empty($stmt) || strlen($stmt) < 5) continue;
        
        // Ignorer les SET et commentaires
        if (preg_match('/^(SET|--|\/\*)/i', $stmt)) {
            try { $pdo->exec($stmt); } catch (Exception $e) {}
            continue;
        }
        
        try {
            $pdo->exec($stmt);
            $success++;
            
            // Afficher ce qui a été créé
            if (preg_match('/CREATE TABLE[^`]*`([^`]+)`/i', $stmt, $m)) {
                echo "  [OK] Table: {$m[1]}\n";
            } elseif (preg_match('/INSERT[^`]*`([^`]+)`/i', $stmt, $m)) {
                echo "  [OK] Insert: {$m[1]}\n";
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // Ignorer les erreurs "already exists"
            if (strpos($msg, 'already exists') !== false || 
                strpos($msg, 'Duplicate') !== false) {
                echo "  [SKIP] " . substr($stmt, 0, 50) . "... (existe deja)\n";
            } else {
                echo "  [ERR] " . substr($msg, 0, 80) . "\n";
                $errors++;
            }
        }
    }
    
    echo "\n";
    echo "==============================================\n";
    echo "Resultat: $success statements executes, $errors erreurs\n";
    echo "==============================================\n\n";
    
    // Vérifier les tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables creees (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        echo "  - $t\n";
    }
    
    echo "\n[OK] Installation terminee!\n";
    
} catch (PDOException $e) {
    die("ERREUR DB: " . $e->getMessage() . "\n");
}
'@

$tempFile = [System.IO.Path]::GetTempFileName() -replace '\.tmp$', '.php'
$phpScript | Out-File -FilePath $tempFile -Encoding UTF8

Write-Host "Execution du script d'installation..." -ForegroundColor Yellow
Write-Host ""

& $php $tempFile

Remove-Item $tempFile -Force -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "==============================================================" -ForegroundColor Green
Write-Host "  Verifiez dans phpMyAdmin que les tables sont creees" -ForegroundColor Green
Write-Host "==============================================================" -ForegroundColor Green
Write-Host ""

$open = Read-Host "Ouvrir phpMyAdmin ? (o/n)"
if ($open -eq "o") {
    Start-Process "http://localhost/phpmyadmin/index.php?route=/database/structure&db=kdocs"
}
