@echo off
REM ============================================================
REM K-Docs - Arrêt des services Docker
REM ============================================================
cd /d "%~dp0"

echo.
echo Arrêt des services K-Docs (OnlyOffice + Qdrant)...
echo.

docker-compose down

if %errorlevel% equ 0 (
    echo.
    echo [OK] Services arrêtés
) else (
    echo.
    echo [ERREUR] Échec de l'arrêt
)

echo.
pause
