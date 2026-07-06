@echo off
REM GEDv1 - Simulation ERP Connect bout-en-bout (GED <-> K-Time, sans WinBiz vivant)
REM 1. Verifie K-Time web (API /api/ged/*) - demarre K-Time si absent
REM 2. Demarre la GED avec le plugin ERP Connect actif (si pas deja up)
REM 3. Seed (tools/erp-sim-seed.php, idempotent) puis spec Playwright erp-connect
REM Voir : K-TIME/docs/SPEC-GED-INTEGRATION.md + apps/erpconnect/
REM Usage : run-erp-simulation.bat

setlocal EnableDelayedExpansion
cd /d "%~dp0"

set GED_HOST=127.0.0.1
set GED_PORT=8770
set KTIME_DIR=F:\DATA\DEVELOPPEMENT\K-TIME\k-time-web
set KTIME_BASE=http://127.0.0.1:8091
set KTIME_GED_API_KEY=ged-dev-key-2026

echo.
echo === Simulation ERP Connect (GED - K-Time) ===
echo.

echo [1/4] K-Time web (%KTIME_BASE%)...
curl -s -o nul -w "%%{http_code}" -H "X-Api-Key: %KTIME_GED_API_KEY%" %KTIME_BASE%/api/ged/health | findstr "200" >nul
if errorlevel 1 (
    echo   K-Time absent - demarrage php -S 127.0.0.1:8091...
    start "ktime-sim" /min cmd /c "cd /d %KTIME_DIR% && php -S 127.0.0.1:8091 -t public public/index.php"
    timeout /t 3 /nobreak >nul
    curl -s -o nul -w "%%{http_code}" -H "X-Api-Key: %KTIME_GED_API_KEY%" %KTIME_BASE%/api/ged/health | findstr "200" >nul
    if errorlevel 1 (
        echo   [ERREUR] API K-Time injoignable - verifier MariaDB 3307 + migration 082 + cle ged_api_key
        exit /b 1
    )
)
echo   OK

echo [2/4] Serveur GED (%GED_HOST%:%GED_PORT%, plugin ERP Connect actif)...
curl -s -o nul -w "%%{http_code}" http://%GED_HOST%:%GED_PORT%/kdocs/health | findstr "200" >nul
if errorlevel 1 (
    start "ged-sim" /min cmd /c "cd /d %~dp0 && set ERPCONNECT_APP_ENABLED=true&& set KTIME_URL=%KTIME_BASE%&& set KTIME_GED_API_KEY=%KTIME_GED_API_KEY%&& set SMQ_APP_ENABLED=true&& set RATE_LIMIT_MAX=100000&& php -S %GED_HOST%:%GED_PORT% router.php"
    timeout /t 3 /nobreak >nul
)
echo   OK

echo [3/4] Seed simulation (fournisseur Fournitout SA + facture 4 lignes)...
php tools\erp-sim-seed.php
if errorlevel 1 exit /b 1

echo [4/4] Spec Playwright erp-connect...
pushd tests\visual
set KDOCS_HOST=%GED_HOST%
set KDOCS_PORT=%GED_PORT%
set KDOCS_BASE_PATH=/kdocs
call npx playwright test specs/erp-connect.spec.ts
set PW_ERR=!errorlevel!
popd
if !PW_ERR! neq 0 (
    echo.
    echo === Simulation ERP Connect : ROUGE ===
    exit /b 1
)

echo.
echo === Simulation ERP Connect : VERTE ===
echo Captures : tests\visual\screenshots\erp-connect-*.png
exit /b 0
