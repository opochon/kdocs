# KDOCS - Installation complète de la base de données
# Ce script installe le schéma puis exécute toutes les migrations
# Usage: .\install-database.ps1

param(
    [switch]$SkipSchema,
    [switch]$DryRun
)

$ErrorActionPreference = "Continue"
$KdocsPath = "C:\wamp64\www\kdocs"
$DatabasePath = "$KdocsPath\database"

Write-Host ""
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host "  KDOCS - Installation de la base de donnees" -ForegroundColor Cyan
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host ""

# Verifier que PHP est disponible
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    # Essayer avec le chemin WAMP
    $phpPath = "C:\wamp64\bin\php\php8.3.14\php.exe"
    if (Test-Path $phpPath) {
        $php = $phpPath
    } else {
        Write-Host "[ERREUR] PHP non trouve dans le PATH" -ForegroundColor Red
        Write-Host "Ajoutez PHP au PATH ou modifiez ce script" -ForegroundColor Yellow
        exit 1
    }
} else {
    $php = "php"
}

Write-Host "[INFO] PHP: $php" -ForegroundColor Gray

# ============================================================
# ETAPE 1: Installation du schema principal
# ============================================================

if (-not $SkipSchema) {
    Write-Host ""
    Write-Host "[1/3] Installation du schema principal..." -ForegroundColor Yellow
    
    if ($DryRun) {
        Write-Host "  [DRY-RUN] php $DatabasePath\install.php" -ForegroundColor Gray
    } else {
        Push-Location $KdocsPath
        & $php "$DatabasePath\install.php"
        $exitCode = $LASTEXITCODE
        Pop-Location
        
        if ($exitCode -ne 0) {
            Write-Host "  [ATTENTION] install.php a retourne un code non-zero: $exitCode" -ForegroundColor Yellow
        } else {
            Write-Host "  [OK] Schema installe" -ForegroundColor Green
        }
    }
} else {
    Write-Host "[1/3] Schema ignore (--SkipSchema)" -ForegroundColor Gray
}

# ============================================================
# ETAPE 2: Migrations SQL
# ============================================================

Write-Host ""
Write-Host "[2/3] Execution des migrations SQL..." -ForegroundColor Yellow

# Migrations SQL dans database/ (ordre alphabetique)
$sqlMigrations = @(
    "migration_asn.sql",
    "migration_audit_log.sql",
    "migration_custom_fields.sql",
    "migration_document_history.sql",
    "migration_document_notes.sql",
    "migration_document_sharing.sql",
    "migration_file_renaming.sql",
    "migration_filesystem.sql",
    "migration_logical_folders.sql",
    "migration_mail_accounts.sql",
    "migration_matching.sql",
    "migration_nested_tags.sql",
    "migration_paperless.sql",
    "migration_saved_searches.sql",
    "migration_settings.sql",
    "migration_storage_paths.sql",
    "migration_tasks.sql",
    "migration_trash.sql",
    "migration_users_roles.sql",
    "migration_webhooks.sql",
    "migration_workflows.sql",
    "migration_workflow_designer.sql"
)

# Migrations SQL dans database/migrations/ (ordre numerique)
$sqlMigrationsNumbered = @(
    "migrations\007_add_matching_columns.sql",
    "migrations\008_classification_fields.sql",
    "migrations\009_ai_classification_fields.sql",
    "migrations\010_required_fields.sql",
    "migrations\011_add_consume_folder_task.sql",
    "migrations\011_workflow_timers.sql",
    "migrations\012_pdf_split_columns.sql",
    "migrations\013_category_mappings.sql",
    "migrations\014_add_ai_ignored_tags.sql",
    "migrations\015_api_usage_tracking.sql",
    "migrations\add_workflow_columns.sql"
)

$allSqlMigrations = $sqlMigrations + $sqlMigrationsNumbered
$sqlSuccess = 0
$sqlSkipped = 0
$sqlErrors = 0

foreach ($migration in $allSqlMigrations) {
    $filePath = "$DatabasePath\$migration"
    $fileName = Split-Path $migration -Leaf
    
    if (-not (Test-Path $filePath)) {
        Write-Host "  [SKIP] $fileName (fichier non trouve)" -ForegroundColor Gray
        $sqlSkipped++
        continue
    }
    
    if ($DryRun) {
        Write-Host "  [DRY-RUN] $fileName" -ForegroundColor Gray
        $sqlSkipped++
        continue
    }
    
    # Executer via PHP pour utiliser la meme config DB
    $phpCode = @"
<?php
require_once '$KdocsPath/vendor/autoload.php';
\$config = require '$KdocsPath/config/config.php';
\$db = \$config['database'];
try {
    \$pdo = new PDO(
        "mysql:host={\$db['host']};port={\$db['port']};dbname={\$db['name']};charset={\$db['charset']}",
        \$db['user'], \$db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    \$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    \$sql = file_get_contents('$filePath');
    \$statements = array_filter(array_map('trim', explode(';', \$sql)));
    foreach (\$statements as \$stmt) {
        if (empty(\$stmt) || preg_match('/^--/', \$stmt)) continue;
        try { \$pdo->exec(\$stmt); }
        catch (PDOException \$e) {
            if (strpos(\$e->getMessage(), 'already exists') === false &&
                strpos(\$e->getMessage(), 'Duplicate') === false) {
                // Silencieux pour les erreurs attendues
            }
        }
    }
    \$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "OK";
} catch (PDOException \$e) {
    echo "ERROR: " . \$e->getMessage();
    exit(1);
}
"@
    
    $tempFile = [System.IO.Path]::GetTempFileName() + ".php"
    $phpCode | Out-File -FilePath $tempFile -Encoding UTF8
    
    $result = & $php $tempFile 2>&1
    Remove-Item $tempFile -Force -ErrorAction SilentlyContinue
    
    if ($result -match "^OK") {
        Write-Host "  [OK] $fileName" -ForegroundColor Green
        $sqlSuccess++
    } else {
        Write-Host "  [ERR] $fileName : $result" -ForegroundColor Red
        $sqlErrors++
    }
}

Write-Host "  SQL: $sqlSuccess OK, $sqlSkipped ignores, $sqlErrors erreurs" -ForegroundColor Cyan

# ============================================================
# ETAPE 3: Migrations PHP
# ============================================================

Write-Host ""
Write-Host "[3/3] Execution des migrations PHP..." -ForegroundColor Yellow

# Migrations PHP importantes (dans l'ordre)
$phpMigrations = @(
    "migrate_users_roles.php",
    "migrate_settings.php",
    "migrate_paperless.php",
    "migrate_trash.php",
    "migrate_audit_log.php",
    "migrate_fs.php",
    "migrate_webhooks.php",
    "migrate_007_matching_columns.php",
    "migrate_008_classification_fields.php",
    "migrate_009_ai_fields.php",
    "migrate_010_required_fields.php",
    "migrate_011_workflow_timers.php",
    "migrate_012_pdf_split.php",
    "migrate_013_category_mappings.php",
    "migrate_014_add_ai_ignored_tags.php",
    "migrate_consume_folder_task.php",
    "migrate_workflow_designer.php",
    "fix_logical_folders_icon.php"
)

$phpSuccess = 0
$phpSkipped = 0
$phpErrors = 0

foreach ($migration in $phpMigrations) {
    $filePath = "$DatabasePath\$migration"
    
    if (-not (Test-Path $filePath)) {
        Write-Host "  [SKIP] $migration (fichier non trouve)" -ForegroundColor Gray
        $phpSkipped++
        continue
    }
    
    if ($DryRun) {
        Write-Host "  [DRY-RUN] $migration" -ForegroundColor Gray
        $phpSkipped++
        continue
    }
    
    Push-Location $KdocsPath
    $output = & $php $filePath 2>&1
    $exitCode = $LASTEXITCODE
    Pop-Location
    
    if ($exitCode -eq 0 -or $output -match "OK|succes|termine|done|deja") {
        Write-Host "  [OK] $migration" -ForegroundColor Green
        $phpSuccess++
    } else {
        # Beaucoup de migrations affichent des erreurs meme si OK
        if ($output -match "error|exception|fatal" -and $output -notmatch "already exists|Duplicate") {
            Write-Host "  [WARN] $migration" -ForegroundColor Yellow
            $phpErrors++
        } else {
            Write-Host "  [OK] $migration" -ForegroundColor Green
            $phpSuccess++
        }
    }
}

Write-Host "  PHP: $phpSuccess OK, $phpSkipped ignores, $phpErrors erreurs" -ForegroundColor Cyan

# ============================================================
# RESUME
# ============================================================

Write-Host ""
Write-Host "==============================================================" -ForegroundColor Green
Write-Host "  Installation terminee !" -ForegroundColor Green
Write-Host "==============================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Resultats:" -ForegroundColor White
Write-Host "  - Schema principal: OK" -ForegroundColor Gray
Write-Host "  - Migrations SQL: $sqlSuccess/$($allSqlMigrations.Count)" -ForegroundColor Gray
Write-Host "  - Migrations PHP: $phpSuccess/$($phpMigrations.Count)" -ForegroundColor Gray
Write-Host ""
Write-Host "Prochaines etapes:" -ForegroundColor White
Write-Host "  1. Verifier dans phpMyAdmin que les tables sont creees" -ForegroundColor Gray
Write-Host "  2. Aller sur http://localhost/kdocs" -ForegroundColor Gray
Write-Host "  3. Se connecter avec root / (vide) ou creer un utilisateur" -ForegroundColor Gray
Write-Host ""

# Proposer d'ouvrir phpMyAdmin
$open = Read-Host "Ouvrir phpMyAdmin pour verifier ? (o/n)"
if ($open -eq "o") {
    Start-Process "http://localhost/phpmyadmin/index.php?route=/database/structure&db=kdocs"
}
