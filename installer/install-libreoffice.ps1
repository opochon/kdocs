# =============================================================================
# K-Docs - Installation de LibreOffice
# Script PowerShell pour Windows
# =============================================================================
# Usage: .\install-libreoffice.ps1
# Necessite les droits administrateur
# =============================================================================

param(
    [switch]$Silent,
    [switch]$Force
)

$ErrorActionPreference = "Stop"

# Configuration
$LibreOfficeVersion = "25.8.4"
$DownloadUrl = "https://download.documentfoundation.org/libreoffice/stable/$LibreOfficeVersion/win/x86_64/LibreOffice_${LibreOfficeVersion}_Win_x86-64.msi"
$InstallPath = "C:\Program Files\LibreOffice"
$TempDir = "$env:TEMP\kdocs-installer"
$InstallerFile = "$TempDir\LibreOffice_installer.msi"

# Couleurs
function Write-Step { param($msg) Write-Host "`n>> $msg" -ForegroundColor Cyan }
function Write-Success { param($msg) Write-Host "   [OK] $msg" -ForegroundColor Green }
function Write-Warning { param($msg) Write-Host "   [!] $msg" -ForegroundColor Yellow }
function Write-Error { param($msg) Write-Host "   [X] $msg" -ForegroundColor Red }

# Banner
Write-Host ""
Write-Host "=============================================" -ForegroundColor Blue
Write-Host "  K-Docs - Installation de LibreOffice" -ForegroundColor White
Write-Host "  Version: $LibreOfficeVersion" -ForegroundColor Gray
Write-Host "=============================================" -ForegroundColor Blue

# Verifier si admin
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Error "Ce script necessite les droits administrateur."
    Write-Host "   Relancez PowerShell en tant qu'administrateur." -ForegroundColor Gray
    exit 1
}

# Verifier si deja installe
Write-Step "Verification de l'installation existante..."
$sofficeExe = "$InstallPath\program\soffice.exe"
if (Test-Path $sofficeExe) {
    if (-not $Force) {
        Write-Success "LibreOffice est deja installe: $sofficeExe"
        Write-Host "`n   Utilisez -Force pour reinstaller." -ForegroundColor Gray
        exit 0
    }
    Write-Warning "LibreOffice existe, reinstallation forcee..."
}

# Creer dossier temp
Write-Step "Preparation du telechargement..."
if (-not (Test-Path $TempDir)) {
    New-Item -ItemType Directory -Path $TempDir -Force | Out-Null
}
Write-Success "Dossier temp: $TempDir"

# Telecharger LibreOffice
Write-Step "Telechargement de LibreOffice $LibreOfficeVersion..."
Write-Host "   URL: $DownloadUrl" -ForegroundColor Gray
Write-Host "   Cela peut prendre plusieurs minutes..." -ForegroundColor Gray

try {
    $ProgressPreference = 'SilentlyContinue'
    Invoke-WebRequest -Uri $DownloadUrl -OutFile $InstallerFile -UseBasicParsing
    $ProgressPreference = 'Continue'

    $fileSize = (Get-Item $InstallerFile).Length / 1MB
    Write-Success "Telechargement termine ($([math]::Round($fileSize, 1)) MB)"
} catch {
    Write-Error "Echec du telechargement: $_"
    Write-Host "`n   Verifiez votre connexion internet." -ForegroundColor Gray
    Write-Host "   URL alternative: https://www.libreoffice.org/download/download/" -ForegroundColor Gray
    exit 1
}

# Installer LibreOffice
Write-Step "Installation de LibreOffice..."
Write-Host "   Chemin: $InstallPath" -ForegroundColor Gray

$msiArgs = @(
    "/i", $InstallerFile,
    "/qn",  # Silent
    "/norestart",
    "INSTALLLOCATION=`"$InstallPath`"",
    "ADDLOCAL=ALL",
    "REMOVE=gm_o_Quickstart"  # Ne pas lancer au demarrage
)

if (-not $Silent) {
    $msiArgs[1] = "/qb"  # Basic UI avec barre de progression
}

try {
    $process = Start-Process -FilePath "msiexec.exe" -ArgumentList $msiArgs -Wait -PassThru

    if ($process.ExitCode -eq 0) {
        Write-Success "Installation terminee avec succes!"
    } elseif ($process.ExitCode -eq 3010) {
        Write-Success "Installation terminee (redemarrage recommande)"
    } else {
        Write-Error "L'installation a echoue avec le code: $($process.ExitCode)"
        exit 1
    }
} catch {
    Write-Error "Erreur lors de l'installation: $_"
    exit 1
}

# Verifier l'installation
Write-Step "Verification de l'installation..."
if (Test-Path $sofficeExe) {
    Write-Success "LibreOffice installe: $sofficeExe"

    # Tester la version
    try {
        $versionOutput = & "$sofficeExe" --version 2>&1
        Write-Host "   Version: $versionOutput" -ForegroundColor Gray
    } catch {
        Write-Warning "Impossible de verifier la version"
    }
} else {
    Write-Error "soffice.exe non trouve apres installation"
    exit 1
}

# Nettoyer
Write-Step "Nettoyage..."
Remove-Item -Path $InstallerFile -Force -ErrorAction SilentlyContinue
Write-Success "Fichier d'installation supprime"

# Configuration K-Docs
Write-Step "Configuration K-Docs..."
$configPath = Join-Path (Split-Path $PSScriptRoot -Parent) "config\config.php"
if (Test-Path $configPath) {
    Write-Success "K-Docs detectera automatiquement LibreOffice"
    Write-Host "   Chemin configure: $sofficeExe" -ForegroundColor Gray
} else {
    Write-Warning "config.php non trouve, configuration manuelle necessaire"
}

# Resume
Write-Host "`n=============================================" -ForegroundColor Green
Write-Host "  Installation terminee avec succes!" -ForegroundColor White
Write-Host "=============================================" -ForegroundColor Green
Write-Host ""
Write-Host "LibreOffice est maintenant disponible pour:" -ForegroundColor Cyan
Write-Host "  - Generation de miniatures Office (DOCX, XLSX, PPTX)" -ForegroundColor White
Write-Host "  - Conversion de documents" -ForegroundColor White
Write-Host ""
Write-Host "Redemarrez K-Docs pour appliquer les changements." -ForegroundColor Yellow
Write-Host ""
