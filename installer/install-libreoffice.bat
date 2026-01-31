@echo off
:: K-Docs - Lancement installation LibreOffice
:: Double-cliquez pour executer

echo.
echo ========================================
echo   K-Docs - Installation LibreOffice
echo ========================================
echo.

:: Verifier si admin
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo Demande des droits administrateur...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
    exit /b
)

:: Lancer le script PowerShell
powershell -ExecutionPolicy Bypass -File "%~dp0install-libreoffice.ps1"

echo.
pause
