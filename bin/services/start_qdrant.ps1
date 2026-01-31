# K-Docs - Start Qdrant Vector Database
Write-Host "=== Starting Qdrant Vector Database ===" -ForegroundColor Cyan

$dockerExe = "C:\Program Files\Docker\Docker\resources\bin\docker.exe"

# Check if Docker is running
try {
    $info = & $dockerExe info 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "[ERROR] Docker is not running. Please start Docker Desktop." -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "[ERROR] Docker not found." -ForegroundColor Red
    exit 1
}

# Create storage directory
$storagePath = "C:\wamp64\www\kdocs\storage\qdrant"
if (-not (Test-Path $storagePath)) {
    New-Item -ItemType Directory -Force -Path $storagePath | Out-Null
    Write-Host "Created storage directory: $storagePath"
}

# Check if container exists
$existing = & $dockerExe ps -a --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>&1
if ($existing -eq "kdocs-qdrant") {
    Write-Host "Container exists, starting..."
    & $dockerExe start kdocs-qdrant
} else {
    Write-Host "Creating new container..."
    & $dockerExe run -d `
        --name kdocs-qdrant `
        --restart unless-stopped `
        -p 6333:6333 `
        -p 6334:6334 `
        -v "${storagePath}:/qdrant/storage" `
        qdrant/qdrant:latest
}

Write-Host "Waiting for Qdrant to start..."
Start-Sleep -Seconds 10

# Check health
try {
    $health = Invoke-RestMethod -Uri "http://localhost:6333/health" -TimeoutSec 5
    Write-Host "[OK] Qdrant is running!" -ForegroundColor Green
    Write-Host "     REST API: http://localhost:6333"
    Write-Host "     Dashboard: http://localhost:6333/dashboard"
} catch {
    Write-Host "[WARN] Qdrant might still be starting..." -ForegroundColor Yellow
}
