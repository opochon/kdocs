@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul 2>&1

REM ============================================================
REM K-Docs - Demarrer OnlyOffice Document Server
REM ============================================================

cd /d "%~dp0"

echo.
echo Demarrage OnlyOffice Document Server...
echo.

REM Trouver Docker
set DOCKER_EXE=
set DOCKER_COMPOSE_CMD=

where docker >nul 2>&1
if %errorlevel% equ 0 (
    set DOCKER_EXE=docker
    set DOCKER_COMPOSE_CMD=docker compose
    goto :docker_found
)

if exist "C:\Program Files\Docker\Docker\resources\bin\docker.exe" (
    set DOCKER_EXE="C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    set DOCKER_COMPOSE_CMD="C:\Program Files\Docker\Docker\resources\bin\docker.exe" compose
    goto :docker_found
)

echo [ERREUR] Docker non trouve.
echo Installez Docker Desktop ou ajoutez-le au PATH.
pause
exit /b 1

:docker_found
echo Docker: %DOCKER_EXE%

REM Verifier si Docker tourne
%DOCKER_EXE% info >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] Docker Desktop n'est pas demarre.
    echo Lancez Docker Desktop et reessayez.
    pause
    exit /b 1
)

REM Verifier si le conteneur existe et tourne deja
%DOCKER_EXE% ps --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>nul | findstr /i "kdocs-onlyoffice" >nul
if %errorlevel% equ 0 (
    echo [INFO] OnlyOffice est deja en cours d'execution.
    goto :check_health
)

REM Verifier si le conteneur existe mais est arrete
%DOCKER_EXE% ps -a --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>nul | findstr /i "kdocs-onlyoffice" >nul
if %errorlevel% equ 0 (
    echo Demarrage du conteneur existant...
    %DOCKER_EXE% start kdocs-onlyoffice
    goto :check_health
)

REM Creer le conteneur avec IP privees autorisees
echo Creation du conteneur (premier lancement)...
%DOCKER_COMPOSE_CMD% up -d 2>nul
if %errorlevel% neq 0 (
    echo Tentative avec docker run...
    %DOCKER_EXE% run -d --name kdocs-onlyoffice --restart unless-stopped -p 8080:80 -e JWT_ENABLED=false -e ALLOW_PRIVATE_IP_ADDRESS=true -e ALLOW_META_IP_ADDRESS=true --add-host=host.docker.internal:host-gateway onlyoffice/documentserver:latest
)

:check_health
echo.
echo Attente du demarrage (30-60 secondes)...

set ATTEMPTS=0
:loop
set /a ATTEMPTS+=1
if %ATTEMPTS% gtr 20 (
    echo.
    echo OnlyOffice demarre en arriere-plan.
    goto :done
)

curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo.
    echo [OK] OnlyOffice est pret !
    goto :done
)

echo   Tentative %ATTEMPTS%/20...
timeout /t 3 /nobreak >nul
goto :loop

:done
echo.
echo URLs:
echo   - http://localhost:8080/healthcheck
echo   - http://localhost:8080/
echo.
pause
