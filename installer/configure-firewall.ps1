# =============================================================================
# K-Docs - Configuration du Firewall Windows pour Docker/OnlyOffice
# Script PowerShell pour Windows
# =============================================================================
# Usage: .\configure-firewall.ps1
# Necessite les droits administrateur
#
# Ce script:
# 1. Detecte l'IP locale de la machine
# 2. Configure le firewall pour permettre les connexions Docker
# 3. Met a jour config.php avec la bonne callback_url
# =============================================================================

param(
    [switch]$RemoveRules,
    [string]$ManualIP
)

$ErrorActionPreference = "Stop"

# Configuration
$RuleNamePrefix = "K-Docs"
$Ports = @(80, 443, 8080)  # HTTP, HTTPS, OnlyOffice

# Couleurs
function Write-Step { param($msg) Write-Host "`n>> $msg" -ForegroundColor Cyan }
function Write-Success { param($msg) Write-Host "   [OK] $msg" -ForegroundColor Green }
function Write-Warning { param($msg) Write-Host "   [!] $msg" -ForegroundColor Yellow }
function Write-Error { param($msg) Write-Host "   [X] $msg" -ForegroundColor Red }
function Write-Info { param($msg) Write-Host "   $msg" -ForegroundColor Gray }

# Banner
Write-Host ""
Write-Host "=============================================" -ForegroundColor Blue
Write-Host "  K-Docs - Configuration Firewall Windows" -ForegroundColor White
Write-Host "  Pour Docker / OnlyOffice" -ForegroundColor Gray
Write-Host "=============================================" -ForegroundColor Blue

# Verifier si admin
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Error "Ce script necessite les droits administrateur."
    Write-Host "   Relancez PowerShell en tant qu'administrateur." -ForegroundColor Gray
    exit 1
}

# Option: Supprimer les regles
if ($RemoveRules) {
    Write-Step "Suppression des regles K-Docs..."

    $rules = Get-NetFirewallRule -DisplayName "$RuleNamePrefix*" -ErrorAction SilentlyContinue
    if ($rules) {
        $rules | Remove-NetFirewallRule
        Write-Success "Regles supprimees: $($rules.Count)"
    } else {
        Write-Info "Aucune regle K-Docs trouvee"
    }

    Write-Host ""
    exit 0
}

# Detecter l'IP locale
Write-Step "Detection de l'adresse IP locale..."

$localIPs = @()

# Methode 1: Get-NetIPAddress (interfaces actives)
$netIPs = Get-NetIPAddress -AddressFamily IPv4 -PrefixOrigin Dhcp, Manual -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike "127.*" -and $_.IPAddress -notlike "169.254.*" } |
    Select-Object -ExpandProperty IPAddress

$localIPs += $netIPs

# Methode 2: Fallback avec hostname
if ($localIPs.Count -eq 0) {
    $hostEntry = [System.Net.Dns]::GetHostEntry([System.Net.Dns]::GetHostName())
    $hostIPs = $hostEntry.AddressList |
        Where-Object { $_.AddressFamily -eq 'InterNetwork' -and $_.IPAddressToString -notlike "127.*" } |
        Select-Object -ExpandProperty IPAddressToString
    $localIPs += $hostIPs
}

# Dedupliquer
$localIPs = $localIPs | Select-Object -Unique

if ($localIPs.Count -eq 0) {
    Write-Error "Impossible de detecter l'adresse IP locale"
    Write-Host "   Utilisez -ManualIP pour specifier l'IP manuellement:" -ForegroundColor Gray
    Write-Host "   .\configure-firewall.ps1 -ManualIP 192.168.1.100" -ForegroundColor White
    exit 1
}

# Afficher les IPs detectees
Write-Success "Adresse(s) IP detectee(s):"
$i = 1
foreach ($ip in $localIPs) {
    Write-Host "   [$i] $ip" -ForegroundColor White
    $i++
}

# Selectionner l'IP
$selectedIP = $ManualIP
if (-not $selectedIP) {
    if ($localIPs.Count -eq 1) {
        $selectedIP = $localIPs[0]
        Write-Info "IP selectionnee automatiquement: $selectedIP"
    } else {
        Write-Host ""
        $choice = Read-Host "   Quelle IP utiliser? (1-$($localIPs.Count), ou tapez une IP)"

        if ($choice -match '^\d+$' -and [int]$choice -ge 1 -and [int]$choice -le $localIPs.Count) {
            $selectedIP = $localIPs[[int]$choice - 1]
        } elseif ($choice -match '^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$') {
            $selectedIP = $choice
        } else {
            $selectedIP = $localIPs[0]
        }
    }
}

Write-Host ""
Write-Success "IP selectionnee: $selectedIP"

# Configurer le Firewall
Write-Step "Configuration des regles de firewall..."

foreach ($port in $Ports) {
    $ruleName = "$RuleNamePrefix - Port $port (Docker Inbound)"

    # Verifier si la regle existe
    $existingRule = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue

    if ($existingRule) {
        Write-Info "Regle existante mise a jour: $ruleName"
        Set-NetFirewallRule -DisplayName $ruleName -Enabled True -Action Allow
    } else {
        # Creer la regle
        New-NetFirewallRule `
            -DisplayName $ruleName `
            -Description "Permet a Docker d'acceder a K-Docs sur le port $port" `
            -Direction Inbound `
            -Protocol TCP `
            -LocalPort $port `
            -Action Allow `
            -Profile Any `
            -Enabled True | Out-Null

        Write-Success "Regle creee: $ruleName"
    }
}

# Regle pour Docker Desktop (subnet)
$dockerRuleName = "$RuleNamePrefix - Docker Desktop Subnet"
$existingDockerRule = Get-NetFirewallRule -DisplayName $dockerRuleName -ErrorAction SilentlyContinue

if (-not $existingDockerRule) {
    New-NetFirewallRule `
        -DisplayName $dockerRuleName `
        -Description "Permet les connexions depuis Docker Desktop" `
        -Direction Inbound `
        -Protocol TCP `
        -LocalPort 80,443,8080 `
        -RemoteAddress 172.16.0.0/12,192.168.0.0/16,10.0.0.0/8 `
        -Action Allow `
        -Profile Any `
        -Enabled True | Out-Null

    Write-Success "Regle Docker subnet creee"
}

# Mettre a jour config.php
Write-Step "Mise a jour de la configuration K-Docs..."

$configPath = Join-Path (Split-Path $PSScriptRoot -Parent) "config\config.php"
$callbackUrl = "http://$selectedIP/kdocs"

if (Test-Path $configPath) {
    $configContent = Get-Content $configPath -Raw

    # Backup
    $backupPath = "$configPath.backup_$(Get-Date -Format 'yyyyMMdd_HHmmss')"
    Copy-Item $configPath $backupPath
    Write-Info "Backup cree: $backupPath"

    # Remplacer callback_url
    $pattern = "('callback_url'\s*=>\s*')[^']*(')"
    $replacement = "`$1$callbackUrl`$2"

    if ($configContent -match $pattern) {
        $newContent = $configContent -replace $pattern, $replacement
        Set-Content $configPath $newContent -Encoding UTF8
        Write-Success "callback_url mis a jour: $callbackUrl"
    } else {
        Write-Warning "Pattern callback_url non trouve dans config.php"
        Write-Host "   Ajoutez manuellement dans la section 'onlyoffice':" -ForegroundColor Gray
        Write-Host "   'callback_url' => '$callbackUrl'," -ForegroundColor White
    }
} else {
    Write-Warning "config.php non trouve: $configPath"
    Write-Host "   Configurez manuellement callback_url:" -ForegroundColor Gray
    Write-Host "   'callback_url' => '$callbackUrl'," -ForegroundColor White
}

# Tester la connectivite
Write-Step "Test de connectivite..."

# Test local
try {
    $testUrl = "http://localhost/kdocs"
    $response = Invoke-WebRequest -Uri $testUrl -UseBasicParsing -TimeoutSec 5 -ErrorAction SilentlyContinue
    Write-Success "K-Docs accessible localement (localhost)"
} catch {
    Write-Warning "K-Docs non accessible sur localhost"
}

# Test avec IP
try {
    $testUrl = "http://$selectedIP/kdocs"
    $response = Invoke-WebRequest -Uri $testUrl -UseBasicParsing -TimeoutSec 5 -ErrorAction SilentlyContinue
    Write-Success "K-Docs accessible via IP ($selectedIP)"
} catch {
    Write-Warning "K-Docs non accessible sur $selectedIP - verifiez Apache/WAMP"
}

# Test OnlyOffice
try {
    $onlyOfficeUrl = "http://localhost:8080/healthcheck"
    $response = Invoke-WebRequest -Uri $onlyOfficeUrl -UseBasicParsing -TimeoutSec 5 -ErrorAction SilentlyContinue
    if ($response.Content -eq "true") {
        Write-Success "OnlyOffice Document Server en ligne"
    }
} catch {
    Write-Warning "OnlyOffice non accessible - verifiez Docker"
}

# Resume
Write-Host ""
Write-Host "=============================================" -ForegroundColor Green
Write-Host "  Configuration terminee!" -ForegroundColor White
Write-Host "=============================================" -ForegroundColor Green
Write-Host ""
Write-Host "Resume:" -ForegroundColor Cyan
Write-Host "  IP locale:     $selectedIP" -ForegroundColor White
Write-Host "  callback_url:  $callbackUrl" -ForegroundColor White
Write-Host "  Ports ouverts: $($Ports -join ', ')" -ForegroundColor White
Write-Host ""
Write-Host "Pour supprimer les regles:" -ForegroundColor Gray
Write-Host "  .\configure-firewall.ps1 -RemoveRules" -ForegroundColor White
Write-Host ""
Write-Host "Redemarrez K-Docs et regenerez les miniatures." -ForegroundColor Yellow
Write-Host ""
