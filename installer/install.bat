@echo off
chcp 65001 >nul 2>&1
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Installateur Principal Windows
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
echo ┌──────────────────────────────────────────────────────────┐
echo │  Que souhaitez-vous faire ?                              │
echo ├──────────────────────────────────────────────────────────┤
echo │                                                          │
echo │   [1] Vérifier les dépendances installées                │
echo │                                                          │
echo │   [2] Installer TOUT (recommandé)                        │
echo │                                                          │
echo │  ─── Installation individuelle ───                       │
echo │   [3] Docker Desktop                                     │
echo │   [4] Services Docker (OnlyOffice + Qdrant)              │
echo │   [5] LibreOffice                                        │
echo │   [6] Tesseract OCR                                      │
echo │   [7] Outils PDF (Ghostscript, Poppler)                  │
echo │                                                          │
echo │  ─── Gestion Docker ───                                  │
echo │   [8] Démarrer les services Docker                       │
echo │   [9] Arrêter les services Docker                        │
echo │                                                          │
echo │   [0] Quitter                                            │
echo │                                                          │
echo └──────────────────────────────────────────────────────────┘
echo.
set /p choice="Votre choix: "

if "%choice%"=="1" call :check_all & goto menu
if "%choice%"=="2" call :install_all & goto menu
if "%choice%"=="3" call scripts\install-docker.bat & goto menu
if "%choice%"=="4" call scripts\setup-docker-services.bat & goto menu
if "%choice%"=="5" call scripts\install-libreoffice.bat & goto menu
if "%choice%"=="6" call scripts\install-tesseract.bat & goto menu
if "%choice%"=="7" call scripts\install-pdf-tools.bat & goto menu
if "%choice%"=="8" call :docker_start & goto menu
if "%choice%"=="9" call :docker_stop & goto menu
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
echo   - Services Docker (OnlyOffice + Qdrant)
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

echo.
echo ══════════════════════════════════════════════════════════
echo   INSTALLATION DES LOGICIELS TERMINÉE
echo ══════════════════════════════════════════════════════════
echo.
echo IMPORTANT: Redémarrez votre ordinateur, puis:
echo.
echo   1. Lancez Docker Desktop
echo   2. Attendez que Docker soit prêt (icône baleine stable)
echo   3. Relancez ce script et choisissez [4] Services Docker
echo.
echo Ou exécutez directement:
echo   cd %~dp0..
echo   docker-compose up -d
echo.
goto :eof

:docker_start
echo.
echo Démarrage des services Docker K-Docs...
cd /d "%~dp0.."
docker-compose up -d
if %errorlevel% equ 0 (
    echo.
    echo [OK] Services démarrés
    echo.
    docker-compose ps
) else (
    echo [ERREUR] Échec du démarrage. Docker Desktop est-il lancé ?
)
echo.
pause
goto :eof

:docker_stop
echo.
echo Arrêt des services Docker K-Docs...
cd /d "%~dp0.."
docker-compose down
if %errorlevel% equ 0 (
    echo.
    echo [OK] Services arrêtés
) else (
    echo [ERREUR] Échec de l'arrêt.
)
echo.
pause
goto :eof

:end
echo.
echo Au revoir !
exit /b 0
