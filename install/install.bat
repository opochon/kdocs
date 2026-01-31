@echo off
REM K-Docs - Installation Windows
REM Ce script lance l'installateur PowerShell

echo.
echo   K-Docs - Installation Windows
echo   =============================
echo.

REM Vérifier si on est admin
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] Ce script doit etre execute en tant qu'administrateur!
    echo.
    echo Clic droit sur install.bat ^> Executer en tant qu'administrateur
    echo.
    pause
    exit /b 1
)

REM Autoriser l'exécution de scripts PowerShell
powershell -Command "Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser -Force" >nul 2>&1

REM Lancer l'installateur PowerShell
echo Lancement de l'installateur...
echo.
powershell -ExecutionPolicy Bypass -File "%~dp0install.ps1"

echo.
pause
