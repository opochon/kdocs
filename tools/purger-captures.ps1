# Vide les captures d'écran des rejeux (var\captures). À lancer quand une version est validée (V1, version
# candidate) : les verdicts versionnés dans recette\preuves restent, seules les images locales partent.
$ErrorActionPreference = 'Stop'
$racine = Split-Path -Parent $PSScriptRoot
$captures = Join-Path $racine 'var\captures'
if (Test-Path $captures) { Get-ChildItem $captures -Directory | Remove-Item -Recurse -Force; "captures vidées : $captures" } else { 'aucune capture' }
