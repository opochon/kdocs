# K-Docs OnlyOffice Starter Script
# Execute: .\start-onlyoffice.ps1

Write-Host "=== K-Docs OnlyOffice Docker Starter ===" -ForegroundColor Cyan
Write-Host ""

# Check Docker is running
$dockerProcess = Get-Process -Name "Docker Desktop" -ErrorAction SilentlyContinue
if (-not $dockerProcess) {
    Write-Host "[!] Docker Desktop n'est pas lance." -ForegroundColor Red
    Write-Host "    Lancez Docker Desktop puis reessayez." -ForegroundColor Yellow
    exit 1
}

# Check if container exists
$container = docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>$null
if ($container) {
    # Container exists, check if running
    $running = docker ps --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>$null
    if ($running) {
        Write-Host "[OK] Container kdocs-onlyoffice deja en cours d'execution" -ForegroundColor Green
    } else {
        Write-Host "[...] Demarrage du container existant..." -ForegroundColor Yellow
        docker start kdocs-onlyoffice
        Write-Host "[OK] Container demarre" -ForegroundColor Green
    }
} else {
    # Create new container
    Write-Host "[...] Creation du container OnlyOffice..." -ForegroundColor Yellow
    Set-Location $PSScriptRoot
    docker-compose up -d
    Write-Host "[OK] Container cree et demarre" -ForegroundColor Green
}

Write-Host ""
Write-Host "En attente du demarrage d'OnlyOffice (peut prendre 2-3 minutes)..." -ForegroundColor Yellow

# Wait for healthcheck
$maxAttempts = 30
$attempt = 0
$ready = $false

while (-not $ready -and $attempt -lt $maxAttempts) {
    $attempt++
    Start-Sleep -Seconds 5

    try {
        $response = Invoke-WebRequest -Uri "http://localhost:8080/healthcheck" -TimeoutSec 3 -UseBasicParsing -ErrorAction SilentlyContinue
        if ($response.Content -match "true") {
            $ready = $true
        }
    } catch {
        Write-Host "  Tentative $attempt/$maxAttempts - En cours d'initialisation..." -ForegroundColor Gray
    }
}

Write-Host ""
if ($ready) {
    Write-Host "========================================" -ForegroundColor Green
    Write-Host " OnlyOffice est pret!" -ForegroundColor Green
    Write-Host " URL: http://localhost:8080" -ForegroundColor Green
    Write-Host " Health: http://localhost:8080/healthcheck" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
} else {
    Write-Host "[!] OnlyOffice n'a pas demarre dans le temps imparti" -ForegroundColor Red
    Write-Host "    Verifiez les logs: docker logs kdocs-onlyoffice" -ForegroundColor Yellow
}
