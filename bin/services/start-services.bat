@echo off
REM ============================================================
REM K-Docs - Démarrage des services Docker
REM ============================================================
cd /d "%~dp0"

echo.
echo Démarrage des services K-Docs (OnlyOffice + Qdrant)...
echo.

docker-compose up -d

if %errorlevel% equ 0 (
    echo.
    echo [OK] Services démarrés
    echo.
    docker-compose ps
    echo.
    echo URLs:
    echo   - K-Docs:      http://localhost/kdocs
    echo   - OnlyOffice:  http://localhost:8080
    echo   - Qdrant:      http://localhost:6333/dashboard
) else (
    echo.
    echo [ERREUR] Échec du démarrage
    echo.
    echo Vérifiez que Docker Desktop est lancé.
)

echo.
pause
