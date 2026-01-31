# K-Docs - Installation des outils
param(
    [string]$DownloadsDir = "..\downloads",
    [string]$InstallPath = "C:\Tools",
    [switch]$Force
)

$ErrorActionPreference = "Stop"

function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }
function Write-Error { param($text) Write-Host "[X] $text" -ForegroundColor Red }

# Chemins d'installation
$LibreOfficePath = "C:\Program Files\LibreOffice"
$TesseractPath = "C:\Program Files\Tesseract-OCR"
$GhostscriptPath = "C:\Program Files\gs"
$PopperPath = Join-Path $InstallPath "poppler"
$ImageMagickPath = Join-Path $InstallPath "ImageMagick"

Write-Host "Installation des outils..." -ForegroundColor Cyan

# ========== LIBREOFFICE ==========
$loInstaller = Join-Path $DownloadsDir "LibreOffice_24.8.4_Win_x86-64.msi"

if (Test-Path $loInstaller) {
    if ((Test-Path "$LibreOfficePath\program\soffice.exe") -and -not $Force) {
        Write-Success "LibreOffice - deja installe"
    } else {
        Write-Info "Installation de LibreOffice (peut prendre plusieurs minutes)..."

        # Installation silencieuse MSI
        $msiArgs = "/i `"$loInstaller`" /qn /norestart ALLUSERS=1"
        $process = Start-Process -FilePath "msiexec.exe" -ArgumentList $msiArgs -Wait -PassThru

        if ($process.ExitCode -eq 0 -and (Test-Path "$LibreOfficePath\program\soffice.exe")) {
            Write-Success "LibreOffice installe dans $LibreOfficePath"
        } elseif ($process.ExitCode -eq 3010) {
            Write-Success "LibreOffice installe (redemarrage recommande)"
        } else {
            Write-Warning "Installation LibreOffice peut avoir echoue (code: $($process.ExitCode))"
            Write-Info "Essayez l'installation manuelle: $loInstaller"
        }
    }
} else {
    Write-Warning "LibreOffice - installeur non trouve (optionnel)"
}

# ========== TESSERACT ==========
$tesseractInstaller = Join-Path $DownloadsDir "tesseract-ocr-w64-setup-5.4.0.exe"

if (Test-Path $tesseractInstaller) {
    if ((Test-Path "$TesseractPath\tesseract.exe") -and -not $Force) {
        Write-Success "Tesseract - deja installe"
    } else {
        Write-Info "Installation de Tesseract OCR..."

        # Installation silencieuse
        $process = Start-Process -FilePath $tesseractInstaller -ArgumentList "/S" -Wait -PassThru

        if ($process.ExitCode -eq 0 -and (Test-Path "$TesseractPath\tesseract.exe")) {
            Write-Success "Tesseract installe dans $TesseractPath"
        } else {
            Write-Warning "Installation Tesseract peut avoir echoue (code: $($process.ExitCode))"
        }
    }
}

# ========== TESSERACT LANGUES ==========
$tessdataPath = "$TesseractPath\tessdata"

if (Test-Path $tessdataPath) {
    # Français
    $fraData = Join-Path $DownloadsDir "fra.traineddata"
    $fraDest = Join-Path $tessdataPath "fra.traineddata"

    if (Test-Path $fraData) {
        if ((Test-Path $fraDest) -and -not $Force) {
            Write-Success "Tesseract francais - deja installe"
        } else {
            Copy-Item $fraData $fraDest -Force
            Write-Success "Tesseract francais installe"
        }
    }

    # Allemand
    $deuData = Join-Path $DownloadsDir "deu.traineddata"
    $deuDest = Join-Path $tessdataPath "deu.traineddata"

    if (Test-Path $deuData) {
        if ((Test-Path $deuDest) -and -not $Force) {
            Write-Success "Tesseract allemand - deja installe"
        } else {
            Copy-Item $deuData $deuDest -Force
            Write-Success "Tesseract allemand installe"
        }
    }
} else {
    Write-Warning "Dossier tessdata non trouve - installez Tesseract d'abord"
}

# ========== GHOSTSCRIPT ==========
$gsInstaller = Join-Path $DownloadsDir "gs10031w64.exe"

if (Test-Path $gsInstaller) {
    $gsExe = Get-ChildItem "$GhostscriptPath\*\bin\gswin64c.exe" -ErrorAction SilentlyContinue | Select-Object -First 1

    if ($gsExe -and -not $Force) {
        Write-Success "Ghostscript - deja installe"
    } else {
        Write-Info "Installation de Ghostscript..."

        # Installation silencieuse
        $process = Start-Process -FilePath $gsInstaller -ArgumentList "/S" -Wait -PassThru

        $gsExe = Get-ChildItem "$GhostscriptPath\*\bin\gswin64c.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($gsExe) {
            Write-Success "Ghostscript installe: $($gsExe.FullName)"
        } else {
            Write-Warning "Installation Ghostscript peut avoir echoue"
        }
    }
}

# ========== POPPLER ==========
$popperZip = Join-Path $DownloadsDir "poppler-24.02.0.zip"

if (Test-Path $popperZip) {
    if ((Test-Path "$PopperPath\Library\bin\pdftotext.exe") -and -not $Force) {
        Write-Success "Poppler - deja installe"
    } else {
        Write-Info "Extraction de Poppler..."

        # Créer le dossier
        if (-not (Test-Path $PopperPath)) {
            New-Item -ItemType Directory -Path $PopperPath -Force | Out-Null
        }

        # Extraire
        Expand-Archive -Path $popperZip -DestinationPath $PopperPath -Force

        # Vérifier structure (peut être dans un sous-dossier)
        $pdftotext = Get-ChildItem $PopperPath -Filter "pdftotext.exe" -Recurse | Select-Object -First 1
        if ($pdftotext) {
            Write-Success "Poppler installe: $($pdftotext.DirectoryName)"
        } else {
            Write-Warning "pdftotext non trouve apres extraction"
        }
    }
}

# ========== IMAGEMAGICK ==========
$imZip = Join-Path $DownloadsDir "ImageMagick-7.1.1-portable.zip"

if (Test-Path $imZip) {
    if ((Test-Path "$ImageMagickPath\magick.exe") -and -not $Force) {
        Write-Success "ImageMagick - deja installe"
    } else {
        Write-Info "Extraction de ImageMagick..."

        # Créer le dossier
        if (-not (Test-Path $ImageMagickPath)) {
            New-Item -ItemType Directory -Path $ImageMagickPath -Force | Out-Null
        }

        # Extraire
        Expand-Archive -Path $imZip -DestinationPath $ImageMagickPath -Force

        # Vérifier
        $magick = Get-ChildItem $ImageMagickPath -Filter "magick.exe" -Recurse | Select-Object -First 1
        if ($magick) {
            Write-Success "ImageMagick installe: $($magick.DirectoryName)"
        } else {
            Write-Warning "magick.exe non trouve apres extraction"
        }
    }
}

# ========== CONFIGURATION PATH ==========
Write-Info "Configuration du PATH systeme..."

$pathsToAdd = @()

# Tesseract
if (Test-Path "$TesseractPath\tesseract.exe") {
    $pathsToAdd += $TesseractPath
}

# Ghostscript
$gsExe = Get-ChildItem "$GhostscriptPath\*\bin\gswin64c.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($gsExe) {
    $pathsToAdd += $gsExe.DirectoryName
}

# Poppler
$pdftotext = Get-ChildItem $PopperPath -Filter "pdftotext.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if ($pdftotext) {
    $pathsToAdd += $pdftotext.DirectoryName
}

# ImageMagick
$magick = Get-ChildItem $ImageMagickPath -Filter "magick.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if ($magick) {
    $pathsToAdd += $magick.DirectoryName
}

# Mettre à jour le PATH
$currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
$updated = $false

foreach ($p in $pathsToAdd) {
    if ($currentPath -notlike "*$p*") {
        $currentPath = "$currentPath;$p"
        $updated = $true
        Write-Info "Ajoute au PATH: $p"
    }
}

if ($updated) {
    [Environment]::SetEnvironmentVariable("Path", $currentPath, "Machine")
    Write-Success "PATH systeme mis a jour"
    Write-Warning "Redemarrer le terminal pour appliquer les changements de PATH"
} else {
    Write-Success "PATH deja configure"
}

# ========== SAUVEGARDER LA CONFIGURATION ==========
$loExe = if (Test-Path "$LibreOfficePath\program\soffice.exe") { "$LibreOfficePath\program\soffice.exe" } else { $null }

$installConfig = @{
    installed_at = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    libreoffice = @{
        path = $loExe
    }
    tesseract = @{
        path = "$TesseractPath\tesseract.exe"
        tessdata = "$TesseractPath\tessdata"
    }
    ghostscript = @{
        path = if ($gsExe) { $gsExe.FullName } else { $null }
    }
    poppler = @{
        pdftotext = if ($pdftotext) { $pdftotext.FullName } else { $null }
        pdftoppm = if ($pdftotext) { Join-Path $pdftotext.DirectoryName "pdftoppm.exe" } else { $null }
    }
    imagemagick = @{
        path = if ($magick) { $magick.FullName } else { $null }
    }
}

$configDir = Join-Path (Split-Path $DownloadsDir -Parent) "config"
if (-not (Test-Path $configDir)) { New-Item -ItemType Directory -Path $configDir -Force | Out-Null }
$installConfig | ConvertTo-Json -Depth 3 | Set-Content (Join-Path $configDir "installed.json") -Encoding UTF8

Write-Success "Installation des outils terminee"
