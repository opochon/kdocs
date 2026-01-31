# K-Docs - Configuration de l'application
param(
    [string]$InstallPath = "C:\Tools"
)

function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }

Write-Host "Configuration de l'application..." -ForegroundColor Cyan

$appRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$configFile = Join-Path $appRoot "config\config.php"
$configLocalFile = Join-Path $appRoot "config\config.local.php"
$envFile = Join-Path $appRoot ".env"

# Détecter les chemins des outils installés
$tesseractPath = "C:\Program Files\Tesseract-OCR\tesseract.exe"
$libreOfficePath = "C:\Program Files\LibreOffice\program\soffice.exe"
$ghostscriptPath = $null
$pdftotextPath = $null
$pdftoppmPath = $null
$imagemagickPath = $null

# LibreOffice
if (-not (Test-Path $libreOfficePath)) { $libreOfficePath = $null }

# Ghostscript
$gsExe = Get-ChildItem "C:\Program Files\gs\*\bin\gswin64c.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
if ($gsExe) { $ghostscriptPath = $gsExe.FullName }

# Poppler
$pdftotext = Get-ChildItem "$InstallPath\poppler" -Filter "pdftotext.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if ($pdftotext) {
    $pdftotextPath = $pdftotext.FullName
    $pdftoppmPath = Join-Path $pdftotext.DirectoryName "pdftoppm.exe"
}

# ImageMagick
$magick = Get-ChildItem "$InstallPath\ImageMagick" -Filter "magick.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if ($magick) { $imagemagickPath = $magick.FullName }

Write-Info "Chemins detectes:"
Write-Info "  Tesseract: $(if ($tesseractPath -and (Test-Path $tesseractPath)) { $tesseractPath } else { 'Non trouve' })"
Write-Info "  LibreOffice: $(if ($libreOfficePath) { $libreOfficePath } else { 'Non trouve (optionnel)' })"
Write-Info "  Ghostscript: $(if ($ghostscriptPath) { $ghostscriptPath } else { 'Non trouve' })"
Write-Info "  pdftotext: $(if ($pdftotextPath) { $pdftotextPath } else { 'Non trouve' })"
Write-Info "  ImageMagick: $(if ($imagemagickPath) { $imagemagickPath } else { 'Non trouve (optionnel)' })"

# Créer config.local.php avec les chemins des outils
$configLocalContent = @"
<?php
/**
 * K-Docs - Configuration locale (généré par l'installateur)
 * Ce fichier est ignoré par Git et contient les paramètres spécifiques à cette installation
 *
 * Généré le: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")
 */

return [
    // Outils externes
    'tools' => [
        'ghostscript' => $(if ($ghostscriptPath) { "'$($ghostscriptPath.Replace('\', '\\'))'" } else { "null" }),
        'pdftotext' => $(if ($pdftotextPath) { "'$($pdftotextPath.Replace('\', '\\'))'" } else { "null" }),
        'pdftoppm' => $(if ($pdftoppmPath) { "'$($pdftoppmPath.Replace('\', '\\'))'" } else { "null" }),
        'imagemagick' => $(if ($imagemagickPath) { "'$($imagemagickPath.Replace('\', '\\'))'" } else { "null" }),
    ],

    // OCR
    'ocr' => [
        'tesseract_path' => $(if ($tesseractPath -and (Test-Path $tesseractPath)) { "'$($tesseractPath.Replace('\', '\\'))'" } else { "'tesseract'" }),
    ],

    // Base de données (décommenter et modifier si nécessaire)
    // 'database' => [
    //     'host' => 'localhost',
    //     'name' => 'kdocs',
    //     'user' => 'kdocs',
    //     'password' => 'kdocs_password',
    // ],

    // API Claude (décommenter et configurer)
    // 'claude' => [
    //     'api_key' => 'sk-ant-...',
    //     'model' => 'claude-sonnet-4-20250514',
    // ],
];
"@

# Sauvegarder config.local.php
$configLocalContent | Set-Content $configLocalFile -Encoding UTF8
Write-Success "Configuration locale creee: $configLocalFile"

# Vérifier/créer les dossiers de stockage
$storageDirs = @(
    "storage",
    "storage\documents",
    "storage\consume",
    "storage\thumbnails",
    "storage\temp",
    "storage\logs",
    "storage\crawl_queue"
)

foreach ($dir in $storageDirs) {
    $fullPath = Join-Path $appRoot $dir
    if (-not (Test-Path $fullPath)) {
        New-Item -ItemType Directory -Path $fullPath -Force | Out-Null
        Write-Info "Cree: $dir"
    }
}
Write-Success "Dossiers de stockage verifies"

# Créer .htaccess pour storage si pas présent
$htaccessPath = Join-Path $appRoot "storage\.htaccess"
if (-not (Test-Path $htaccessPath)) {
    "Deny from all" | Set-Content $htaccessPath -Encoding UTF8
    Write-Info "Protection .htaccess creee pour storage/"
}

# Vérifier les permissions
Write-Info "Verification des permissions..."
$testFile = Join-Path $appRoot "storage\test_write.tmp"
try {
    "test" | Set-Content $testFile -Encoding UTF8
    Remove-Item $testFile -Force
    Write-Success "Permissions d'ecriture OK"
}
catch {
    Write-Warning "Probleme de permissions sur storage/"
    Write-Info "Verifiez que le serveur web peut ecrire dans ce dossier"
}

# Générer une clé d'application si .env n'existe pas
if (-not (Test-Path $envFile)) {
    $appKey = -join ((65..90) + (97..122) + (48..57) | Get-Random -Count 32 | ForEach-Object { [char]$_ })

    $envContent = @"
# K-Docs Environment Configuration
# Généré le $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")

APP_ENV=production
APP_DEBUG=false
APP_KEY=$appKey

DB_HOST=localhost
DB_NAME=kdocs
DB_USER=root
DB_PASSWORD=

# Claude API (optionnel)
# CLAUDE_API_KEY=sk-ant-...
"@

    $envContent | Set-Content $envFile -Encoding UTF8
    Write-Success "Fichier .env cree"
} else {
    Write-Info "Fichier .env existe deja"
}

Write-Success "Configuration application terminee"
