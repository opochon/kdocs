@echo off
echo === Fix OnlyOffice Container ===
echo.

REM Stop and remove the incorrectly configured container
echo [1/4] Arret des containers OnlyOffice existants...
for /f "tokens=*" %%i in ('docker ps -q --filter "ancestor=onlyoffice/documentserver"') do (
    echo   Arret de %%i...
    docker stop %%i
)

echo.
echo [2/4] Suppression des containers OnlyOffice...
for /f "tokens=*" %%i in ('docker ps -aq --filter "ancestor=onlyoffice/documentserver"') do (
    echo   Suppression de %%i...
    docker rm %%i
)

echo.
echo [3/4] Creation du nouveau container avec la bonne configuration...
docker run -d ^
  --name kdocs-onlyoffice ^
  --restart unless-stopped ^
  -p 8080:80 ^
  -e JWT_ENABLED=false ^
  --add-host=host.docker.internal:host-gateway ^
  onlyoffice/documentserver:latest

echo.
echo [4/4] Attente du demarrage (2-3 minutes)...

set /a attempts=0
set /a max_attempts=36

:waitloop
set /a attempts+=1
timeout /t 5 /nobreak >nul

curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul 2>&1
if %errorlevel%==0 goto :ready

if %attempts% geq %max_attempts% goto :timeout

echo   Tentative %attempts%/%max_attempts% - Initialisation en cours...
goto :waitloop

:ready
echo.
echo ========================================
echo  OnlyOffice est pret!
echo  URL: http://localhost:8080
echo  Healthcheck: http://localhost:8080/healthcheck
echo ========================================
echo.
echo Testez avec: curl http://localhost:8080/healthcheck
goto :end

:timeout
echo.
echo [!] Timeout - verifiez les logs:
echo     docker logs kdocs-onlyoffice
echo.

:end
pause
