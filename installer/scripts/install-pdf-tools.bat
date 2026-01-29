@echo off
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Installation outils PDF (Ghostscript, Poppler)
REM ============================================================

set DOWNLOAD_DIR=%~dp0..\downloads

REM Versions
set GS_VERSION=10.03.1
set POPPLER_VERSION=24.02.0

REM URLs
set GS_URL=https://github.com/ArtifexSoftware/ghostpdl-downloads/releases/download/gs10031/gs10031w64.exe
set POPPLER_URL=https://github.com/oschwartz10612/poppler-windows/releases/download/v%POPPLER_VERSION%-0/Release-%POPPLER_VERSION%-0.zip

set GS_INSTALLER=%DOWNLOAD_DIR%\gs%GS_VERSION:.=%w64.exe
set POPPLER_ZIP=%DOWNLOAD_DIR%\poppler-%POPPLER_VERSION%.zip

echo.
echo ══════════════════════════════════════════════════════════
echo   Installation des outils PDF
echo ══════════════════════════════════════════════════════════
echo.

REM Créer le dossier downloads si nécessaire
if not exist "%DOWNLOAD_DIR%" mkdir "%DOWNLOAD_DIR%"

REM ============================================================
REM GHOSTSCRIPT
REM ============================================================
echo.
echo --- Ghostscript %GS_VERSION% ---
echo.

set GS_INSTALLED=0
for /d %%d in ("C:\Program Files\gs\gs*") do (
    if exist "%%d\bin\gswin64c.exe" (
        set GS_INSTALLED=1
        echo [INFO] Ghostscript déjà installé: %%d
    )
)

if %GS_INSTALLED%==0 (
    if not exist "%GS_INSTALLER%" (
        echo Téléchargement de Ghostscript...
        echo URL: %GS_URL%
        echo.
        curl -L -o "%GS_INSTALLER%" "%GS_URL%" 2>nul
        if !errorlevel! neq 0 (
            powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%GS_URL%' -OutFile '%GS_INSTALLER%'"
        )
    )

    if exist "%GS_INSTALLER%" (
        echo Installation de Ghostscript...
        "%GS_INSTALLER%" /S
        timeout /t 5 /nobreak >nul
        echo [OK] Ghostscript installé
    ) else (
        echo [ERREUR] Téléchargement échoué
    )
) else (
    set /p reinstall="Réinstaller Ghostscript ? (O/N): "
    if /i "!reinstall!"=="O" (
        if exist "%GS_INSTALLER%" (
            "%GS_INSTALLER%" /S
        )
    )
)

REM ============================================================
REM POPPLER
REM ============================================================
echo.
echo --- Poppler %POPPLER_VERSION% (pdftotext, pdftoppm) ---
echo.

set POPPLER_PATH=C:\Program Files\poppler
set POPPLER_INSTALLED=0

if exist "%POPPLER_PATH%\Library\bin\pdftotext.exe" (
    set POPPLER_INSTALLED=1
    echo [INFO] Poppler déjà installé: %POPPLER_PATH%
)

if %POPPLER_INSTALLED%==0 (
    if not exist "%POPPLER_ZIP%" (
        echo Téléchargement de Poppler...
        echo URL: %POPPLER_URL%
        echo.
        curl -L -o "%POPPLER_ZIP%" "%POPPLER_URL%" 2>nul
        if !errorlevel! neq 0 (
            powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%POPPLER_URL%' -OutFile '%POPPLER_ZIP%'"
        )
    )

    if exist "%POPPLER_ZIP%" (
        echo Extraction de Poppler vers %POPPLER_PATH%...

        REM Créer le dossier
        if not exist "%POPPLER_PATH%" mkdir "%POPPLER_PATH%"

        REM Extraire avec PowerShell
        powershell -Command "Expand-Archive -Path '%POPPLER_ZIP%' -DestinationPath '%POPPLER_PATH%' -Force"

        REM Le ZIP contient un sous-dossier, déplacer le contenu
        for /d %%d in ("%POPPLER_PATH%\poppler-*") do (
            xcopy "%%d\*" "%POPPLER_PATH%\" /E /Y >nul 2>&1
            rd /s /q "%%d" >nul 2>&1
        )

        if exist "%POPPLER_PATH%\Library\bin\pdftotext.exe" (
            echo [OK] Poppler installé

            REM Ajouter au PATH
            echo Ajout au PATH système...
            setx /M PATH "%PATH%;%POPPLER_PATH%\Library\bin" >nul 2>&1
        ) else (
            echo [ERREUR] Extraction échouée
        )
    ) else (
        echo [ERREUR] Téléchargement échoué
    )
) else (
    set /p reinstall="Réinstaller Poppler ? (O/N): "
    if /i "!reinstall!"=="O" (
        rd /s /q "%POPPLER_PATH%" >nul 2>&1
        goto :install_poppler
    )
)

echo.
echo ══════════════════════════════════════════════════════════
echo   Résumé
echo ══════════════════════════════════════════════════════════
echo.

REM Vérification finale
for /d %%d in ("C:\Program Files\gs\gs*") do (
    if exist "%%d\bin\gswin64c.exe" echo [OK] Ghostscript: %%d\bin\gswin64c.exe
)

if exist "%POPPLER_PATH%\Library\bin\pdftotext.exe" (
    echo [OK] Poppler: %POPPLER_PATH%\Library\bin\
)

echo.
pause
exit /b 0
