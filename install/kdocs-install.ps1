#Requires -RunAsAdministrator
<#
.SYNOPSIS
    K-Docs - Script d'installation complet Windows

.DESCRIPTION
    Installe tous les composants nécessaires pour K-Docs:
    - Outils: Tesseract, Ghostscript, Poppler, LibreOffice, ImageMagick
    - Services: OnlyOffice (Docker), Qdrant (Docker), Ollama
    - Base de données: MySQL/MariaDB
    - Configuration: config.php, PATH système

.PARAMETER Components
    Composants à installer: All, Tools, Database, OnlyOffice, Qdrant, Ollama
    Default: All

.PARAMETER SkipDownload
    Ne pas télécharger les fichiers (utiliser ceux existants)

.PARAMETER Force
    Réinstaller même si déjà présent

.PARAMETER InstallPath
    Chemin d'installation pour les outils portables (défaut: C:\Tools)

.EXAMPLE
    .\kdocs-install.ps1
    Installation complète

.EXAMPLE
    .\kdocs-install.ps1 -Components Tools
    Installer uniquement les outils

.EXAMPLE
    .\kdocs-install.ps1 -Components OnlyOffice,Qdrant
    Installer OnlyOffice et Qdrant
#>

param(
    [ValidateSet("All", "Tools", "Database", "OnlyOffice", "Qdrant", "Ollama", "Verify")]
    [string[]]$Components = @("All"),
    [switch]$SkipDownload,
    [switch]$Force,
    [string]$InstallPath = "C:\Tools"
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

# ============================================================
# FONCTIONS UTILITAIRES
# ============================================================

function Write-Banner {
    Write-Host @"

  _  __    ____
 | |/ /   |  _ \  ___   ___ ___
 | ' / ___| | | |/ _ \ / __/ __|
 | . \___| |_| | (_) | (__\__ \
 |_|\_\  |____/ \___/ \___|___/

  Installation Windows - v2.0

"@ -ForegroundColor Cyan
}

function Write-Header { param($text) Write-Host "`n=== $text ===" -ForegroundColor Cyan }
function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }
function Write-Err { param($text) Write-Host "[X] $text" -ForegroundColor Red }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }
function Write-Step { param($num, $text) Write-Host "`n[$num] $text" -ForegroundColor White }

function Test-Command {
    param([string]$Command)
    $null = Get-Command $Command -ErrorAction SilentlyContinue
    return $?
}

function Test-DockerRunning {
    try {
        $null = docker info 2>$null
        return $LASTEXITCODE -eq 0
    } catch {
        return $false
    }
}

# ============================================================
# VARIABLES GLOBALES
# ============================================================

$ScriptRoot = $PSScriptRoot
$DownloadsDir = Join-Path $ScriptRoot "downloads"
$ConfigDir = Join-Path $ScriptRoot "config"
$ScriptsDir = Join-Path $ScriptRoot "scripts"
$KDocsRoot = Split-Path $ScriptRoot -Parent

# Créer les dossiers
@($DownloadsDir, $ConfigDir, $InstallPath) | ForEach-Object {
    if (-not (Test-Path $_)) { New-Item -ItemType Directory -Path $_ -Force | Out-Null }
}

# ============================================================
# MAIN
# ============================================================

Write-Banner

$installAll = $Components -contains "All"

# ============================================================
# 1. PRÉREQUIS
# ============================================================
Write-Header "Verification des prerequis"

# PHP
$phpPath = @(
    "C:\wamp64\bin\php\php8.4.0\php.exe",
    "C:\wamp64\bin\php\php8.3.14\php.exe",
    "C:\wamp64\bin\php\php8.2.0\php.exe",
    "C:\xampp\php\php.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($phpPath) {
    $phpVersion = & $phpPath -v 2>$null | Select-Object -First 1
    Write-Success "PHP: $phpVersion"
} else {
    Write-Err "PHP non trouve - Installez WAMP64 ou XAMPP"
    exit 1
}

# MySQL
$mysqlPath = @(
    "C:\wamp64\bin\mariadb\*\bin\mysql.exe",
    "C:\wamp64\bin\mysql\*\bin\mysql.exe",
    "C:\xampp\mysql\bin\mysql.exe"
) | ForEach-Object { Get-Item $_ -ErrorAction SilentlyContinue } | Select-Object -First 1

if ($mysqlPath) {
    Write-Success "MySQL/MariaDB: $($mysqlPath.FullName)"
} else {
    Write-Warning "MySQL non trouve - configuration manuelle requise"
}

# Docker
$dockerAvailable = Test-Command "docker"
if ($dockerAvailable) {
    if (Test-DockerRunning) {
        Write-Success "Docker: disponible et actif"
    } else {
        Write-Warning "Docker installe mais non demarre"
    }
} else {
    Write-Warning "Docker non installe (requis pour OnlyOffice/Qdrant)"
}

# ============================================================
# 2. OUTILS (Tesseract, Ghostscript, Poppler, LibreOffice)
# ============================================================
if ($installAll -or $Components -contains "Tools") {
    Write-Header "Installation des outils"

    if (-not $SkipDownload) {
        Write-Step 1 "Telechargement des outils"
        & "$ScriptsDir\download-tools.ps1" -DownloadsDir $DownloadsDir -Force:$Force
    }

    Write-Step 2 "Installation des outils"
    & "$ScriptsDir\install-tools.ps1" -DownloadsDir $DownloadsDir -InstallPath $InstallPath -Force:$Force
}

# ============================================================
# 3. BASE DE DONNÉES
# ============================================================
if ($installAll -or $Components -contains "Database") {
    Write-Header "Configuration base de donnees"

    if ($mysqlPath) {
        & "$ScriptsDir\setup-database.ps1" -MySqlPath $mysqlPath.FullName
    } else {
        Write-Warning "MySQL non disponible - configuration manuelle requise"
        Write-Info "Creez la base 'kdocs' et importez database/schema.sql"
    }
}

# ============================================================
# 4. ONLYOFFICE (Docker)
# ============================================================
if ($installAll -or $Components -contains "OnlyOffice") {
    Write-Header "Installation OnlyOffice"

    if (-not $dockerAvailable) {
        Write-Warning "Docker requis pour OnlyOffice"
        Write-Info "Installez Docker Desktop: https://www.docker.com/products/docker-desktop"
    } elseif (-not (Test-DockerRunning)) {
        Write-Warning "Demarrez Docker Desktop puis relancez ce script"
    } else {
        # Vérifier si déjà installé
        $existing = docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>$null

        if ($existing -and -not $Force) {
            Write-Success "OnlyOffice - deja installe"
            Write-Info "Demarrer: docker start kdocs-onlyoffice"
        } else {
            Write-Info "Telechargement et demarrage OnlyOffice (peut prendre plusieurs minutes)..."

            # Supprimer l'ancien si Force
            if ($existing) {
                docker rm -f kdocs-onlyoffice 2>$null | Out-Null
            }

            # Créer le dossier de données
            $onlyOfficeData = Join-Path $KDocsRoot "storage\onlyoffice"
            if (-not (Test-Path $onlyOfficeData)) {
                New-Item -ItemType Directory -Path $onlyOfficeData -Force | Out-Null
            }

            # Lancer le conteneur
            docker run -d `
                --name kdocs-onlyoffice `
                --restart unless-stopped `
                -p 8080:80 `
                -v "${onlyOfficeData}:/var/www/onlyoffice/Data" `
                onlyoffice/documentserver:latest 2>&1 | Out-Null

            if ($LASTEXITCODE -eq 0) {
                Write-Success "OnlyOffice demarre sur http://localhost:8080"
                Write-Info "Attendre 30-60 secondes pour le demarrage complet"
            } else {
                Write-Err "Echec du demarrage OnlyOffice"
            }
        }
    }
}

# ============================================================
# 5. QDRANT (Docker)
# ============================================================
if ($installAll -or $Components -contains "Qdrant") {
    Write-Header "Installation Qdrant (recherche semantique)"

    if (-not $dockerAvailable) {
        Write-Warning "Docker requis pour Qdrant"
    } elseif (-not (Test-DockerRunning)) {
        Write-Warning "Demarrez Docker Desktop puis relancez ce script"
    } else {
        $existing = docker ps -a --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>$null

        if ($existing -and -not $Force) {
            Write-Success "Qdrant - deja installe"
            Write-Info "Dashboard: http://localhost:6333/dashboard"
        } else {
            Write-Info "Telechargement et demarrage Qdrant..."

            if ($existing) {
                docker rm -f kdocs-qdrant 2>$null | Out-Null
            }

            $qdrantData = Join-Path $KDocsRoot "storage\qdrant"
            if (-not (Test-Path $qdrantData)) {
                New-Item -ItemType Directory -Path $qdrantData -Force | Out-Null
            }

            docker run -d `
                --name kdocs-qdrant `
                --restart unless-stopped `
                -p 6333:6333 `
                -p 6334:6334 `
                -v "${qdrantData}:/qdrant/storage" `
                qdrant/qdrant:latest 2>&1 | Out-Null

            if ($LASTEXITCODE -eq 0) {
                Write-Success "Qdrant demarre"
                Write-Info "API: http://localhost:6333"
                Write-Info "Dashboard: http://localhost:6333/dashboard"
            } else {
                Write-Err "Echec du demarrage Qdrant"
            }
        }
    }
}

# ============================================================
# 6. OLLAMA (Embeddings locaux)
# ============================================================
if ($installAll -or $Components -contains "Ollama") {
    Write-Header "Installation Ollama (embeddings locaux)"

    if (Test-Command "ollama") {
        Write-Success "Ollama - deja installe"

        # Vérifier le modèle
        $models = ollama list 2>$null
        if ($models -match "nomic-embed-text") {
            Write-Success "Modele nomic-embed-text disponible"
        } else {
            Write-Info "Telechargement du modele nomic-embed-text..."
            ollama pull nomic-embed-text 2>&1 | Out-Null
            Write-Success "Modele telecharge"
        }
    } else {
        Write-Warning "Ollama non installe"
        Write-Info "Telecharger depuis: https://ollama.ai/download"
        Write-Info "Puis executer: ollama pull nomic-embed-text"
    }
}

# ============================================================
# 7. CONFIGURATION APPLICATION
# ============================================================
if ($installAll -or $Components -contains "Tools" -or $Components -contains "Database") {
    Write-Header "Configuration de l'application"
    & "$ScriptsDir\configure-app.ps1" -InstallPath $InstallPath
}

# ============================================================
# 8. VÉRIFICATION
# ============================================================
if ($installAll -or $Components -contains "Verify") {
    Write-Header "Verification de l'installation"
    & "$ScriptsDir\verify-install.ps1"
}

# ============================================================
# RÉSUMÉ
# ============================================================
Write-Header "Resume de l'installation"

$status = @()

# Outils
$tools = @{
    "Tesseract" = Test-Path "C:\Program Files\Tesseract-OCR\tesseract.exe"
    "Ghostscript" = (Get-ChildItem "C:\Program Files\gs\*\bin\gswin64c.exe" -ErrorAction SilentlyContinue) -ne $null
    "Poppler" = Test-Path "$InstallPath\poppler\*\bin\pdftotext.exe" -ErrorAction SilentlyContinue
    "LibreOffice" = Test-Path "C:\Program Files\LibreOffice\program\soffice.exe"
    "ImageMagick" = (Get-ChildItem "$InstallPath\ImageMagick" -Filter "magick.exe" -Recurse -ErrorAction SilentlyContinue) -ne $null
}

foreach ($tool in $tools.Keys) {
    $icon = if ($tools[$tool]) { "[OK]" } else { "[--]" }
    $color = if ($tools[$tool]) { "Green" } else { "Gray" }
    Write-Host "  $icon $tool" -ForegroundColor $color
}

# Services Docker
if ($dockerAvailable -and (Test-DockerRunning)) {
    Write-Host ""
    $containers = @("kdocs-onlyoffice", "kdocs-qdrant")
    foreach ($c in $containers) {
        $running = docker ps --filter "name=$c" --format "{{.Names}}" 2>$null
        $icon = if ($running) { "[OK]" } else { "[--]" }
        $color = if ($running) { "Green" } else { "Gray" }
        Write-Host "  $icon $c" -ForegroundColor $color
    }
}

# Message final
Write-Host @"

Installation terminee!

Prochaines etapes:
  1. Demarrer WAMP/XAMPP
  2. Acceder a http://localhost/kdocs
  3. Se connecter avec root / admin123
  4. IMPORTANT: Changer le mot de passe!

Documentation: $KDocsRoot\docs
Support: $KDocsRoot\install\README.md

"@ -ForegroundColor White
