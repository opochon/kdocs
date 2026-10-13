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

# 4. Écrans (Playwright) — le serveur est lancé par la configuration Playwright. `--no-install` : sans
#    node_modules, npx échoue au lieu d'attendre une confirmation qui bloque le harness (constat du juge, 11-10). Le projet « personas »
#    est exclu : la couche 5 a son propre projet et se joue plus bas, seule.
#    Lot ged-parite-ecrans-1 : la couche 9 relit `var\e2e-latest.json` (identifiants des parcours, preuve des
#    écrans). Le rapport JSON est donc écrit EN PLUS de la sortie ligne (l'override `--reporter=line`
#    l'aurait supprimé) ; les preuves d'écran ne sont pas un faux vert.
$env:PLAYWRIGHT_JSON_OUTPUT_NAME = 'var\e2e-latest.json'
$sortie = & npx.cmd --no-install playwright test --project=parcours --project=budgets --reporter=line,json 2>&1
Remove-Item Env:\PLAYWRIGHT_JSON_OUTPUT_NAME -ErrorAction SilentlyContinue
# Sortie complète gardée hors dépôt : un écran rouge se nomme sans rejouer la couche (constat du juge, 11-10).
New-Item -ItemType Directory -Force var\harness | Out-Null; $sortie | Out-File var\harness\4-ecrans.txt -Encoding utf8
$couches['4-ecrans'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Where-Object { $_ -match 'passed|failed' } | Select-Object -Last 2) -join ' ' }

# 7. Sécurité : sonde offensive HTTP, serveur et sessions de test isolés.
$sonde = 'tools\banc\sonde-securite.php'
if (-not (Test-Path $sonde)) {
  $couches['7-securite'] = @{ ok = $false; detail = 'sonde absente' }
} else {
  $sortie = & php $sonde 2>&1
  $couches['7-securite'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Select-Object -Last 1) -join '' }
}

# 2. Contrat : mêmes propriétés pour chaque implémentation et toutes les routes déclarées, sur serveur HTTP réel.
$sortie = & php tools\banc\contrat.php 2>&1
$couches['2-contrat'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Select-Object -Last 1) -join '' }

# 6. Charge : 3 puis 10 utilisateurs simultanés, une tâche d'ingestion en fond, base gedv2_test sous verrou.
#    Fonds de 10 000 documents préparé dans var/ (jamais dans tests/corpus) ; mesuré par tools\banc\charge.php.
$charge = 'tools\banc\charge.php'
if (-not (Test-Path $charge)) {
  $couches['6-charge'] = @{ ok = $false; detail = 'banc absent' }
} else {
  $sortie = & php $charge 2>&1
  $couches['6-charge'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Select-Object -Last 1) -join '' }
}

# 8. Qualité statique : PHPStan niveau 6 (aucune erreur hors baseline) + audit des dépendances (composer audit).
#    Voir docs\qualite\README.md (Porte qualité). Un audit impossible (réseau) compte ROUGE : on ne ment pas.
$stan = & php vendor\bin\phpstan analyse --no-progress --memory-limit=2G --error-format=raw 2>&1; $okStan = ($LASTEXITCODE -eq 0)
$nbStan = ($stan | Select-String ':\d+:').Count
$audit = & php tools\composer.phar audit --locked --no-interaction 2>&1; $okAudit = ($LASTEXITCODE -eq 0)
$couches['8-statique'] = @{ ok = ($okStan -and $okAudit); detail = ("phpstan : $nbStan erreur(s) hors baseline | audit : " + (($audit | Select-Object -Last 1) -join ''))}
# 5. Personas : chaque persona joue ses gestes F1–F12 depuis l'écran d'accueil (menus et libellés
#    visibles, jamais d'URL directe sauf la connexion). Un geste non trouvé en 30 s est un ÉCHEC NOMMÉ,
#    jamais un test sauté. La couche est VERTE seulement si tous les personas trouvent tout ; le détail
#    (persona, gestes trouvés / total, temps médian) vient des preuves recette\preuves\personas\.
$dossierPersonas = 'recette\preuves\personas'
Get-ChildItem "$dossierPersonas\*.json" -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
Get-ChildItem "$dossierPersonas\*.png" -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
# Captures HORS du dépôt : var\captures\personas\<rejeu>\ (décision d'Olivier, 10-10 : jamais de captures dans git).
$env:GED_CAPTURES_RUN = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$sortie = & npx.cmd --no-install playwright test --project=personas --reporter=line 2>&1
New-Item -ItemType Directory -Force var\harness | Out-Null; $sortie | Out-File var\harness\5-personas.txt -Encoding utf8
$okPersonas = ($LASTEXITCODE -eq 0)
$detailsPersonas = @()
$manifests = Get-ChildItem "$dossierPersonas\*.manifest.json" -ErrorAction SilentlyContinue | Sort-Object Name
foreach ($manifeste in $manifests) {
  $m = Get-Content $manifeste.FullName -Raw | ConvertFrom-Json
  $trouves = 0; $temps = @()
  foreach ($nomGeste in $m.gestes) {
    $fichier = Join-Path $dossierPersonas ("{0}-{1}.json" -f $m.persona, $nomGeste)
    if (Test-Path $fichier) {
      $geste = Get-Content $fichier -Raw | ConvertFrom-Json
      if ($geste.trouve) { $trouves++; $temps += [int]$geste.temps_ms }
    }
  }
  $total = @($m.gestes).Count
  $median = 0
  if ($temps.Count -gt 0) {
    $trie = @($temps | Sort-Object)
    $milieu = [math]::Floor($trie.Count / 2)
    if ($trie.Count % 2 -eq 0) { $median = [int](($trie[$milieu - 1] + $trie[$milieu]) / 2) } else { $median = [int]$trie[$milieu] }
  }
  if ($trouves -ne $total) { $okPersonas = $false }
  $detailsPersonas += ("{0}: {1}/{2} (médiane {3} ms)" -f $m.persona, $trouves, $total, $median)
}
if ($detailsPersonas.Count -eq 0) { $okPersonas = $false; $detailsPersonas += 'aucun persona joué' }
$couches['5-personas'] = @{ ok = $okPersonas; detail = ($detailsPersonas -join ' | ') }
# Rétention des captures : les 5 derniers rejeux (un rejeu rouge est marqué ROUGE.txt ; tant qu'aucun vert ne
# l'a suivi, il est forcément parmi les plus récents). À la validation d'une version, tools\purger-captures.ps1 vide tout.
$dossierCaptures = 'var\captures\personas'
$rejeu = Join-Path $dossierCaptures $env:GED_CAPTURES_RUN
if (-not $okPersonas -and (Test-Path $rejeu)) { Set-Content (Join-Path $rejeu 'ROUGE.txt') ($detailsPersonas -join ' | ') -Encoding UTF8 }
$rejeux = @(Get-ChildItem $dossierCaptures -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'manuel' } | Sort-Object Name -Descending)
$rejeux | Select-Object -Skip 5 | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item Env:\GED_CAPTURES_RUN -ErrorAction SilentlyContinue

# 9. Parite Paperless-ngx : regenere l'inventaire depuis les sources de reference, le compare au
#    fichier versionne, verifie que chaque « couvert » a un equivalent GEDv2 et une preuve passee au
#    dernier rejeu, puis ecrit recette\parite-paperless.json et docs\parite\PARITE-PAPERLESS.md.
#    Le pourcentage n'est pas un critere : la couche est verte si l'inventaire est complet et rejoue a l'identique.
$sortie = & php tools\parite\juge.php 2>&1
New-Item -ItemType Directory -Force var\harness | Out-Null; $sortie | Out-File var\harness\9-parite.txt -Encoding utf8
$couches['9-parite'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Where-Object { $_ -match '^parite' } | Select-Object -Last 1) -join '' }

# 9b. Parite M-Files (lot ged-mfiles-oracle-1) : meme methode, sur l'inventaire extrait de la
#     DOCUMENTATION PUBLIQUE M-Files (capture versionnee). Vert si l'inventaire est complet et rejoue
#     a l'identique ; ecrit recette\parite-mfiles.json et docs\parite\PARITE-MFILES.md.
$sortie = & php tools\parite\juge-mfiles.php 2>&1
$sortie | Out-File var\harness\9-parite-mfiles.txt -Encoding utf8
$couches['9-parite-mfiles'] = @{ ok = ($LASTEXITCODE -eq 0); detail = ($sortie | Where-Object { $_ -match '^parite M-Files' } | Select-Object -Last 1) -join '' }

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
