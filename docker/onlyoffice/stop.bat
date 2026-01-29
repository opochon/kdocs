@echo off
REM K-Docs - Arrêter OnlyOffice Document Server
cd /d "%~dp0"
echo Arrêt OnlyOffice Document Server...
docker-compose down
echo OnlyOffice arrêté.
pause
