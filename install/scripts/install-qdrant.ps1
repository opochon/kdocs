# K-Docs - Installation Qdrant Vector Database
# Requires Docker Desktop

param(
    [switch]$Force,
    [string]$StoragePath = "C:\wamp64\www\kdocs\storage\qdrant"
)

$ErrorActionPreference = "Stop"

function Write-Header { param($text) Write-Host "`n=== $text ===" -ForegroundColor Cyan }
function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }
function Write-Error { param($text) Write-Host "[X] $text" -ForegroundColor Red }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }

Write-Header "Installation Qdrant Vector Database"

# Trouver Docker
$dockerExe = $null
$dockerPaths = @(
    "C:\Program Files\Docker\Docker\resources\bin\docker.exe",
    "$env:ProgramFiles\Docker\Docker\resources\bin\docker.exe"
)
foreach ($p in $dockerPaths) {
    if (Test-Path $p) { $dockerExe = $p; break }
}

# Sinon essayer dans le PATH
if (-not $dockerExe) {
    try {
        $dockerExe = (Get-Command docker -ErrorAction SilentlyContinue).Source
    } catch {}
}

if (-not $dockerExe) {
    Write-Error "Docker non trouve!"
    Write-Info "Installez Docker Desktop: https://www.docker.com/products/docker-desktop"
    exit 1
}

Write-Success "Docker trouve: $dockerExe"

# Vérifier que Docker tourne
Write-Info "Verification que Docker Desktop est demarre..."
try {
    $info = & $dockerExe info 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Docker Desktop n'est pas demarre."
        Write-Info "Lancez Docker Desktop et reessayez."
        exit 1
    }
} catch {
    Write-Error "Impossible de contacter Docker: $_"
    exit 1
}
Write-Success "Docker Desktop est actif"

# Créer le dossier de stockage
Write-Info "Creation du dossier de stockage..."
if (-not (Test-Path $StoragePath)) {
    New-Item -ItemType Directory -Path $StoragePath -Force | Out-Null
}
Write-Success "Dossier: $StoragePath"

# Vérifier si le conteneur existe déjà
$existing = & $dockerExe ps -a --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>&1
if ($existing -eq "kdocs-qdrant" -and -not $Force) {
    Write-Warning "Le conteneur kdocs-qdrant existe deja."

    # Vérifier s'il tourne
    $running = & $dockerExe ps --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>&1
    if ($running -eq "kdocs-qdrant") {
        Write-Success "Qdrant est deja en cours d'execution!"
    } else {
        Write-Info "Demarrage du conteneur existant..."
        & $dockerExe start kdocs-qdrant
        Write-Success "Conteneur demarre"
    }
} else {
    # Supprimer l'ancien si Force
    if ($Force -and $existing -eq "kdocs-qdrant") {
        Write-Info "Suppression de l'ancien conteneur..."
        & $dockerExe rm -f kdocs-qdrant | Out-Null
    }

    # Télécharger l'image
    Write-Info "Telechargement de l'image Qdrant (premiere fois peut prendre quelques minutes)..."
    & $dockerExe pull qdrant/qdrant:latest
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Impossible de telecharger l'image Qdrant."
        Write-Info "Verifiez votre connexion internet et les credentials Docker."
        exit 1
    }
    Write-Success "Image telechargee"

    # Créer le conteneur
    Write-Info "Creation du conteneur..."
    & $dockerExe run -d `
        --name kdocs-qdrant `
        --restart unless-stopped `
        -p 6333:6333 `
        -p 6334:6334 `
        -v "${StoragePath}:/qdrant/storage" `
        qdrant/qdrant:latest

    if ($LASTEXITCODE -ne 0) {
        Write-Error "Impossible de creer le conteneur."
        exit 1
    }
    Write-Success "Conteneur cree et demarre"
}

# Attendre le démarrage
Write-Info "Attente du demarrage de Qdrant..."
Start-Sleep -Seconds 5

# Vérifier la santé
$maxAttempts = 10
$attempt = 0
$healthy = $false

while ($attempt -lt $maxAttempts -and -not $healthy) {
    $attempt++
    try {
        $health = Invoke-RestMethod -Uri "http://localhost:6333/health" -TimeoutSec 3 -ErrorAction SilentlyContinue
        $healthy = $true
    } catch {
        Write-Info "Tentative $attempt/$maxAttempts..."
        Start-Sleep -Seconds 2
    }
}

Write-Host ""
if ($healthy) {
    Write-Success "Qdrant est operationnel!"
    Write-Host ""
    Write-Host "  URLs:" -ForegroundColor White
    Write-Host "    REST API:   http://localhost:6333" -ForegroundColor Gray
    Write-Host "    gRPC:       localhost:6334" -ForegroundColor Gray
    Write-Host "    Dashboard:  http://localhost:6333/dashboard" -ForegroundColor Gray
    Write-Host ""
    Write-Host "  Commandes Docker:" -ForegroundColor White
    Write-Host "    Demarrer:   docker start kdocs-qdrant" -ForegroundColor Gray
    Write-Host "    Arreter:    docker stop kdocs-qdrant" -ForegroundColor Gray
    Write-Host "    Logs:       docker logs kdocs-qdrant" -ForegroundColor Gray
    Write-Host "    Supprimer:  docker rm -f kdocs-qdrant" -ForegroundColor Gray
    Write-Host ""
    Write-Host "  Prochaine etape:" -ForegroundColor White
    Write-Host "    php bin\kdocs embeddings:sync --all" -ForegroundColor Yellow
} else {
    Write-Warning "Qdrant demarre en arriere-plan."
    Write-Info "Attendez quelques secondes puis testez:"
    Write-Info "  curl http://localhost:6333/health"
}
