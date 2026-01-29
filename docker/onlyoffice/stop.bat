@echo off
REM K-Docs - Arrêter OnlyOffice Document Server
cd /d "%~dp0"
echo Arret OnlyOffice Document Server...

REM Essayer docker compose (v2) puis docker-compose (v1)
docker compose down 2>nul
if %errorlevel% neq 0 (
    docker-compose down 2>nul
)

echo.
echo OnlyOffice arrete.
echo.
pause
