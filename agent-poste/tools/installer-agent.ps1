#Requires -Version 5.1
<#
.SYNOPSIS
    Installe ou désinstalle l'agent local K-Docs SANS droits administrateur.

.DESCRIPTION
    Tout se fait dans le profil de l'utilisateur (cahier des charges « installateur sans droits
    administrateur », modèle du bridge WinBiz de K-Time) :

      - l'agent est copié dans %LOCALAPPDATA%\K-Docs\Agent ;
      - le protocole d'URL `kdocs://` est enregistré sous HKCU\Software\Classes\kdocs
        (documentation Microsoft « Registering an Application to a URI Scheme » : HKEY_CLASSES_ROOT est
        la vue fusionnée de HKCU\Software\Classes et HKLM\Software\Classes, une entrée par utilisateur
        suffit et ne demande aucun droit administrateur) ;
      - l'agent démarre à l'ouverture de session par une valeur dans
        HKCU\Software\Microsoft\Windows\CurrentVersion\Run
        (documentation Microsoft « Run and RunOnce Registry Keys »).

    La DÉSINSTALLATION retire le programme, la clé de protocole et la valeur d'ouverture de session.
    Elle NE TOUCHE PAS aux données de l'utilisateur (documents de travail, copies de secours, journal,
    jeton, configuration) : règle « zéro suppression de donnée utilisateur par le produit ».

.PARAMETER Action
    Installer (défaut) ou Desinstaller.

.PARAMETER SourceExe
    Chemin de l'exécutable de l'agent à installer (installer uniquement). Tous les fichiers du même
    dossier (bibliothèques, runtimeconfig) sont copiés avec lui.

.PARAMETER Racine
    Racine des données de l'agent (défaut %LOCALAPPDATA%\K-Docs). L'agent vit dans <Racine>\Agent.

.PARAMETER Serveur
    Facultatif (installer) : adresse du serveur GEDv2. Si fournie, l'enrôlement est tenté juste après
    l'installation (jeton d'appareil chiffré DPAPI).

.PARAMETER Code
    Facultatif : code d'appairage unique.

.PARAMETER Verification
    Facultatif : code de vérification de l'appairage.

.PARAMETER Poste
    Facultatif : nom du poste ; à défaut, le nom de la machine.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File installer-agent.ps1 -SourceExe .\Kdocs.AgentPoste.exe

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File installer-agent.ps1 -Action Desinstaller
#>
[CmdletBinding()]
param(
    [ValidateSet('Installer', 'Desinstaller')]
    [string]$Action = 'Installer',

    [string]$SourceExe,

    [string]$Racine = (Join-Path $env:LOCALAPPDATA 'K-Docs'),

    [string]$Serveur,

    [string]$Code,

    [string]$Verification,

    [string]$Poste
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$CleProtocole = 'HKCU:\Software\Classes\kdocs'
$CleRun = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
$NomRun = 'K-Docs'

function Get-DossierAgent {
    param([string]$RacineAgent)
    return (Join-Path $RacineAgent 'Agent')
}

function Get-CibleExe {
    param([string]$DossierAgent)
    return (Join-Path $DossierAgent 'Kdocs.AgentPoste.exe')
}

function Installer-Agent {
    param([string]$Source, [string]$RacineAgent, [string]$AdresseServeur, [string]$CodeAppairage,
        [string]$CodeVerification, [string]$NomPoste)

    if ([string]::IsNullOrWhiteSpace($Source) -or -not (Test-Path -LiteralPath $Source -PathType Leaf)) {
        throw "installateur : l'exécutable source est introuvable ($Source)"
    }

    $dossierAgent = Get-DossierAgent -RacineAgent $RacineAgent
    $cible = Get-CibleExe -DossierAgent $dossierAgent
    New-Item -ItemType Directory -Path $dossierAgent -Force | Out-Null

    # Copie de l'agent et de ses dépendances (dossier source entier), remplacés à chaque installation.
    $dossierSource = Split-Path -Parent (Resolve-Path -LiteralPath $Source).Path
    Copy-Item -Path (Join-Path $dossierSource '*') -Destination $dossierAgent -Recurse -Force
    if (-not (Test-Path -LiteralPath $cible -PathType Leaf)) {
        throw "installateur : la copie de l'agent a échoué ($cible)"
    }

    # Protocole d'URL par utilisateur : « <exe> » « %1 » (le lien reçu est analysé par l'agent).
    New-Item -Path $CleProtocole -Force | Out-Null
    Set-Item -Path $CleProtocole -Value 'URL:K-Docs Protocol'
    New-ItemProperty -Path $CleProtocole -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    New-Item -Path (Join-Path $CleProtocole 'shell\open\command') -Force | Out-Null
    Set-Item -Path (Join-Path $CleProtocole 'shell\open\command') -Value ('"' + $cible + '" "%1"')

    # Démarrage à l'ouverture de session, dans le profil de l'utilisateur (aucun droit administrateur).
    New-Item -Path $CleRun -Force | Out-Null
    Set-ItemProperty -Path $CleRun -Name $NomRun -Value ('"' + $cible + '"') -Type String

    Write-Host "[K-Docs] agent installé : $cible"
    Write-Host "[K-Docs] protocole kdocs:// enregistré (HKCU\Software\Classes\kdocs)"
    Write-Host "[K-Docs] démarrage à l'ouverture de session enregistré ($CleRun\$NomRun)"

    if (-not [string]::IsNullOrWhiteSpace($AdresseServeur)) {
        $arguments = @('--enrolement', '--serveur', $AdresseServeur,
            '--sortie', (Join-Path $RacineAgent 'enrolement.json'))
        if (-not [string]::IsNullOrWhiteSpace($CodeAppairage)) { $arguments += @('--code', $CodeAppairage) }
        if (-not [string]::IsNullOrWhiteSpace($CodeVerification)) { $arguments += @('--verification', $CodeVerification) }
        if (-not [string]::IsNullOrWhiteSpace($NomPoste)) { $arguments += @('--poste', $NomPoste) }

        Write-Host "[K-Docs] enrôlement du poste auprès de $AdresseServeur…"
        & $cible @arguments
        if ($LASTEXITCODE -ne 0) {
            throw "installateur : l'enrôlement a échoué (code $LASTEXITCODE), voir $RacineAgent\enrolement.json"
        }

        Write-Host '[K-Docs] poste enrôlé (jeton chiffré DPAPI)'
    }
}

function Desinstaller-Agent {
    param([string]$RacineAgent)

    $dossierAgent = Get-DossierAgent -RacineAgent $RacineAgent

    # 1. Ouverture de session.
    if ($null -ne (Get-ItemProperty -Path $CleRun -Name $NomRun -ErrorAction SilentlyContinue)) {
        Remove-ItemProperty -Path $CleRun -Name $NomRun -Force
        Write-Host "[K-Docs] démarrage automatique retiré ($CleRun\$NomRun)"
    }

    # 2. Protocole d'URL.
    if (Test-Path -LiteralPath $CleProtocole) {
        Remove-Item -Path $CleProtocole -Recurse -Force
        Write-Host '[K-Docs] protocole kdocs:// retiré (HKCU\Software\Classes\kdocs)'
    }

    # 3. Programme. Les données de l'utilisateur ne sont JAMAIS supprimées.
    if (Test-Path -LiteralPath $dossierAgent) {
        Remove-Item -LiteralPath $dossierAgent -Recurse -Force
        Write-Host "[K-Docs] programme retiré ($dossierAgent)"
    }

    Write-Host "[K-Docs] données de l'utilisateur conservées sous $RacineAgent (documents, secours, journal)"
}

switch ($Action) {
    'Installer' {
        Installer-Agent -Source $SourceExe -RacineAgent $Racine -AdresseServeur $Serveur `
            -CodeAppairage $Code -CodeVerification $Verification -NomPoste $Poste
    }
    'Desinstaller' {
        Desinstaller-Agent -RacineAgent $Racine
    }
}
