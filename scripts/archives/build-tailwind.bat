@echo off
REM K-Docs - Build Tailwind CSS
REM Télécharge le CLI si nécessaire et compile le CSS

cd /d "%~dp0"

set TAILWIND_CLI=tailwindcss-windows-x64.exe
set TAILWIND_URL=https://github.com/tailwindlabs/tailwindcss/releases/latest/download/%TAILWIND_CLI%

REM Vérifier si le CLI existe
if not exist "%TAILWIND_CLI%" (
    echo Telechargement de Tailwind CLI...
    curl -sLO %TAILWIND_URL%
    if errorlevel 1 (
        echo [ERREUR] Echec du telechargement. Verifiez votre connexion.
        pause
        exit /b 1
    )
    echo [OK] Tailwind CLI telecharge
)

REM Build CSS
echo.
echo Compilation de Tailwind CSS...
%TAILWIND_CLI% -i src/css/input.css -o public/css/tailwind.css --minify

if errorlevel 1 (
    echo [ERREUR] Echec de la compilation
    pause
    exit /b 1
)

echo.
echo [OK] CSS compile: public/css/tailwind.css
echo.
