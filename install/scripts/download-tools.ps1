# K-Docs - Téléchargement des outils
param(
    [string]$DownloadsDir = "..\downloads",
    [switch]$Force
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }

# Configuration des téléchargements
$Tools = @{
    "libreoffice" = @{
        Name = "LibreOffice 24.8.4"
        Url = "https://download.documentfoundation.org/libreoffice/stable/24.8.4/win/x86_64/LibreOffice_24.8.4_Win_x86-64.msi"
        File = "LibreOffice_24.8.4_Win_x86-64.msi"
        Type = "msi"
    }
    "tesseract" = @{
        Name = "Tesseract OCR 5.4.0"
        Url = "https://github.com/UB-Mannheim/tesseract/releases/download/v5.4.0.20240606/tesseract-ocr-w64-setup-5.4.0.20240606.exe"
        File = "tesseract-ocr-w64-setup-5.4.0.exe"
        Type = "installer"
    }
    "tesseract-fra" = @{
        Name = "Tesseract French Language"
        Url = "https://github.com/tesseract-ocr/tessdata/raw/main/fra.traineddata"
        File = "fra.traineddata"
        Type = "data"
    }
    "tesseract-deu" = @{
        Name = "Tesseract German Language"
        Url = "https://github.com/tesseract-ocr/tessdata/raw/main/deu.traineddata"
        File = "deu.traineddata"
        Type = "data"
    }
    "ghostscript" = @{
        Name = "Ghostscript 10.03.1"
        Url = "https://github.com/ArtifexSoftware/ghostpdl-downloads/releases/download/gs10031/gs10031w64.exe"
        File = "gs10031w64.exe"
        Type = "installer"
    }
    "poppler" = @{
        Name = "Poppler 24.02.0 (pdftotext)"
        Url = "https://github.com/oschwartz10612/poppler-windows/releases/download/v24.02.0-0/Release-24.02.0-0.zip"
        File = "poppler-24.02.0.zip"
        Type = "archive"
    }
    "imagemagick" = @{
        Name = "ImageMagick 7.1.1 (portable)"
        Url = "https://imagemagick.org/archive/binaries/ImageMagick-7.1.1-38-portable-Q16-x64.zip"
        File = "ImageMagick-7.1.1-portable.zip"
        Type = "archive"
    }
}

# Créer le dossier downloads
if (-not (Test-Path $DownloadsDir)) {
    New-Item -ItemType Directory -Path $DownloadsDir -Force | Out-Null
}

# Sauvegarder la configuration
$configPath = Join-Path (Split-Path $DownloadsDir -Parent) "config"
if (-not (Test-Path $configPath)) { New-Item -ItemType Directory -Path $configPath -Force | Out-Null }
$Tools | ConvertTo-Json -Depth 3 | Set-Content (Join-Path $configPath "tools.json") -Encoding UTF8

Write-Host "Telechargement des outils..." -ForegroundColor Cyan

foreach ($key in $Tools.Keys) {
    $tool = $Tools[$key]
    $filePath = Join-Path $DownloadsDir $tool.File

    if ((Test-Path $filePath) -and -not $Force) {
        Write-Success "$($tool.Name) - deja telecharge"
        continue
    }

    Write-Info "Telechargement de $($tool.Name)..."

    try {
        # Utiliser Invoke-WebRequest avec gestion des redirections GitHub
        $webClient = New-Object System.Net.WebClient
        $webClient.Headers.Add("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64)")
        $webClient.DownloadFile($tool.Url, $filePath)

        $size = (Get-Item $filePath).Length / 1MB
        Write-Success "$($tool.Name) - $([math]::Round($size, 2)) MB"
    }
    catch {
        Write-Warning "Echec telechargement $($tool.Name): $_"

        # Essayer avec Invoke-WebRequest comme fallback
        try {
            Invoke-WebRequest -Uri $tool.Url -OutFile $filePath -UseBasicParsing
            Write-Success "$($tool.Name) - telecharge (fallback)"
        }
        catch {
            Write-Warning "Impossible de telecharger $($tool.Name)"
        }
    }
}

# Créer le fichier checksums
Write-Info "Generation des checksums..."
$checksums = @{}
Get-ChildItem $DownloadsDir -File | ForEach-Object {
    $hash = Get-FileHash $_.FullName -Algorithm SHA256
    $checksums[$_.Name] = $hash.Hash
}
$checksums | ConvertTo-Json | Set-Content (Join-Path $DownloadsDir "checksums.json") -Encoding UTF8

Write-Success "Telechargements termines"
Write-Info "Fichiers dans: $DownloadsDir"
