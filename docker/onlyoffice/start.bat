@echo off
REM K-Docs - Démarrer OnlyOffice Document Server
cd /d "%~dp0"
echo Démarrage OnlyOffice Document Server...

REM Essayer docker compose (v2) puis docker-compose (v1)
docker compose up -d 2>nul
if %errorlevel% neq 0 (
    docker-compose up -d 2>nul
    if %errorlevel% neq 0 (
        echo ERREUR: Docker Compose non trouve.
        echo Verifiez que Docker Desktop est installe et demarre.
        pause
        exit /b 1
    )
)

echo.
echo OnlyOffice demarre sur http://localhost:8080
echo Attente du demarrage (peut prendre 30-60 secondes)...
timeout /t 10 /nobreak >nul

REM Test healthcheck
curl -s http://localhost:8080/healthcheck >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] OnlyOffice est pret!
) else (
    echo [...] OnlyOffice demarre encore, patientez...
)

echo.
echo Test: http://localhost:8080/healthcheck
echo.
pause
