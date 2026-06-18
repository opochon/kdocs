@echo off
REM Tue les processus PHP qui ecoutent sur le port dev GEDv1 (evite zombies / doubles listen)
setlocal
set "PORT=%~1"
if "%PORT%"=="" set "PORT=8765"

echo [kill-dev-port] Port %PORT%...

for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":%PORT%" ^| findstr LISTENING') do (
    echo   taskkill PID %%a
    taskkill /F /PID %%a >nul 2>&1
)

powershell -NoProfile -Command ^
  "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -match ':%PORT%' -or ($_.CommandLine -match 'router\.php' -and $_.CommandLine -match '8765|8770') } | ForEach-Object { Write-Host ('  stop php PID ' + $_.ProcessId); Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"

ping 127.0.0.1 -n 2 >nul
exit /b 0
