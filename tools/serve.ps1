# Serveur de dev GEDv2 : http://127.0.0.1:8870 (OPcache coupé : on sert toujours le code du disque).
param([int]$Port = 8870)
$racine = Split-Path $PSScriptRoot -Parent
Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
  ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }
Start-Process -WindowStyle Hidden -FilePath php -ArgumentList '-d', 'opcache.enable=0', '-S', "127.0.0.1:$Port", '-t', 'public', 'public/index.php' -WorkingDirectory $racine
Start-Sleep -Seconds 1
$sante = try { (Invoke-WebRequest "http://127.0.0.1:$Port/sante" -UseBasicParsing -TimeoutSec 10).Content } catch { "injoignable : $($_.Exception.Message)" }
Write-Host "GEDv2 : http://127.0.0.1:$Port  ·  $sante"
