# K-Docs - Vérification de l'installation
param(
    [switch]$Detailed
)

function Write-Check {
    param($name, $status, $detail = "")
    $icon = if ($status) { "[OK]" } else { "[X]" }
    $color = if ($status) { "Green" } else { "Red" }
    Write-Host "$icon " -ForegroundColor $color -NoNewline
    Write-Host "$name" -NoNewline
    if ($detail) { Write-Host " - $detail" -ForegroundColor Gray } else { Write-Host "" }
}

function Write-Warning { param($text) Write-Host "    [!] $text" -ForegroundColor Yellow }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }

Write-Host "`nVerification de l'installation K-Docs" -ForegroundColor Cyan
Write-Host "=" * 40

$allOk = $true

# ========== PHP ==========
Write-Host "`nPHP:" -ForegroundColor White
$phpOk = $false
$phpPaths = @(
    "C:\wamp64\bin\php\php8.4.0\php.exe",
    "C:\wamp64\bin\php\php8.3.14\php.exe",
    "C:\wamp64\bin\php\php8.2.0\php.exe",
    "C:\xampp\php\php.exe"
)
foreach ($p in $phpPaths) {
    if (Test-Path $p) {
        $version = & $p -v 2>$null | Select-Object -First 1
        Write-Check "PHP" $true $version
        $phpOk = $true
        break
    }
}
if (-not $phpOk) {
    Write-Check "PHP" $false "Non trouve"
    $allOk = $false
}

# ========== TESSERACT ==========
Write-Host "`nTesseract OCR:" -ForegroundColor White
$tesseractPath = "C:\Program Files\Tesseract-OCR\tesseract.exe"
if (Test-Path $tesseractPath) {
    $version = & $tesseractPath --version 2>&1 | Select-Object -First 1
    Write-Check "Tesseract" $true $version

    # Vérifier les langues
    $langs = & $tesseractPath --list-langs 2>&1
    $hasFra = $langs -match "fra"
    $hasEng = $langs -match "eng"

    Write-Check "  Langue anglaise (eng)" $hasEng
    Write-Check "  Langue francaise (fra)" $hasFra
    if (-not $hasFra) {
        Write-Warning "Installez fra.traineddata pour l'OCR en francais"
        $allOk = $false
    }
} else {
    Write-Check "Tesseract" $false "Non installe"
    $allOk = $false
}

# ========== GHOSTSCRIPT ==========
Write-Host "`nGhostscript:" -ForegroundColor White
$gsPath = Get-ChildItem "C:\Program Files\gs\*\bin\gswin64c.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($gsPath) {
    $version = & $gsPath.FullName --version 2>&1
    Write-Check "Ghostscript" $true "v$version"
} else {
    Write-Check "Ghostscript" $false "Non installe"
    Write-Warning "Requis pour les miniatures PDF"
    $allOk = $false
}

# ========== POPPLER ==========
Write-Host "`nPoppler (pdftotext):" -ForegroundColor White
$pdftotextPath = Get-ChildItem "C:\Tools\poppler" -Filter "pdftotext.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $pdftotextPath) {
    # Chercher dans PATH
    $pdftotextPath = Get-Command pdftotext -ErrorAction SilentlyContinue
}
if ($pdftotextPath) {
    $path = if ($pdftotextPath.FullName) { $pdftotextPath.FullName } else { $pdftotextPath.Source }
    $version = & $path -v 2>&1 | Select-Object -First 1
    Write-Check "pdftotext" $true $version
} else {
    Write-Check "pdftotext" $false "Non installe"
    Write-Warning "Requis pour l'extraction de texte PDF"
    $allOk = $false
}

# ========== LIBREOFFICE (optionnel) ==========
Write-Host "`nLibreOffice (optionnel):" -ForegroundColor White
$loPath = "C:\Program Files\LibreOffice\program\soffice.exe"
if (Test-Path $loPath) {
    Write-Check "LibreOffice" $true "Installe"
    Write-Info "Utilise pour: conversion Office, miniatures DOCX/XLSX"
} else {
    Write-Check "LibreOffice" $false "Non installe (optionnel)"
    Write-Info "Recommande pour de meilleures miniatures Office"
}

# ========== IMAGEMAGICK (optionnel) ==========
Write-Host "`nImageMagick (optionnel):" -ForegroundColor White
$magickPath = Get-ChildItem "C:\Tools\ImageMagick" -Filter "magick.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $magickPath) {
    $magickPath = Get-Command magick -ErrorAction SilentlyContinue
}
if ($magickPath) {
    $path = if ($magickPath.FullName) { $magickPath.FullName } else { $magickPath.Source }
    $version = & $path --version 2>&1 | Select-Object -First 1
    Write-Check "ImageMagick" $true ($version -replace "Version: ", "")
} else {
    Write-Check "ImageMagick" $false "Non installe (optionnel)"
}

# ========== SERVICES DOCKER (optionnel) ==========
Write-Host "`nServices Docker (optionnel):" -ForegroundColor White
$dockerAvailable = $null -ne (Get-Command docker -ErrorAction SilentlyContinue)
if ($dockerAvailable) {
    # OnlyOffice
    $ooRunning = docker ps --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>$null
    if ($ooRunning) {
        Write-Check "OnlyOffice" $true "http://localhost:8080"
    } else {
        $ooExists = docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>$null
        if ($ooExists) {
            Write-Check "OnlyOffice" $false "Arrete (docker start kdocs-onlyoffice)"
        } else {
            Write-Check "OnlyOffice" $false "Non installe"
        }
    }

    # Qdrant
    $qdRunning = docker ps --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>$null
    if ($qdRunning) {
        Write-Check "Qdrant" $true "http://localhost:6333/dashboard"
    } else {
        $qdExists = docker ps -a --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>$null
        if ($qdExists) {
            Write-Check "Qdrant" $false "Arrete (docker start kdocs-qdrant)"
        } else {
            Write-Check "Qdrant" $false "Non installe"
        }
    }
} else {
    Write-Check "Docker" $false "Non installe (requis pour OnlyOffice/Qdrant)"
}

# ========== OLLAMA (optionnel) ==========
Write-Host "`nOllama (optionnel):" -ForegroundColor White
$ollamaAvailable = $null -ne (Get-Command ollama -ErrorAction SilentlyContinue)
if ($ollamaAvailable) {
    $models = ollama list 2>$null
    if ($models -match "nomic-embed-text") {
        Write-Check "Ollama" $true "nomic-embed-text disponible"
    } else {
        Write-Check "Ollama" $true "Installe (modele manquant: ollama pull nomic-embed-text)"
    }
} else {
    Write-Check "Ollama" $false "Non installe (optionnel pour embeddings)"
}

# ========== BASE DE DONNÉES ==========
Write-Host "`nBase de donnees:" -ForegroundColor White
$appRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$envFile = Join-Path $appRoot ".env"
$configFile = Join-Path $appRoot "config\config.php"

if ((Test-Path $envFile) -or (Test-Path $configFile)) {
    Write-Check "Configuration" $true "Fichiers de config presents"
} else {
    Write-Check "Configuration" $false "Fichiers de config manquants"
    $allOk = $false
}

# ========== DOSSIERS ==========
Write-Host "`nDossiers:" -ForegroundColor White
$requiredDirs = @(
    "storage",
    "storage\documents",
    "storage\consume",
    "storage\thumbnails",
    "storage\temp"
)

foreach ($dir in $requiredDirs) {
    $path = Join-Path $appRoot $dir
    $exists = Test-Path $path
    Write-Check $dir $exists
    if (-not $exists) { $allOk = $false }
}

# ========== PERMISSIONS ==========
Write-Host "`nPermissions:" -ForegroundColor White
$storagePath = Join-Path $appRoot "storage"
$testFile = Join-Path $storagePath "test_write_check.tmp"
try {
    "test" | Set-Content $testFile -Encoding UTF8 -ErrorAction Stop
    Remove-Item $testFile -Force
    Write-Check "Ecriture storage/" $true
}
catch {
    Write-Check "Ecriture storage/" $false
    $allOk = $false
}

# ========== RÉSUMÉ ==========
Write-Host "`n" + "=" * 40

if ($allOk) {
    Write-Host "`n[OK] Installation complete!" -ForegroundColor Green
    Write-Host "    Accedez a http://localhost/kdocs" -ForegroundColor Gray
} else {
    Write-Host "`n[!] Installation incomplete" -ForegroundColor Yellow
    Write-Host "    Corrigez les problemes ci-dessus" -ForegroundColor Gray
}

Write-Host ""

# Retourner le statut
exit $(if ($allOk) { 0 } else { 1 })
