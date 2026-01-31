# K-Docs - Script d'installation Windows
# Exécuter en tant qu'administrateur

param(
    [switch]$SkipDownload,
    [switch]$SkipInstall,
    [switch]$SkipDatabase,
    [switch]$Force,
    [string]$InstallPath = "C:\Tools"
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

# Couleurs
function Write-Header { param($text) Write-Host "`n=== $text ===" -ForegroundColor Cyan }
function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }
function Write-Error { param($text) Write-Host "[X] $text" -ForegroundColor Red }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }

# Vérifier les droits admin
function Test-Administrator {
    $currentUser = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($currentUser)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# Banner
Write-Host @"

  _  __    ____
 | |/ /   |  _ \  ___   ___ ___
 | ' / ___| | | |/ _ \ / __/ __|
 | . \___| |_| | (_) | (__\__ \
 |_|\_\  |____/ \___/ \___|___/

  Installation Windows

"@ -ForegroundColor Cyan

# Vérification admin
if (-not (Test-Administrator)) {
    Write-Error "Ce script doit etre execute en tant qu'administrateur!"
    Write-Info "Clic droit sur PowerShell > Executer en tant qu'administrateur"
    exit 1
}

$ScriptRoot = $PSScriptRoot
$DownloadsDir = Join-Path $ScriptRoot "downloads"
$ConfigFile = Join-Path $ScriptRoot "config\tools.json"

# Créer les dossiers nécessaires
if (-not (Test-Path $DownloadsDir)) { New-Item -ItemType Directory -Path $DownloadsDir -Force | Out-Null }
if (-not (Test-Path $InstallPath)) { New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null }

Write-Header "Verification des prerequis"

# Vérifier PHP
$phpPath = $null
$phpPaths = @(
    "C:\wamp64\bin\php\php8.4.0\php.exe",
    "C:\wamp64\bin\php\php8.3.14\php.exe",
    "C:\wamp64\bin\php\php8.2.0\php.exe",
    "C:\xampp\php\php.exe"
)
foreach ($p in $phpPaths) {
    if (Test-Path $p) { $phpPath = $p; break }
}

if ($phpPath) {
    $phpVersion = & $phpPath -v 2>$null | Select-Object -First 1
    Write-Success "PHP trouve: $phpVersion"
} else {
    Write-Error "PHP non trouve! Installez WAMP64 ou XAMPP d'abord."
    exit 1
}

# Vérifier MySQL
$mysqlPath = $null
$mysqlPaths = @(
    "C:\wamp64\bin\mysql\mysql8.0.31\bin\mysql.exe",
    "C:\wamp64\bin\mysql\mysql8.3.0\bin\mysql.exe",
    "C:\wamp64\bin\mariadb\mariadb11.5.2\bin\mysql.exe",
    "C:\xampp\mysql\bin\mysql.exe"
)
foreach ($p in $mysqlPaths) {
    if (Test-Path $p) { $mysqlPath = $p; break }
}

# Recherche dynamique si pas trouvé
if (-not $mysqlPath) {
    $found = Get-ChildItem "C:\wamp64\bin\mysql" -Filter "mysql.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $found) {
        $found = Get-ChildItem "C:\wamp64\bin\mariadb" -Filter "mysql.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    }
    if ($found) { $mysqlPath = $found.FullName }
}

if ($mysqlPath) {
    Write-Success "MySQL/MariaDB trouve: $mysqlPath"
} else {
    Write-Warning "MySQL non trouve - configuration base de donnees manuelle requise"
}

Write-Header "Telechargement des outils"

if (-not $SkipDownload) {
    & "$ScriptRoot\scripts\download-tools.ps1" -DownloadsDir $DownloadsDir -Force:$Force
} else {
    Write-Info "Telechargement ignore (-SkipDownload)"
}

Write-Header "Installation des outils"

if (-not $SkipInstall) {
    & "$ScriptRoot\scripts\install-tools.ps1" -DownloadsDir $DownloadsDir -InstallPath $InstallPath -Force:$Force
} else {
    Write-Info "Installation ignoree (-SkipInstall)"
}

Write-Header "Configuration de la base de donnees"

if (-not $SkipDatabase -and $mysqlPath) {
    & "$ScriptRoot\scripts\setup-database.ps1" -MySqlPath $mysqlPath
} else {
    Write-Info "Configuration base de donnees ignoree"
}

Write-Header "Configuration de l'application"

& "$ScriptRoot\scripts\configure-app.ps1" -InstallPath $InstallPath

Write-Header "Verification finale"

& "$ScriptRoot\scripts\verify-install.ps1"

Write-Host "`n" -NoNewline
Write-Success "Installation terminee!"
Write-Host @"

Prochaines etapes:
1. Demarrer WAMP/XAMPP
2. Acceder a http://localhost/kdocs
3. Se connecter avec admin / admin (changer le mot de passe!)

"@ -ForegroundColor White
