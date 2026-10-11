# Vérifie l'agent local K-Docs : compilation stricte (avertissements = erreurs côté agent),
# puis tous les tests (unitaires + parcours F13 + oracles Office réels).
#
# Garde d'hygiène HKCU : le parcours F13 enregistre réellement HKCU\Software\Classes\kdocs ; la clé doit
# être IDENTIQUE avant et après la suite (les tests rendent l'état qu'ils ont trouvé, cf.
# agent-poste/tools/tester-installateur.ps1). Si elle a changé, la garde restaure l'état d'avant et la
# vérification échoue.
#
#   powershell -ExecutionPolicy Bypass -File agent-poste\tools\verifier.ps1
#   powershell -ExecutionPolicy Bypass -File agent-poste\tools\verifier.ps1 -Filtre "Categorie=F13"
param([string]$Filtre = '')
$ErrorActionPreference = 'Stop'
$racine = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $racine

$cleKdocs = 'HKCU:\Software\Classes\kdocs'
$bac = Join-Path $env:TEMP ('kdocs-verif-hkcu-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $bac | Out-Null

function Lire-EtatKdocs {
    if (-not (Test-Path -LiteralPath $cleKdocs)) { return 'ABSENTE' }
    $fichier = Join-Path $bac 'etat-courant.reg'
    & reg.exe export 'HKCU\Software\Classes\kdocs' $fichier /y | Out-Null
    return (((Get-Content -LiteralPath $fichier -Raw -Encoding Unicode) -replace "`r`n", "`n").Trim())
}

$avaitKdocs = Test-Path -LiteralPath $cleKdocs
$sauvegarde = Join-Path $bac 'kdocs-avant.reg'
if ($avaitKdocs) { & reg.exe export 'HKCU\Software\Classes\kdocs' $sauvegarde /y | Out-Null }
$etatAvant = Lire-EtatKdocs

try {
    dotnet build agent-poste\AgentPoste.slnx -c Debug --nologo
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    if ($Filtre -ne '') {
        dotnet test agent-poste\AgentPoste.slnx --nologo --filter $Filtre
    } else {
        dotnet test agent-poste\AgentPoste.slnx --nologo
    }
    $codeTests = $LASTEXITCODE

    $etatApres = Lire-EtatKdocs
    if ($etatApres -ne $etatAvant) {
        Write-Host 'HKCU: ROUGE — la clé kdocs a changé pendant la suite (les tests doivent rendre l''état trouvé).'
        Write-Host ("  avant : " + ($etatAvant -replace "`n", ' | '))
        Write-Host ("  après : " + ($etatApres -replace "`n", ' | '))
        # On rend l'état d'avant pour ne pas laisser le poste modifié, puis on échoue.
        if ($avaitKdocs) { & reg.exe import $sauvegarde | Out-Null }
        else { Remove-Item -LiteralPath $cleKdocs -Recurse -Force -ErrorAction SilentlyContinue }
        exit 1
    }

    Write-Host 'HKCU: VERT — clé kdocs identique avant/après la suite.'
    exit $codeTests
} finally {
    Remove-Item -LiteralPath $bac -Recurse -Force -ErrorAction SilentlyContinue
}
