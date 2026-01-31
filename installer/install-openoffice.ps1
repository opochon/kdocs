# =============================================================================
# K-Docs - Installation d'Apache OpenOffice (Alternative)
# Script PowerShell pour Windows
# =============================================================================
# Usage: .\install-openoffice.ps1
# Necessite les droits administrateur
# Note: LibreOffice est recommande, OpenOffice est une alternative
# =============================================================================

param(
    [switch]$Silent,
    [switch]$Force
)

$ErrorActionPreference = "Stop"

# Configuration
$OpenOfficeVersion = "4.1.15"
$DownloadUrl = "https://sourceforge.net/projects/openofficeorg.mirror/files/$OpenOfficeVersion/binaries/fr/Apache_OpenOffice_${OpenOfficeVersion}_Win_x86-64_install_fr.exe/download"
$InstallPath = "C:\Program Files\OpenOffice"
$TempDir = "$env:TEMP\kdocs-installer"
$InstallerFile = "$TempDir\OpenOffice_installer.exe"

# Couleurs
function Write-Step { param($msg) Write-Host "`n>> $msg" -ForegroundColor Cyan }
function Write-Success { param($msg) Write-Host "   [OK] $msg" -ForegroundColor Green }
function Write-Warning { param($msg) Write-Host "   [!] $msg" -ForegroundColor Yellow }
function Write-Error { param($msg) Write-Host "   [X] $msg" -ForegroundColor Red }

# Banner
Write-Host ""
Write-Host "=============================================" -ForegroundColor DarkYellow
Write-Host "  K-Docs - Installation d'Apache OpenOffice" -ForegroundColor White
Write-Host "  Version: $OpenOfficeVersion (Alternative)" -ForegroundColor Gray
Write-Host "=============================================" -ForegroundColor DarkYellow
Write-Host ""
Write-Warning "LibreOffice est recommande pour de meilleures performances."
Write-Host "   Utilisez install-libreoffice.ps1 si possible." -ForegroundColor Gray

# Verifier si admin
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Error "Ce script necessite les droits administrateur."
    Write-Host "   Relancez PowerShell en tant qu'administrateur." -ForegroundColor Gray
    exit 1
}

# Verifier si LibreOffice est deja installe
$libreOfficePath = "C:\Program Files\LibreOffice\program\soffice.exe"
if (Test-Path $libreOfficePath) {
    Write-Warning "LibreOffice est deja installe!"
    Write-Host "   K-Docs utilisera LibreOffice en priorite." -ForegroundColor Gray
    $continue = Read-Host "   Voulez-vous quand meme installer OpenOffice? (o/N)"
    if ($continue -ne "o" -and $continue -ne "O") {
        Write-Host "   Installation annulee." -ForegroundColor Gray
        exit 0
    }
}

# Verifier si OpenOffice est deja installe
Write-Step "Verification de l'installation existante..."
$sofficePaths = @(
    "$InstallPath\program\soffice.exe",
    "C:\Program Files (x86)\OpenOffice 4\program\soffice.exe",
    "C:\Program Files\OpenOffice 4\program\soffice.exe"
)
$existingPath = $sofficePaths | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($existingPath -and -not $Force) {
    Write-Success "OpenOffice est deja installe: $existingPath"
    Write-Host "`n   Utilisez -Force pour reinstaller." -ForegroundColor Gray
    exit 0
}

# Creer dossier temp
Write-Step "Preparation du telechargement..."
if (-not (Test-Path $TempDir)) {
    New-Item -ItemType Directory -Path $TempDir -Force | Out-Null
}
Write-Success "Dossier temp: $TempDir"

# Telecharger OpenOffice
Write-Step "Telechargement d'Apache OpenOffice $OpenOfficeVersion..."
Write-Host "   URL: SourceForge Mirror" -ForegroundColor Gray
Write-Host "   Cela peut prendre plusieurs minutes..." -ForegroundColor Gray

try {
    $ProgressPreference = 'SilentlyContinue'
    # SourceForge redirige, on doit suivre
    Invoke-WebRequest -Uri $DownloadUrl -OutFile $InstallerFile -UseBasicParsing -MaximumRedirection 5
    $ProgressPreference = 'Continue'

    $fileSize = (Get-Item $InstallerFile).Length / 1MB
    Write-Success "Telechargement termine ($([math]::Round($fileSize, 1)) MB)"
} catch {
    Write-Error "Echec du telechargement: $_"
    Write-Host "`n   Telechargez manuellement depuis:" -ForegroundColor Gray
    Write-Host "   https://www.openoffice.org/download/" -ForegroundColor Cyan
    exit 1
}

# Installer OpenOffice
Write-Step "Installation d'Apache OpenOffice..."
Write-Host "   L'installeur va s'ouvrir..." -ForegroundColor Gray

$installerArgs = @("/S")  # Silent install

if (-not $Silent) {
    $installerArgs = @()  # Interactive
    Write-Host "   Suivez les instructions de l'installeur." -ForegroundColor Yellow
}

try {
    $process = Start-Process -FilePath $InstallerFile -ArgumentList $installerArgs -Wait -PassThru

    if ($process.ExitCode -eq 0) {
        Write-Success "Installation terminee!"
    } else {
        Write-Warning "Code de sortie: $($process.ExitCode)"
    }
} catch {
    Write-Error "Erreur lors de l'installation: $_"
    exit 1
}

# Verifier l'installation
Write-Step "Verification de l'installation..."
$installedPath = $sofficePaths | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($installedPath) {
    Write-Success "OpenOffice installe: $installedPath"
} else {
    Write-Warning "soffice.exe non trouve. Verifiez l'installation manuelle."
}

# Nettoyer
Write-Step "Nettoyage..."
Remove-Item -Path $InstallerFile -Force -ErrorAction SilentlyContinue
Write-Success "Fichier d'installation supprime"

# Mettre a jour la config K-Docs
Write-Step "Configuration K-Docs..."

if ($installedPath) {
    $configPath = Join-Path (Split-Path $PSScriptRoot -Parent) "config\config.php"

    Write-Host "   Pour utiliser OpenOffice, modifiez config/config.php:" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "   'libreoffice' => '$($installedPath -replace '\\', '\\\\')'," -ForegroundColor White
    Write-Host ""
}

# Resume
Write-Host "`n=============================================" -ForegroundColor Green
Write-Host "  Installation terminee!" -ForegroundColor White
Write-Host "=============================================" -ForegroundColor Green
Write-Host ""
Write-Host "Note: LibreOffice reste recommande pour K-Docs." -ForegroundColor Yellow
Write-Host ""
