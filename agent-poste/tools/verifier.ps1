# Vérifie l'agent local K-Docs : compilation stricte (avertissements = erreurs côté agent),
# puis tous les tests (unitaires + parcours F13 + oracles Office réels).
#   powershell -ExecutionPolicy Bypass -File agent-poste\tools\verifier.ps1
#   powershell -ExecutionPolicy Bypass -File agent-poste\tools\verifier.ps1 -Filtre "Categorie=F13"
param([string]$Filtre = '')
$ErrorActionPreference = 'Stop'
$racine = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
Set-Location $racine

dotnet build agent-poste\AgentPoste.slnx -c Debug --nologo
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if ($Filtre -ne '') {
  dotnet test agent-poste\AgentPoste.slnx --nologo --filter $Filtre
} else {
  dotnet test agent-poste\AgentPoste.slnx --nologo
}
exit $LASTEXITCODE
