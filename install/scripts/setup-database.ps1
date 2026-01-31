# K-Docs - Configuration base de données
param(
    [string]$MySqlPath,
    [string]$Host = "localhost",
    [string]$User = "root",
    [string]$Password = "",
    [string]$Database = "kdocs"
)

function Write-Success { param($text) Write-Host "[OK] $text" -ForegroundColor Green }
function Write-Info { param($text) Write-Host "    $text" -ForegroundColor Gray }
function Write-Warning { param($text) Write-Host "[!] $text" -ForegroundColor Yellow }
function Write-Error { param($text) Write-Host "[X] $text" -ForegroundColor Red }

Write-Host "Configuration de la base de donnees..." -ForegroundColor Cyan

# Trouver MySQL si pas spécifié
if (-not $MySqlPath) {
    $searchPaths = @(
        "C:\wamp64\bin\mysql",
        "C:\wamp64\bin\mariadb",
        "C:\xampp\mysql\bin"
    )

    foreach ($searchPath in $searchPaths) {
        if (Test-Path $searchPath) {
            $found = Get-ChildItem $searchPath -Filter "mysql.exe" -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($found) {
                $MySqlPath = $found.FullName
                break
            }
        }
    }
}

if (-not $MySqlPath -or -not (Test-Path $MySqlPath)) {
    Write-Error "MySQL non trouve!"
    Write-Info "Specifiez le chemin avec -MySqlPath"
    exit 1
}

Write-Info "MySQL: $MySqlPath"

# Construire la commande
$mysqlArgs = @("-h", $Host, "-u", $User)
if ($Password) {
    $mysqlArgs += @("-p$Password")
}

# Tester la connexion
Write-Info "Test de connexion..."
try {
    $result = & $MySqlPath @mysqlArgs -e "SELECT 1" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Connexion MySQL echouee"
        Write-Info "Verifiez que MySQL/MariaDB est demarre"
        Write-Info "Verifiez les identifiants (user: $User)"
        exit 1
    }
    Write-Success "Connexion MySQL OK"
}
catch {
    Write-Error "Erreur connexion: $_"
    exit 1
}

# Vérifier si la base existe
Write-Info "Verification de la base '$Database'..."
$checkDb = & $MySqlPath @mysqlArgs -e "SHOW DATABASES LIKE '$Database'" 2>&1

if ($checkDb -like "*$Database*") {
    Write-Success "Base de donnees '$Database' existe deja"

    # Vérifier les tables
    $tables = & $MySqlPath @mysqlArgs -D $Database -e "SHOW TABLES" 2>&1
    $tableCount = ($tables | Where-Object { $_ -notlike "*Tables*" }).Count
    Write-Info "$tableCount tables trouvees"
} else {
    Write-Info "Creation de la base '$Database'..."

    & $MySqlPath @mysqlArgs -e "CREATE DATABASE IF NOT EXISTS ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>&1

    if ($LASTEXITCODE -eq 0) {
        Write-Success "Base de donnees '$Database' creee"
    } else {
        Write-Error "Echec creation base de donnees"
        exit 1
    }

    # Importer le schéma si disponible
    $schemaFile = Join-Path $PSScriptRoot "..\..\database\schema.sql"
    if (Test-Path $schemaFile) {
        Write-Info "Import du schema..."
        Get-Content $schemaFile | & $MySqlPath @mysqlArgs -D $Database 2>&1

        if ($LASTEXITCODE -eq 0) {
            Write-Success "Schema importe"
        } else {
            Write-Warning "Erreur lors de l'import du schema"
        }
    } else {
        Write-Info "Fichier schema non trouve - utilisez l'interface web pour initialiser"
    }
}

# Créer un utilisateur dédié (optionnel)
Write-Info "Configuration utilisateur kdocs..."

$createUserSql = @"
CREATE USER IF NOT EXISTS 'kdocs'@'localhost' IDENTIFIED BY 'kdocs_password';
GRANT ALL PRIVILEGES ON ``$Database``.* TO 'kdocs'@'localhost';
FLUSH PRIVILEGES;
"@

try {
    $createUserSql | & $MySqlPath @mysqlArgs 2>&1 | Out-Null
    Write-Success "Utilisateur 'kdocs' configure"
    Write-Info "  Utilisateur: kdocs"
    Write-Info "  Mot de passe: kdocs_password"
    Write-Warning "  Changez ce mot de passe en production!"
}
catch {
    Write-Warning "Impossible de creer l'utilisateur 'kdocs'"
}

Write-Success "Configuration base de donnees terminee"
