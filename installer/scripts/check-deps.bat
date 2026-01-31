@echo off
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Vérification des dépendances (mise à jour)
REM ============================================================

set "OK=[32m[OK][0m"
set "MISSING=[31m[MANQUANT][0m"
set "WARN=[33m[ATTENTION][0m"

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
if %DOCKER_OK%==1 (
    docker compose version >nul 2>&1
    if !errorlevel! equ 0 (
        for /f "tokens=*" %%i in ('docker compose version 2^>nul') do echo   Version: %%i
        echo   %OK% Docker Compose disponible
    ) else (
        echo   %WARN% Docker Compose non trouvé
    )
) else (
    echo   %MISSING% Nécessite Docker Desktop
)
echo.

REM --- OnlyOffice Container ---
echo --- OnlyOffice Document Server ---
if %DOCKER_OK%==1 (
    docker ps --filter "name=kdocs-onlyoffice" --format "{{.Status}}" 2>nul | findstr /i "Up" >nul
    if !errorlevel! equ 0 (
        echo   %OK% Container en cours d'exécution
        curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
        if !errorlevel! equ 0 (
            echo   %OK% Répond sur http://localhost:8080
        ) else (
            echo   %WARN% Démarre encore, patientez...
        )
    ) else (
        docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Status}}" 2>nul | findstr /i "Exited" >nul
        if !errorlevel! equ 0 (
            echo   %WARN% Container arrêté
            echo          → docker start kdocs-onlyoffice
        ) else (
            echo   %MISSING% Container non créé
            echo          → Exécutez: installer\scripts\setup-docker-services.bat
        )
    )
) else (
    echo   %MISSING% Nécessite Docker Desktop
)
echo.

REM --- Qdrant Vector Database ---
echo --- Qdrant Vector Database ---
if %DOCKER_OK%==1 (
    docker ps --filter "name=kdocs-qdrant" --format "{{.Status}}" 2>nul | findstr /i "Up" >nul
    if !errorlevel! equ 0 (
        echo   %OK% Container en cours d'exécution
        curl -s http://localhost:6333/collections 2>nul | findstr /i "result" >nul
        if !errorlevel! equ 0 (
            echo   %OK% Répond sur http://localhost:6333
            REM Compter les collections
            for /f %%n in ('curl -s http://localhost:6333/collections 2^>nul ^| findstr /o "collections" ^| find /c ":"') do (
                echo   Info: Dashboard http://localhost:6333/dashboard
            )
        ) else (
            echo   %WARN% Démarre encore, patientez...
        )
    ) else (
        docker ps -a --filter "name=kdocs-qdrant" --format "{{.Status}}" 2>nul | findstr /i "Exited" >nul
        if !errorlevel! equ 0 (
            echo   %WARN% Container arrêté
            echo          → docker start kdocs-qdrant
        ) else (
            echo   %MISSING% Container non créé
            echo          → Exécutez: installer\scripts\setup-docker-services.bat
        )
    )
) else (
    echo   %MISSING% Nécessite Docker Desktop
)
echo.

REM --- Ollama (pour embeddings) ---
echo --- Ollama (Embeddings locaux) ---
curl -s http://localhost:11434/api/tags 2>nul | findstr /i "models" >nul
if %errorlevel% equ 0 (
    echo   %OK% Ollama répond sur http://localhost:11434
    REM Vérifier si nomic-embed-text est installé
    curl -s http://localhost:11434/api/tags 2>nul | findstr /i "nomic-embed-text" >nul
    if !errorlevel! equ 0 (
        echo   %OK% Modèle nomic-embed-text disponible
    ) else (
        echo   %WARN% Modèle nomic-embed-text non installé
        echo          → ollama pull nomic-embed-text
    )
) else (
    echo   %WARN% Ollama non démarré ou non installé
    echo          → https://ollama.ai/ ou démarrez Ollama
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

echo ══════════════════════════════════════════════════════════
echo   RÉSUMÉ
echo ══════════════════════════════════════════════════════════
echo.
echo   Services Docker:
echo     - OnlyOffice: prévisualisation/édition Office
echo     - Qdrant: recherche sémantique vectorielle
echo.
echo   Services locaux:
echo     - Ollama: embeddings pour recherche sémantique
echo.
echo   Outils système:
echo     - Tesseract: OCR (reconnaissance texte images/PDF)
echo     - LibreOffice: conversion/miniatures Office
echo     - Ghostscript/Poppler: traitement PDF
echo.
echo Pour installer les services Docker:
echo   installer\scripts\setup-docker-services.bat
echo.
