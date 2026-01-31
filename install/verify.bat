@echo off
REM K-Docs - Vérification de l'installation

echo.
echo   K-Docs - Verification de l'installation
echo   ========================================
echo.

cd /d "%~dp0"

powershell -ExecutionPolicy Bypass -File "scripts\verify-install.ps1"

echo.
pause
