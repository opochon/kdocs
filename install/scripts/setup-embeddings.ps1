# K-Docs - Configuration des Embeddings
# Configure OpenAI API key ou Ollama pour la recherche sémantique

param(
    [string]$Provider = "openai",  # "openai" ou "ollama"
    [string]$ApiKey,
    [switch]$TestOnly
)

$ErrorActionPreference = "Stop"

function Write-Header { param($text) Write-Host "`n=== $text ===" -ForegroundColor Cyan }
function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }
function Write-Error { param($text) Write-Host "[X] $text" -ForegroundColor Red }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }

$kdocsRoot = "C:\wamp64\www\kdocs"
$configFile = Join-Path $kdocsRoot "config\config.php"

Write-Header "Configuration Embeddings K-Docs"

# Vérifier Qdrant
Write-Info "Verification de Qdrant..."
try {
    $health = Invoke-RestMethod -Uri "http://localhost:6333/health" -TimeoutSec 3 -ErrorAction SilentlyContinue
    Write-Success "Qdrant disponible"
} catch {
    Write-Warning "Qdrant non disponible - lancez d'abord: install\scripts\install-qdrant.ps1"
}

if ($Provider -eq "openai") {
    Write-Header "Configuration OpenAI"

    # Chercher la clé API
    if (-not $ApiKey) {
        # Vérifier la variable d'environnement
        $ApiKey = $env:OPENAI_API_KEY

        # Vérifier le fichier
        $keyFile = Join-Path $kdocsRoot "openai_api_key.txt"
        if (-not $ApiKey -and (Test-Path $keyFile)) {
            $ApiKey = (Get-Content $keyFile -Raw).Trim()
        }

        # Demander à l'utilisateur
        if (-not $ApiKey) {
            Write-Info "Cle API OpenAI non trouvee."
            Write-Info "Obtenez une cle sur: https://platform.openai.com/api-keys"
            Write-Host ""
            $ApiKey = Read-Host "Entrez votre cle API OpenAI (sk-...)"
        }
    }

    if (-not $ApiKey -or -not $ApiKey.StartsWith("sk-")) {
        Write-Error "Cle API OpenAI invalide (doit commencer par sk-)"
        exit 1
    }

    Write-Success "Cle API: $($ApiKey.Substring(0, 10))...****"

    # Sauvegarder la clé
    $keyFile = Join-Path $kdocsRoot "openai_api_key.txt"
    Set-Content -Path $keyFile -Value $ApiKey -NoNewline
    Write-Success "Cle sauvegardee dans openai_api_key.txt"

    # Tester la clé
    if (-not $TestOnly) {
        Write-Info "Test de la cle API..."
        try {
            $testPayload = @{
                model = "text-embedding-3-small"
                input = "test"
            } | ConvertTo-Json

            $headers = @{
                "Authorization" = "Bearer $ApiKey"
                "Content-Type" = "application/json"
            }

            $response = Invoke-RestMethod -Uri "https://api.openai.com/v1/embeddings" `
                -Method Post -Body $testPayload -Headers $headers -TimeoutSec 30

            if ($response.data) {
                Write-Success "Cle API valide! Embedding genere avec succes."
                Write-Info "Dimensions: $($response.data[0].embedding.Count)"
            }
        } catch {
            Write-Error "Cle API invalide ou erreur: $_"
            exit 1
        }
    }

    # Mettre à jour la config
    Write-Info "Mise a jour de config.php..."
    Write-Warning "Ajoutez cette ligne dans config.php > 'embeddings' > 'api_key':"
    Write-Host ""
    Write-Host "    'api_key' => file_get_contents(__DIR__ . '/../openai_api_key.txt')," -ForegroundColor Yellow
    Write-Host ""

} elseif ($Provider -eq "ollama") {
    Write-Header "Configuration Ollama (Local)"

    # Vérifier Ollama
    Write-Info "Verification d'Ollama..."
    try {
        $tags = Invoke-RestMethod -Uri "http://localhost:11434/api/tags" -TimeoutSec 5
        Write-Success "Ollama disponible"

        # Lister les modèles
        Write-Info "Modeles installes:"
        foreach ($model in $tags.models) {
            Write-Info "  - $($model.name)"
        }

        # Vérifier si nomic-embed-text est installé
        $hasEmbedModel = $tags.models | Where-Object { $_.name -like "*nomic-embed*" -or $_.name -like "*embed*" }
        if (-not $hasEmbedModel) {
            Write-Warning "Modele d'embedding non trouve!"
            Write-Info "Installez avec: ollama pull nomic-embed-text"
        }
    } catch {
        Write-Error "Ollama non disponible sur http://localhost:11434"
        Write-Info "Installez Ollama: https://ollama.ai"
        Write-Info "Puis lancez: ollama pull nomic-embed-text"
        exit 1
    }

    Write-Info "Configuration Ollama OK"
    Write-Info "Modifiez config.php:"
    Write-Host ""
    Write-Host "    'embeddings' => [" -ForegroundColor Yellow
    Write-Host "        'enabled' => true," -ForegroundColor Yellow
    Write-Host "        'provider' => 'ollama'," -ForegroundColor Yellow
    Write-Host "        'ollama_model' => 'nomic-embed-text'," -ForegroundColor Yellow
    Write-Host "        'dimensions' => 768," -ForegroundColor Yellow
    Write-Host "    ]," -ForegroundColor Yellow
    Write-Host ""
}

Write-Header "Prochaines etapes"
Write-Host ""
Write-Host "1. Verifiez que Qdrant est demarre:" -ForegroundColor White
Write-Host "   curl http://localhost:6333/health" -ForegroundColor Gray
Write-Host ""
Write-Host "2. Synchronisez les embeddings:" -ForegroundColor White
Write-Host "   cd C:\wamp64\www\kdocs" -ForegroundColor Gray
Write-Host "   php bin\kdocs embeddings:sync --all" -ForegroundColor Gray
Write-Host ""
Write-Host "3. Testez la recherche semantique:" -ForegroundColor White
Write-Host "   php bin\kdocs search:semantic ""facture electricite""" -ForegroundColor Gray
Write-Host ""
