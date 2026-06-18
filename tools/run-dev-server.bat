@echo off
REM Serveur dev GEDv1 - OBLIGATOIRE: router.php (pas -t .)
setlocal EnableDelayedExpansion
cd /d "%~dp0.."

set HOST=127.0.0.1
set PORT=8765
if not "%~1"=="" set PORT=%~1
set GEDV1_DEBUG_SESSION=4af063

call "%~dp0kill-dev-port.bat" %PORT%

echo.
echo GEDv1 dev: http://%HOST%:%PORT%/kdocs/
echo Login: root / (vide)
echo Ctrl+C pour arreter
echo.

php -S %HOST%:%PORT% router.php
