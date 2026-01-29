@echo off
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Installation Tesseract OCR
REM ============================================================

set DOWNLOAD_DIR=%~dp0..\downloads
set TESS_VERSION=5.3.3
set TESS_URL=https://github.com/UB-Mannheim/tesseract/releases/download/v%TESS_VERSION%.20231005/tesseract-ocr-w64-setup-%TESS_VERSION%.20231005.exe
set TESS_INSTALLER=%DOWNLOAD_DIR%\tesseract-ocr-w64-setup-%TESS_VERSION%.exe

echo.
echo ══════════════════════════════════════════════════════════
echo   Installation de Tesseract OCR %TESS_VERSION%
echo ══════════════════════════════════════════════════════════
echo.

REM Vérifier si déjà installé
if exist "C:\Program Files\Tesseract-OCR\tesseract.exe" (
    echo [INFO] Tesseract OCR est déjà installé.
    "C:\Program Files\Tesseract-OCR\tesseract.exe" --version 2>&1 | findstr /i "tesseract"
    echo.
    set /p reinstall="Voulez-vous réinstaller ? (O/N): "
    if /i not "!reinstall!"=="O" exit /b 0
)

REM Créer le dossier downloads si nécessaire
if not exist "%DOWNLOAD_DIR%" mkdir "%DOWNLOAD_DIR%"

REM Télécharger Tesseract
if exist "%TESS_INSTALLER%" (
    echo [INFO] Installateur déjà téléchargé.
    set /p redownload="Re-télécharger ? (O/N): "
    if /i "!redownload!"=="O" del "%TESS_INSTALLER%"
)

if not exist "%TESS_INSTALLER%" (
    echo Téléchargement de Tesseract OCR %TESS_VERSION%...
    echo URL: %TESS_URL%
    echo.

    curl -L -o "%TESS_INSTALLER%" "%TESS_URL%" 2>nul
    if !errorlevel! neq 0 (
        echo Téléchargement avec PowerShell...
        powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%TESS_URL%' -OutFile '%TESS_INSTALLER%'"
    )

    if not exist "%TESS_INSTALLER%" (
        echo [ERREUR] Échec du téléchargement.
        echo Téléchargez manuellement depuis: https://github.com/UB-Mannheim/tesseract/releases
        pause
        exit /b 1
    )
)

echo.
echo Installation de Tesseract OCR...
echo.
echo IMPORTANT: Dans l'installateur, cochez les langues suivantes :
echo   - French (fra)
echo   - German (deu)
echo   - English (eng)
echo   - Italian (ita)
echo.
echo Appuyez sur une touche pour lancer l'installation...
pause >nul

REM Lancer l'installateur (pas de mode silencieux pour choisir les langues)
"%TESS_INSTALLER%"

if exist "C:\Program Files\Tesseract-OCR\tesseract.exe" (
    echo.
    echo [OK] Tesseract OCR installé avec succès !

    REM Ajouter au PATH si pas déjà présent
    echo.
    echo Ajout au PATH système...
    setx /M PATH "%PATH%;C:\Program Files\Tesseract-OCR" >nul 2>&1

    echo.
    echo Langues installées:
    dir "C:\Program Files\Tesseract-OCR\tessdata\*.traineddata" /b 2>nul
    echo.
) else (
    echo.
    echo [ATTENTION] Installation annulée ou échouée.
    echo.
)

pause
exit /b 0
