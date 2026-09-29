# Harness GEDv2 : une commande, tout rejoué, un verdict. Une couche absente est ROUGE, jamais verte.
#   powershell -File tools\harness.ps1
$ErrorActionPreference = 'Continue'
$racine = Split-Path $PSScriptRoot -Parent
Set-Location $racine
New-Item -ItemType Directory var, recette -Force | Out-Null
$couches = [ordered]@{}
$debut = Get-Date

# 0. Garde-fous : on teste CE dépôt, avec la base de test, jamais une base réelle.
$env:DB_NAME = 'gedv2_test'
$env:APP_ENV = 'test'
$couches['0-garde-fous'] = @{ ok = (Test-Path "$racine\vendor\autoload.php") -and (Test-Path "$racine\node_modules\@playwright\test");
  detail = 'vendor et node_modules présents ; DB_NAME=gedv2_test imposé' }

# 1. Unitaire (PHPUnit)
$sortie = & php vendor\bin\phpunit --log-junit var\phpunit.xml 2>&1
$couches['1-unitaire'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Select-Object -Last 1) -join '' }

# 4. Écrans (Playwright) — le serveur est lancé par la configuration Playwright
$sortie = & npx.cmd playwright test --reporter=line 2>&1
$couches['4-ecrans'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Where-Object { $_ -match 'passed|failed' } | Select-Object -Last 2) -join ' ' }

# Couches à venir : elles comptent ROUGE tant qu'elles n'existent pas (règle anti-mensonge).
foreach ($c in '2-contrat', '3-banc', '5-personas', '6-charge', '7-securite') {
  $couches[$c] = @{ ok = $false; detail = 'pas encore écrite' }
}

$registre = [ordered]@{
  date = (Get-Date).ToString('s'); duree_s = [int]((Get-Date) - $debut).TotalSeconds
  commit = (git rev-parse --short HEAD 2>$null); couches = $couches
}
$registre | ConvertTo-Json -Depth 5 | Set-Content -Encoding utf8 recette\registre.json
foreach ($k in $couches.Keys) {
  $c = $couches[$k]; $etat = if ($c.ok) { 'VERT ' } else { 'ROUGE' }
  Write-Host ("{0}  {1,-14} {2}" -f $etat, $k, $c.detail)
}
Write-Host "Registre : recette\registre.json"
