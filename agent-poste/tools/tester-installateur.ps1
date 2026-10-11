#Requires -Version 5.1
<#
.SYNOPSIS
    Rejoue l'installateur K-Docs sur cette machine : installer, vérifier les clés, désinstaller,
    vérifier qu'il ne reste rien (hors données utilisateur, qui doivent survivre).

.DESCRIPTION
    Le test installe l'agent dans une racine de données ÉPHÉMÈRE (<bac>\K-Docs) mais dans les VRAIES clés
    HKCU (HKCU\Software\Classes\kdocs, HKCU\...\Run\K-Docs) : c'est ce que l'installateur doit écrire.
    L'état HKCU d'avant le test est sauvegardé puis restauré (reg export/import), pour ne rien laisser
    derrière soi sur le poste.

    La source par défaut est l'exécutable réellement construit ; si le build est absent, un faux
    exécutable est créé pour prouver la mécanique de copie (l'installateur copie tout le dossier source).

.PARAMETER SourceExe
    Exécutable de l'agent à installer. Par défaut : le build Debug de `Kdocs.AgentPoste.exe`.

.PARAMETER Garder
    Conserve le bac de test et l'état installé (diagnostic). Par défaut, tout est nettoyé et l'état HKCU
    d'avant est restauré.
#>
[CmdletBinding()]
param(
    [string]$SourceExe,
    [switch]$Garder
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$scriptInstaller = Join-Path $PSScriptRoot 'installer-agent.ps1'
if (-not (Test-Path -LiteralPath $scriptInstaller -PathType Leaf)) {
    throw 'tester-installateur : installer-agent.ps1 est introuvable'
}

$bac = Join-Path $env:TEMP ('kdocs-test-installateur-' + [guid]::NewGuid().ToString('N'))
$racine = Join-Path $bac 'K-Docs'
New-Item -ItemType Directory -Force -Path $racine | Out-Null

# Source de l'agent : le build réel s'il existe, sinon un faux binaire (mécanique de copie).
$source = $SourceExe
if ([string]::IsNullOrWhiteSpace($source)) {
    $build = Join-Path (Split-Path $PSScriptRoot -Parent) 'src\Kdocs.AgentPoste\bin\Debug\net10.0-windows\Kdocs.AgentPoste.exe'
    if (Test-Path -LiteralPath $build) {
        $source = (Resolve-Path -LiteralPath $build).Path
    } else {
        $dossierSource = Join-Path $bac 'source'
        New-Item -ItemType Directory -Force -Path $dossierSource | Out-Null
        Set-Content -Path (Join-Path $dossierSource 'Kdocs.AgentPoste.exe') -Value 'faux agent (test)' -Encoding ASCII
        Set-Content -Path (Join-Path $dossierSource 'Kdocs.AgentPoste.dll') -Value 'fausse bibliothèque (test)' -Encoding ASCII
        $source = Join-Path $dossierSource 'Kdocs.AgentPoste.exe'
    }
}
if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
    throw "tester-installateur : source introuvable ($source)"
}

# Donnée utilisateur préexistante : elle doit survivre à la désinstallation.
$donnee = Join-Path $racine 'Travail\1\rapport.docx'
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $donnee) | Out-Null
Set-Content -Path $donnee -Value 'document de travail synthétique' -Encoding UTF8

$cleProtocole = 'HKCU:\Software\Classes\kdocs'
$cleRun = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
$sauvegarde = Join-Path $bac 'kdocs-avant.reg'
$protocoleAvant = Test-Path -LiteralPath $cleProtocole
if ($protocoleAvant) {
    & reg.exe export 'HKCU\Software\Classes\kdocs' $sauvegarde /y | Out-Null
}
$runAvant = Get-ItemProperty -Path $cleRun -Name 'K-Docs' -ErrorAction SilentlyContinue
$runAvantValeur = if ($null -ne $runAvant) { $runAvant.'K-Docs' } else { $null }

function Assert-Vrai([bool]$Condition, [string]$Message) {
    if (-not $Condition) {
        throw "ÉCHEC : $Message"
    }
}

try {
    Write-Host "[K-Docs test] source : $source"
    & $scriptInstaller -Action Installer -SourceExe $source -Racine $racine | Out-Null

    $cible = Join-Path $racine 'Agent\Kdocs.AgentPoste.exe'
    Assert-Vrai (Test-Path -LiteralPath $cible -PathType Leaf) "l'agent n'est pas copié dans $cible"
    Assert-Vrai ((Get-ItemPropertyValue -Path $cleProtocole -Name '(default)') -eq 'URL:K-Docs Protocol') 'valeur par défaut du protocole incorrecte'
    Assert-Vrai ((Get-ItemPropertyValue -Path $cleProtocole -Name 'URL Protocol') -eq '') 'valeur « URL Protocol » incorrecte'
    Assert-Vrai ((Get-ItemPropertyValue -Path "$cleProtocole\shell\open\command" -Name '(default)') -eq ('"' + $cible + '" "%1"')) 'commande du protocole incorrecte'
    Assert-Vrai ((Get-ItemPropertyValue -Path $cleRun -Name 'K-Docs') -eq ('"' + $cible + '"')) 'valeur de démarrage à l’ouverture de session incorrecte'

    & $scriptInstaller -Action Desinstaller -Racine $racine | Out-Null

    Assert-Vrai (-not (Test-Path -LiteralPath (Join-Path $racine 'Agent'))) 'le dossier Agent subsiste après désinstallation'
    Assert-Vrai (-not (Test-Path -LiteralPath $cleProtocole)) 'la clé de protocole subsiste après désinstallation'
    Assert-Vrai ($null -eq (Get-ItemProperty -Path $cleRun -Name 'K-Docs' -ErrorAction SilentlyContinue)) 'la valeur de démarrage subsiste après désinstallation'
    Assert-Vrai (Test-Path -LiteralPath $donnee -PathType Leaf) 'les données utilisateur ont été supprimées (interdit)'

    Write-Host 'INSTALLATEUR: OK (installation, clés vérifiées, désinstallation propre, données utilisateur conservées)'
} finally {
    if (-not $Garder) {
        if (Test-Path -LiteralPath $cleProtocole) {
            Remove-Item -Path $cleProtocole -Recurse -Force
        }
        if ($protocoleAvant -and (Test-Path -LiteralPath $sauvegarde)) {
            & reg.exe import $sauvegarde | Out-Null
        }
        if ($null -ne $runAvantValeur) {
            Set-ItemProperty -Path $cleRun -Name 'K-Docs' -Value $runAvantValeur -Type String
        } else {
            Remove-ItemProperty -Path $cleRun -Name 'K-Docs' -Force -ErrorAction SilentlyContinue
        }
        Remove-Item -LiteralPath $bac -Recurse -Force -ErrorAction SilentlyContinue
    } else {
        Write-Host "[K-Docs test] bac conservé : $bac"
    }
}
