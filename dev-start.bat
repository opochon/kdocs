@echo off
REM K-Docs - serveur dev PHP integre (127.0.0.1, router.php obligatoire)
setlocal EnableDelayedExpansion
cd /d "%~dp0"

set HOST=127.0.0.1
set PORT=8765

call tools\kill-dev-port.bat %PORT%

echo.
echo Demarrage serveur (premier plan)...
echo   URL   : http://%HOST%:%PORT%/kdocs/
echo   Login : root / (mot de passe vide)
echo   Arret : Ctrl+C
echo.

php -S %HOST%:%PORT% router.php
