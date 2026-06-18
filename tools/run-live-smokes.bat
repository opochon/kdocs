@echo off
REM GEDv1 - Smokes live (serveur + HTTP + login + pages)
setlocal EnableDelayedExpansion
cd /d "%~dp0.."

set BASE=http://127.0.0.1:8765/kdocs
set GEDV1_DEBUG_SESSION=4af063
set AUDIT_BASE_URL=%BASE%

echo.
echo === GEDv1 LIVE SMOKES ===
echo Base: %BASE%
echo.

REM 1. Offline migration smoke
php tests\migration_smoke_test.php
if errorlevel 1 exit /b 1

REM 2. Demarrer serveur si health pas 200
php -r "$c=@curl_init('http://127.0.0.1:8765/kdocs/health');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_NOBODY=>1]);curl_exec($c);$h=curl_getinfo($c,CURLINFO_HTTP_CODE);exit($h===200?0:1);"
if not errorlevel 1 goto server_ready

echo [INFO] Nettoyage port 8765 puis demarrage serveur
call "%~dp0kill-dev-port.bat" 8765
echo [INFO] Demarrage serveur: php -S 127.0.0.1:8765 router.php
start "GEDv1-dev" /MIN cmd /c "set GEDV1_DEBUG_SESSION=4af063 && php -S 127.0.0.1:8765 router.php"
set WAIT_SEC=0
:wait_health
php -r "$c=@curl_init('http://127.0.0.1:8765/kdocs/health');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>3]);curl_exec($c);$h=curl_getinfo($c,CURLINFO_HTTP_CODE);exit($h===200?0:1);"
if not errorlevel 1 goto server_ready
ping 127.0.0.1 -n 2 >nul
set /a WAIT_SEC+=1
if !WAIT_SEC! geq 15 (
    echo [ERROR] Health timeout apres 15s
    exit /b 2
)
goto wait_health

:server_ready
echo [OK] Health 200 - serveur pret

REM 3. Smoke HTTP (routes publiques + env)
php tests\smoke_test.php %BASE%
set SMOKE_RC=%errorlevel%

REM 4. Smoke live authentifie (login + pages + assets)
php tests\live_smoke_test.php %BASE%
set LIVE_RC=%errorlevel%

REM 4b. Smoke exhaustif pages + API GET
php tests\full_pages_smoke_test.php %BASE%
set FULL_RC=%errorlevel%

REM 5. Audit instrumente
php tools\audit_with_log.php
set AUDIT_RC=%errorlevel%

echo.
echo === Resume ===
echo smoke_test.php   : %SMOKE_RC%
echo live_smoke_test  : %LIVE_RC%
echo full_pages_smoke : %FULL_RC%
echo audit_with_log   : %AUDIT_RC%
echo Logs debug       : F:\DATA\DEVELOPPEMENT\htmleditor_v3\htmleditor\debug-4af063.log
echo.

if %SMOKE_RC% neq 0 exit /b %SMOKE_RC%
if %LIVE_RC% neq 0 exit /b %LIVE_RC%
if %FULL_RC% neq 0 exit /b %FULL_RC%
if %AUDIT_RC% neq 0 exit /b %AUDIT_RC%
exit /b 0
