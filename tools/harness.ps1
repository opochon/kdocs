# Harness GEDv2 : une commande, tout rejoué, un verdict. Une couche absente est ROUGE, jamais verte.
#   powershell -File tools\harness.ps1 [-SansBanc]   (-SansBanc : saute la couche 3, qui compte alors ROUGE)
param([switch]$SansBanc)
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

# 3. Banc : corpus + vérité, à travers le code de la GED. Chaque banc attendu doit exister ET franchir sa porte.
if ($SansBanc) {
  $couches['3-banc'] = @{ ok = $false; detail = 'sautée (-SansBanc)' }
} else {
  $bancs = [ordered]@{ 'decoupe' = 'tools\banc\mesurer-decoupe.php'; 'classement' = 'tools\banc\mesurer-classement.php'; 'bout-en-bout' = 'tools\banc\rejouer-ingestion.php' }
  $ok = $true; $details = @()
  foreach ($b in $bancs.Keys) {
    if (-not (Test-Path $bancs[$b])) { $ok = $false; $details += "$b : à écrire"; continue }
    $sortie = & php $bancs[$b] courrier-100p 2>&1   # le banc lit le .env (moteur, clés) et n'écrit dans aucune base
    if ($LASTEXITCODE -ne 0) { $ok = $false }
    $details += "$b : " + (($sortie | Select-Object -Last 1) -join '')
  }
  $couches['3-banc'] = @{ ok = $ok; detail = ($details -join ' | ') }
}

# 4. Écrans (Playwright) — le serveur est lancé par la configuration Playwright
$sortie = & npx.cmd playwright test --reporter=line 2>&1
$couches['4-ecrans'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Where-Object { $_ -match 'passed|failed' } | Select-Object -Last 2) -join ' ' }

# 7. Sécurité : sonde offensive HTTP, serveur et sessions de test isolés.
$sonde = 'tools\banc\sonde-securite.php'
if (-not (Test-Path $sonde)) {
  $couches['7-securite'] = @{ ok = $false; detail = 'sonde absente' }
} else {
  $sortie = & php $sonde 2>&1
  $couches['7-securite'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Select-Object -Last 1) -join '' }
}

# 6. Charge : 3 puis 10 utilisateurs simultanés, une tâche d'ingestion en fond, base gedv2_test sous verrou.
#    Fonds de 10 000 documents préparé dans var/ (jamais dans tests/corpus) ; mesuré par tools\banc\charge.php.
$charge = 'tools\banc\charge.php'
if (-not (Test-Path $charge)) {
  $couches['6-charge'] = @{ ok = $false; detail = 'banc absent' }
} else {
  $sortie = & php $charge 2>&1
  $couches['6-charge'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Select-Object -Last 1) -join '' }
}

# Couches à venir : elles comptent ROUGE tant qu'elles n'existent pas (règle anti-mensonge).
foreach ($c in '2-contrat', '5-personas') {
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
