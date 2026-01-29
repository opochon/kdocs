@echo off
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Vérification des dépendances
REM ============================================================

set "OK=[OK]"
set "MISSING=[MANQUANT]"
set "WARN=[ATTENTION]"

echo.
echo Vérification des dépendances K-Docs...
echo.

REM --- Docker Desktop ---
echo --- Docker Desktop ---
set DOCKER_OK=0
where docker >nul 2>&1
if %errorlevel% equ 0 (
    for /f "tokens=*" %%i in ('docker --version 2^>nul') do echo   Version: %%i
    docker info >nul 2>&1
    if !errorlevel! equ 0 (
        echo   %OK% Docker est installé et fonctionne
        set DOCKER_OK=1
    ) else (
        echo   %WARN% Docker installé mais non démarré
        echo          → Lancez Docker Desktop
    )
) else (
    echo   %MISSING% Docker Desktop non installé
    echo          → Exécutez: installer\scripts\install-docker.bat
)
echo.

REM --- Docker Compose ---
echo --- Docker Compose ---
docker compose version >nul 2>&1
if %errorlevel% equ 0 (
    for /f "tokens=*" %%i in ('docker compose version 2^>nul') do echo   Version: %%i
    echo   %OK% Docker Compose disponible
) else (
    docker-compose --version >nul 2>&1
    if !errorlevel! equ 0 (
        for /f "tokens=*" %%i in ('docker-compose --version 2^>nul') do echo   Version: %%i
        echo   %OK% Docker Compose (legacy) disponible
    ) else (
        if %DOCKER_OK%==1 (
            echo   %WARN% Docker Compose non trouvé dans le PATH
        ) else (
            echo   %MISSING% Docker Compose non disponible (installez Docker Desktop)
        )
    )
)
echo.

REM --- OnlyOffice Container ---
echo --- OnlyOffice Document Server ---
if %DOCKER_OK%==1 (
    docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Status}}" 2>nul | findstr /i "Up" >nul
    if !errorlevel! equ 0 (
        echo   %OK% Conteneur OnlyOffice en cours d'exécution
        curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
        if !errorlevel! equ 0 (
            echo   %OK% OnlyOffice répond sur http://localhost:8080
        ) else (
            echo   %WARN% OnlyOffice démarre encore, patientez...
        )
    ) else (
        docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Status}}" 2>nul | findstr /i "Exited" >nul
        if !errorlevel! equ 0 (
            echo   %WARN% Conteneur OnlyOffice arrêté
            echo          → Exécutez: docker\onlyoffice\start.bat
        ) else (
            echo   %MISSING% Conteneur OnlyOffice non créé
            echo          → Exécutez: docker\onlyoffice\start.bat
        )
    )
) else (
    echo   %MISSING% Nécessite Docker Desktop
)
echo.

REM --- LibreOffice ---
echo --- LibreOffice ---
set LO_PATH=
if exist "C:\Program Files\LibreOffice\program\soffice.exe" set LO_PATH=C:\Program Files\LibreOffice\program\soffice.exe
if exist "C:\Program Files (x86)\LibreOffice\program\soffice.exe" set LO_PATH=C:\Program Files (x86)\LibreOffice\program\soffice.exe

if defined LO_PATH (
    echo   Chemin: %LO_PATH%
    echo   %OK% LibreOffice installé
) else (
    echo   %MISSING% LibreOffice non installé
    echo          → Exécutez: installer\scripts\install-libreoffice.bat
)
echo.

REM --- Tesseract OCR ---
echo --- Tesseract OCR ---
set TESS_PATH=
if exist "C:\Program Files\Tesseract-OCR\tesseract.exe" set TESS_PATH=C:\Program Files\Tesseract-OCR\tesseract.exe

if defined TESS_PATH (
    for /f "tokens=*" %%i in ('"%TESS_PATH%" --version 2^>^&1 ^| findstr /i "tesseract"') do echo   Version: %%i
    echo   %OK% Tesseract OCR installé
) else (
    where tesseract >nul 2>&1
    if !errorlevel! equ 0 (
        for /f "tokens=*" %%i in ('tesseract --version 2^>^&1 ^| findstr /i "tesseract"') do echo   Version: %%i
        echo   %OK% Tesseract OCR installé (dans PATH)
    ) else (
        echo   %MISSING% Tesseract OCR non installé
        echo          → Exécutez: installer\scripts\install-tesseract.bat
    )
)
echo.

REM --- Ghostscript ---
echo --- Ghostscript ---
set GS_PATH=
for /d %%d in ("C:\Program Files\gs\gs*") do set GS_PATH=%%d\bin\gswin64c.exe
if not exist "%GS_PATH%" (
    for /d %%d in ("C:\Program Files (x86)\gs\gs*") do set GS_PATH=%%d\bin\gswin32c.exe
)

if exist "%GS_PATH%" (
    for /f "tokens=*" %%i in ('"%GS_PATH%" --version 2^>nul') do echo   Version: %%i
    echo   Chemin: %GS_PATH%
    echo   %OK% Ghostscript installé
) else (
    echo   %MISSING% Ghostscript non installé
    echo          → Exécutez: installer\scripts\install-pdf-tools.bat
)
echo.

REM --- Poppler (pdftotext) ---
echo --- Poppler (pdftotext, pdftoppm) ---
set POPPLER_PATH=
if exist "C:\Program Files\poppler\Library\bin\pdftotext.exe" set POPPLER_PATH=C:\Program Files\poppler\Library\bin
if exist "C:\poppler\Library\bin\pdftotext.exe" set POPPLER_PATH=C:\poppler\Library\bin
if exist "C:\Program Files\Git\mingw64\bin\pdftotext.exe" set POPPLER_PATH=C:\Program Files\Git\mingw64\bin

if defined POPPLER_PATH (
    echo   Chemin: %POPPLER_PATH%
    echo   %OK% Poppler installé
) else (
    where pdftotext >nul 2>&1
    if !errorlevel! equ 0 (
        echo   %OK% Poppler installé (dans PATH)
    ) else (
        echo   %MISSING% Poppler non installé
        echo          → Exécutez: installer\scripts\install-pdf-tools.bat
    )
)
echo.

REM --- ImageMagick ---
echo --- ImageMagick ---
set IM_PATH=
for /d %%d in ("C:\Program Files\ImageMagick*") do set IM_PATH=%%d\magick.exe

if exist "%IM_PATH%" (
    for /f "tokens=*" %%i in ('"%IM_PATH%" --version 2^>nul ^| findstr /i "Version"') do echo   %%i
    echo   %OK% ImageMagick installé
) else (
    where magick >nul 2>&1
    if !errorlevel! equ 0 (
        echo   %OK% ImageMagick installé (dans PATH)
    ) else (
        echo   %WARN% ImageMagick non installé (optionnel)
        echo          → Utilisé comme fallback pour les miniatures
    )
)
echo.

echo ══════════════════════════════════════════════════════════
echo   RÉSUMÉ
echo ══════════════════════════════════════════════════════════
echo.
