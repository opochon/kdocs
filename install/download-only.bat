@echo off
REM K-Docs - Téléchargement des outils uniquement
REM Ce script télécharge tous les outils sans les installer

echo.
echo   K-Docs - Telechargement des outils
echo   ===================================
echo.

cd /d "%~dp0"

powershell -ExecutionPolicy Bypass -File "scripts\download-tools.ps1" -DownloadsDir "downloads"

echo.
echo Fichiers telecharges dans: %~dp0downloads
echo.
pause
