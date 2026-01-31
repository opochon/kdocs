# K-Docs - Désinstallation des outils
# Exécuter en tant qu'administrateur

param(
    [switch]$KeepDownloads,
    [switch]$Force
)

$ErrorActionPreference = "Stop"

function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }

# Vérifier admin
$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($currentUser)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "[X] Ce script doit etre execute en tant qu'administrateur!" -ForegroundColor Red
    exit 1
}

Write-Host "`nDesinstallation des outils K-Docs" -ForegroundColor Cyan
Write-Host "=" * 35

if (-not $Force) {
    $confirm = Read-Host "`nEtes-vous sur de vouloir desinstaller les outils? (o/N)"
    if ($confirm -ne "o" -and $confirm -ne "O") {
        Write-Host "Annule." -ForegroundColor Yellow
        exit 0
    }
}

# Tesseract
Write-Info "Recherche de Tesseract..."
$tesseractUninstall = Get-ChildItem "C:\Program Files\Tesseract-OCR\unins*.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($tesseractUninstall) {
    Write-Info "Desinstallation de Tesseract..."
    Start-Process -FilePath $tesseractUninstall.FullName -ArgumentList "/SILENT" -Wait
    Write-Success "Tesseract desinstalle"
} else {
    Write-Info "Tesseract non installe ou deja supprime"
}

# Ghostscript
Write-Info "Recherche de Ghostscript..."
$gsUninstall = Get-ChildItem "C:\Program Files\gs\*\uninstgs.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($gsUninstall) {
    Write-Info "Desinstallation de Ghostscript..."
    Start-Process -FilePath $gsUninstall.FullName -ArgumentList "/S" -Wait
    Write-Success "Ghostscript desinstalle"
} else {
    Write-Info "Ghostscript non installe ou deja supprime"
}

# Poppler (portable)
$popplerPath = "C:\Tools\poppler"
if (Test-Path $popplerPath) {
    Write-Info "Suppression de Poppler..."
    Remove-Item $popplerPath -Recurse -Force
    Write-Success "Poppler supprime"
}

# ImageMagick (portable)
$imPath = "C:\Tools\ImageMagick"
if (Test-Path $imPath) {
    Write-Info "Suppression de ImageMagick..."
    Remove-Item $imPath -Recurse -Force
    Write-Success "ImageMagick supprime"
}

# Nettoyer le PATH
Write-Info "Nettoyage du PATH systeme..."
$currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
$pathsToRemove = @(
    "C:\Program Files\Tesseract-OCR",
    "C:\Program Files\gs",
    "C:\Tools\poppler",
    "C:\Tools\ImageMagick"
)

$newPath = $currentPath
foreach ($p in $pathsToRemove) {
    $newPath = ($newPath -split ";" | Where-Object { $_ -notlike "*$p*" }) -join ";"
}

if ($newPath -ne $currentPath) {
    [Environment]::SetEnvironmentVariable("Path", $newPath, "Machine")
    Write-Success "PATH nettoye"
}

# Téléchargements
$downloadsDir = Join-Path $PSScriptRoot "..\downloads"
if (-not $KeepDownloads -and (Test-Path $downloadsDir)) {
    $confirm = Read-Host "Supprimer les fichiers telecharges? (o/N)"
    if ($confirm -eq "o" -or $confirm -eq "O") {
        Remove-Item "$downloadsDir\*" -Force -ErrorAction SilentlyContinue
        Write-Success "Telechargements supprimes"
    }
}

Write-Host "`n" + "=" * 35
Write-Success "Desinstallation terminee"
Write-Warning "Redemarrez le terminal pour appliquer les changements de PATH"
