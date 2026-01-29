@echo off
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Installation Docker Desktop
REM ============================================================

set DOWNLOAD_DIR=%~dp0..\downloads
set DOCKER_URL=https://desktop.docker.com/win/main/amd64/Docker%%20Desktop%%20Installer.exe
set DOCKER_INSTALLER=%DOWNLOAD_DIR%\DockerDesktopInstaller.exe

echo.
echo ══════════════════════════════════════════════════════════
echo   Installation de Docker Desktop
echo ══════════════════════════════════════════════════════════
echo.

REM Vérifier si déjà installé
where docker >nul 2>&1
if %errorlevel% equ 0 (
    echo [INFO] Docker est déjà installé.
    docker --version
    echo.
    set /p reinstall="Voulez-vous réinstaller ? (O/N): "
    if /i not "!reinstall!"=="O" exit /b 0
)

REM Vérifier les prérequis Windows
echo Vérification des prérequis...
echo.

REM Vérifier WSL2
wsl --status >nul 2>&1
if %errorlevel% neq 0 (
    echo [INFO] WSL2 n'est pas activé. Installation...
    echo.
    echo Cette opération nécessite un redémarrage.
    echo Après le redémarrage, relancez ce script.
    echo.
    wsl --install
    echo.
    echo Appuyez sur une touche pour redémarrer...
    pause >nul
    shutdown /r /t 10 /c "Redémarrage pour WSL2 - K-Docs"
    exit /b 0
)

REM Créer le dossier downloads si nécessaire
if not exist "%DOWNLOAD_DIR%" mkdir "%DOWNLOAD_DIR%"

REM Télécharger Docker Desktop
if exist "%DOCKER_INSTALLER%" (
    echo [INFO] Installateur déjà téléchargé.
    set /p redownload="Re-télécharger ? (O/N): "
    if /i "!redownload!"=="O" del "%DOCKER_INSTALLER%"
)

if not exist "%DOCKER_INSTALLER%" (
    echo Téléchargement de Docker Desktop...
    echo URL: %DOCKER_URL%
    echo.

    REM Utiliser curl ou PowerShell
    curl -L -o "%DOCKER_INSTALLER%" "%DOCKER_URL%" 2>nul
    if !errorlevel! neq 0 (
        echo Téléchargement avec PowerShell...
        powershell -Command "Invoke-WebRequest -Uri '%DOCKER_URL%' -OutFile '%DOCKER_INSTALLER%'"
    )

    if not exist "%DOCKER_INSTALLER%" (
        echo [ERREUR] Échec du téléchargement.
        echo Téléchargez manuellement depuis: https://www.docker.com/products/docker-desktop/
        pause
        exit /b 1
    )
)

echo.
echo Taille du fichier:
for %%A in ("%DOCKER_INSTALLER%") do echo   %%~zA octets

echo.
echo Installation de Docker Desktop...
echo (L'installation peut prendre plusieurs minutes)
echo.

REM Lancer l'installation silencieuse
"%DOCKER_INSTALLER%" install --quiet --accept-license

if %errorlevel% equ 0 (
    echo.
    echo [OK] Docker Desktop installé avec succès !
    echo.
    echo IMPORTANT:
    echo   1. Redémarrez votre ordinateur
    echo   2. Lancez Docker Desktop depuis le menu Démarrer
    echo   3. Attendez que l'icône baleine soit stable
    echo   4. Puis exécutez: docker\onlyoffice\start.bat
    echo.
) else (
    echo.
    echo [ERREUR] L'installation a échoué.
    echo Essayez d'installer manuellement: %DOCKER_INSTALLER%
    echo.
)

pause
exit /b 0
