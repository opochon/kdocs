@echo off
:: K-Docs - Configuration Firewall pour Docker
:: Double-cliquez pour executer

echo.
echo ========================================
echo   K-Docs - Configuration Firewall
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
powershell -ExecutionPolicy Bypass -File "%~dp0configure-firewall.ps1"

echo.
pause
