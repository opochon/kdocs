# K-Docs - Installation Ollama (Embeddings locaux)
# Génère des embeddings gratuitement sans clé API

param(
    [switch]$SkipModelDownload
)

$ErrorActionPreference = "Stop"

function Write-Header { param($text) Write-Host "`n=== $text ===" -ForegroundColor Cyan }
function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }
function Write-Error { param($text) Write-Host "[X] $text" -ForegroundColor Red }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }

Write-Header "Installation Ollama pour K-Docs"

# Vérifier si Ollama est déjà installé
$ollamaPath = $null
$searchPaths = @(
    "$env:LOCALAPPDATA\Programs\Ollama\ollama.exe",
    "$env:ProgramFiles\Ollama\ollama.exe",
    "C:\Users\$env:USERNAME\AppData\Local\Programs\Ollama\ollama.exe"
)

foreach ($p in $searchPaths) {
    if (Test-Path $p) { $ollamaPath = $p; break }
}

# Vérifier aussi dans le PATH
if (-not $ollamaPath) {
    try {
        $ollamaPath = (Get-Command ollama -ErrorAction SilentlyContinue).Source
    } catch {}
}

if ($ollamaPath) {
    Write-Success "Ollama deja installe: $ollamaPath"
} else {
    Write-Warning "Ollama non trouve."
    Write-Host ""
    Write-Host "  Installation manuelle requise:" -ForegroundColor White
    Write-Host "  1. Telecharger depuis: https://ollama.ai/download" -ForegroundColor Gray
    Write-Host "  2. Installer (suivre les instructions)" -ForegroundColor Gray
    Write-Host "  3. Relancer ce script" -ForegroundColor Gray
    Write-Host ""

    $download = Read-Host "Ouvrir la page de telechargement? (O/N)"
    if ($download -eq "O" -or $download -eq "o") {
        Start-Process "https://ollama.ai/download"
    }
    exit 1
}

# Vérifier que le service tourne
Write-Info "Verification du service Ollama..."
try {
    $response = Invoke-RestMethod -Uri "http://localhost:11434/api/tags" -TimeoutSec 5 -ErrorAction Stop
    Write-Success "Service Ollama actif"
} catch {
    Write-Warning "Service Ollama non demarre."
    Write-Info "Demarrage d'Ollama..."

    # Essayer de démarrer Ollama
    Start-Process $ollamaPath -ArgumentList "serve" -WindowStyle Hidden
    Start-Sleep -Seconds 3

    try {
        $response = Invoke-RestMethod -Uri "http://localhost:11434/api/tags" -TimeoutSec 5
        Write-Success "Service Ollama demarre"
    } catch {
        Write-Error "Impossible de demarrer Ollama."
        Write-Info "Lancez Ollama manuellement et reessayez."
        exit 1
    }
}

# Lister les modèles installés
Write-Header "Modeles installes"
$models = (Invoke-RestMethod -Uri "http://localhost:11434/api/tags").models

if ($models.Count -eq 0) {
    Write-Info "Aucun modele installe"
} else {
    foreach ($m in $models) {
        $size = [math]::Round($m.size / 1GB, 2)
        Write-Info "$($m.name) (${size}GB)"
    }
}

# Vérifier/installer le modèle d'embedding
Write-Header "Modele d'embedding"

$embedModel = $models | Where-Object { $_.name -like "*nomic-embed*" }

if ($embedModel) {
    Write-Success "nomic-embed-text deja installe"
} elseif (-not $SkipModelDownload) {
    Write-Info "Telechargement de nomic-embed-text (~274MB)..."
    Write-Info "Ce modele genere des embeddings de 768 dimensions"
    Write-Host ""

    # Télécharger via ollama pull
    $process = Start-Process $ollamaPath -ArgumentList "pull nomic-embed-text" -Wait -PassThru -NoNewWindow

    if ($process.ExitCode -eq 0) {
        Write-Success "Modele nomic-embed-text installe"
    } else {
        Write-Error "Erreur lors du telechargement"
        Write-Info "Essayez manuellement: ollama pull nomic-embed-text"
    }
} else {
    Write-Warning "Installation du modele ignoree (-SkipModelDownload)"
    Write-Info "Installez manuellement: ollama pull nomic-embed-text"
}

# Test d'embedding
Write-Header "Test d'embedding"
Write-Info "Generation d'un embedding de test..."

try {
    $testPayload = @{
        model = "nomic-embed-text"
        prompt = "Ceci est un test d'embedding pour K-Docs"
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "http://localhost:11434/api/embeddings" `
        -Method Post -Body $testPayload -ContentType "application/json" -TimeoutSec 30

    if ($response.embedding) {
        $dims = $response.embedding.Count
        Write-Success "Embedding genere: $dims dimensions"
    }
} catch {
    Write-Error "Erreur: $_"
    Write-Info "Verifiez que nomic-embed-text est installe: ollama list"
}

Write-Header "Configuration terminee"
Write-Host ""
Write-Host "  Ollama est pret pour K-Docs!" -ForegroundColor Green
Write-Host ""
Write-Host "  Prochaines etapes:" -ForegroundColor White
Write-Host "    1. Demarrer Qdrant: install\scripts\install-qdrant.ps1" -ForegroundColor Gray
Write-Host "    2. Synchroniser:    php bin\kdocs embeddings:sync --all" -ForegroundColor Gray
Write-Host ""
