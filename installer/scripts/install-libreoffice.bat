@echo off
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Installation LibreOffice
REM ============================================================

set DOWNLOAD_DIR=%~dp0..\downloads
set LO_VERSION=24.2.7
set LO_URL=https://download.documentfoundation.org/libreoffice/stable/%LO_VERSION%/win/x86_64/LibreOffice_%LO_VERSION%_Win_x86-64.msi
set LO_INSTALLER=%DOWNLOAD_DIR%\LibreOffice_%LO_VERSION%_Win_x86-64.msi

echo.
echo ══════════════════════════════════════════════════════════
echo   Installation de LibreOffice %LO_VERSION%
echo ══════════════════════════════════════════════════════════
echo.

REM Vérifier si déjà installé
if exist "C:\Program Files\LibreOffice\program\soffice.exe" (
    echo [INFO] LibreOffice est déjà installé.
    echo Chemin: C:\Program Files\LibreOffice\program\soffice.exe
    echo.
    set /p reinstall="Voulez-vous réinstaller ? (O/N): "
    if /i not "!reinstall!"=="O" exit /b 0
)

REM Créer le dossier downloads si nécessaire
if not exist "%DOWNLOAD_DIR%" mkdir "%DOWNLOAD_DIR%"

REM Télécharger LibreOffice
if exist "%LO_INSTALLER%" (
    echo [INFO] Installateur déjà téléchargé.
    set /p redownload="Re-télécharger ? (O/N): "
    if /i "!redownload!"=="O" del "%LO_INSTALLER%"
)

if not exist "%LO_INSTALLER%" (
    echo Téléchargement de LibreOffice %LO_VERSION%...
    echo URL: %LO_URL%
    echo (Environ 350 MB, patientez...)
    echo.

    curl -L -o "%LO_INSTALLER%" "%LO_URL%" 2>nul
    if !errorlevel! neq 0 (
        echo Téléchargement avec PowerShell...
        powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%LO_URL%' -OutFile '%LO_INSTALLER%'"
    )

    if not exist "%LO_INSTALLER%" (
        echo [ERREUR] Échec du téléchargement.
        echo Téléchargez manuellement depuis: https://www.libreoffice.org/download/download/
        pause
        exit /b 1
    )
)

echo.
echo Taille du fichier:
for %%A in ("%LO_INSTALLER%") do echo   %%~zA octets

echo.
echo Installation de LibreOffice...
echo (L'installation peut prendre plusieurs minutes)
echo.

REM Installation silencieuse MSI
msiexec /i "%LO_INSTALLER%" /qb /norestart ADDLOCAL=ALL REMOVE=gm_o_Onlineupdate

if %errorlevel% equ 0 (
    echo.
    echo [OK] LibreOffice installé avec succès !
    echo Chemin: C:\Program Files\LibreOffice\program\soffice.exe
    echo.
) else (
    echo.
    echo [ERREUR] L'installation a échoué (code: %errorlevel%).
    echo Essayez d'installer manuellement: %LO_INSTALLER%
    echo.
)

pause
exit /b 0
