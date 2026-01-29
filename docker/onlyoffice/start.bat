@echo off
REM K-Docs - Démarrer OnlyOffice Document Server
cd /d "%~dp0"
echo Démarrage OnlyOffice Document Server...
docker-compose up -d
echo.
echo OnlyOffice démarré sur http://localhost:8080
echo Test: http://localhost:8080/healthcheck
echo.
pause
