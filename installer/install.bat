@echo off
chcp 65001 >nul 2>&1
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Installateur Principal Windows
REM ============================================================
REM Ce script installe toutes les dépendances nécessaires :
REM - Docker Desktop (pour OnlyOffice)
REM - LibreOffice (pour miniatures et conversion)
REM - Tesseract OCR (pour reconnaissance de texte)
REM - Ghostscript (pour traitement PDF)
REM - Poppler (pdftotext, pdftoppm)
REM ============================================================

title K-Docs Installer
cd /d "%~dp0"

echo.
echo ╔══════════════════════════════════════════════════════════╗
echo ║           K-Docs - Installation des dépendances          ║
echo ╚══════════════════════════════════════════════════════════╝
echo.

REM Vérifier les droits admin
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] Ce script doit être exécuté en tant qu'Administrateur.
    echo.
    echo Clic droit sur install.bat → "Exécuter en tant qu'administrateur"
    echo.
    pause
    exit /b 1
)

REM Menu principal
:menu
echo.
echo Que souhaitez-vous faire ?
echo.
echo   [1] Vérifier les dépendances installées
echo   [2] Installer TOUT (recommandé)
echo   [3] Installer Docker Desktop uniquement
echo   [4] Installer LibreOffice uniquement
echo   [5] Installer Tesseract OCR uniquement
echo   [6] Installer outils PDF (Ghostscript, Poppler)
echo   [7] Configurer OnlyOffice (après Docker)
echo   [0] Quitter
echo.
set /p choice="Votre choix: "

if "%choice%"=="1" call :check_all & goto menu
if "%choice%"=="2" call :install_all & goto menu
if "%choice%"=="3" call scripts\install-docker.bat & goto menu
if "%choice%"=="4" call scripts\install-libreoffice.bat & goto menu
if "%choice%"=="5" call scripts\install-tesseract.bat & goto menu
if "%choice%"=="6" call scripts\install-pdf-tools.bat & goto menu
if "%choice%"=="7" call scripts\setup-onlyoffice.bat & goto menu
if "%choice%"=="0" goto end

echo Choix invalide.
goto menu

:check_all
echo.
echo ══════════════════════════════════════════════════════════
echo   VÉRIFICATION DES DÉPENDANCES
echo ══════════════════════════════════════════════════════════
echo.
call scripts\check-deps.bat
goto :eof

:install_all
echo.
echo ══════════════════════════════════════════════════════════
echo   INSTALLATION COMPLÈTE
echo ══════════════════════════════════════════════════════════
echo.
echo Cette opération va installer :
echo   - Docker Desktop
echo   - LibreOffice
echo   - Tesseract OCR
echo   - Ghostscript
echo   - Poppler (outils PDF)
echo.
set /p confirm="Continuer ? (O/N): "
if /i not "%confirm%"=="O" goto :eof

call scripts\install-docker.bat
call scripts\install-libreoffice.bat
call scripts\install-tesseract.bat
call scripts\install-pdf-tools.bat
call scripts\setup-onlyoffice.bat

echo.
echo ══════════════════════════════════════════════════════════
echo   INSTALLATION TERMINÉE
echo ══════════════════════════════════════════════════════════
echo.
echo Redémarrez votre ordinateur pour finaliser l'installation.
echo Puis lancez Docker Desktop et exécutez :
echo   docker\onlyoffice\start.bat
echo.
goto :eof

:end
echo.
echo Au revoir !
exit /b 0
