@echo off
echo === Recreate OnlyOffice Container with Network Fix ===
echo.

echo [1/3] Stopping and removing existing container...
docker stop kdocs-onlyoffice 2>nul
docker rm kdocs-onlyoffice 2>nul

echo.
echo [2/3] Creating container with proper network configuration...
docker run -d ^
  --name kdocs-onlyoffice ^
  --restart unless-stopped ^
  -p 8080:80 ^
  -e JWT_ENABLED=false ^
  --add-host=host.docker.internal:host-gateway ^
  onlyoffice/documentserver:latest

echo.
echo [3/3] Waiting for OnlyOffice to start (2-3 min)...

set /a attempts=0
:waitloop
set /a attempts+=1
timeout /t 5 /nobreak >nul
curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul 2>&1
if %errorlevel%==0 goto :ready
if %attempts% geq 36 goto :timeout
echo   Attempt %attempts%/36...
goto :waitloop

:ready
echo.
echo ========================================
echo  OnlyOffice ready!
echo ========================================
echo.
echo Testing connection to host...
docker exec kdocs-onlyoffice curl -s -o /dev/null -w "%%{http_code}" http://host.docker.internal/ 2>nul
echo.
goto :end

:timeout
echo Timeout - check logs: docker logs kdocs-onlyoffice

:end
pause
