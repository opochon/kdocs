@echo off
echo === K-Docs OnlyOffice Docker Starter ===
echo.

REM Check if Docker is running
docker info >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Docker n'est pas lance ou Docker Desktop n'est pas installe.
    echo         Veuillez lancer Docker Desktop puis reessayez.
    pause
    exit /b 1
)

REM Check if container already exists
docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Names}}" | findstr /i "kdocs-onlyoffice" >nul 2>&1
if %errorlevel%==0 (
    echo [INFO] Container kdocs-onlyoffice existe deja.

    REM Check if running
    docker ps --filter "name=kdocs-onlyoffice" --format "{{.Names}}" | findstr /i "kdocs-onlyoffice" >nul 2>&1
    if %errorlevel%==0 (
        echo [OK] Container deja en cours d'execution.
    ) else (
        echo [INFO] Demarrage du container existant...
        docker start kdocs-onlyoffice
        echo [OK] Container demarre.
    )
) else (
    echo [INFO] Creation du container OnlyOffice...
    cd /d "%~dp0"
    docker-compose up -d
    echo [OK] Container cree et demarre.
)

echo.
echo En attente du demarrage d'OnlyOffice (peut prendre 2-3 minutes)...
echo.

REM Wait for healthcheck
set /a attempts=0
set /a max_attempts=30

:waitloop
set /a attempts+=1
timeout /t 5 /nobreak >nul

curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul 2>&1
if %errorlevel%==0 goto :ready

if %attempts% geq %max_attempts% goto :timeout

echo   Tentative %attempts%/%max_attempts% - En cours d'initialisation...
goto :waitloop

:ready
echo.
echo ========================================
echo  OnlyOffice est pret!
echo  URL: http://localhost:8080
echo  Health: http://localhost:8080/healthcheck
echo ========================================
goto :end

:timeout
echo.
echo [ATTENTION] OnlyOffice n'a pas demarre dans le temps imparti.
echo             Verifiez les logs: docker logs kdocs-onlyoffice
echo.

:end
pause
