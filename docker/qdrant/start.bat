@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul 2>&1

REM ============================================================
REM K-Docs - Demarrer Qdrant Vector Database
REM ============================================================

cd /d "%~dp0"

echo.
echo Demarrage Qdrant Vector Database...
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

REM Creer le dossier de stockage si necessaire
if not exist "..\..\storage\qdrant" mkdir "..\..\storage\qdrant"

REM Verifier si le conteneur existe et tourne deja
%DOCKER_EXE% ps --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>nul | findstr /i "kdocs-qdrant" >nul
if %errorlevel% equ 0 (
    echo [INFO] Qdrant est deja en cours d'execution.
    goto :check_health
)

REM Verifier si le conteneur existe mais est arrete
%DOCKER_EXE% ps -a --filter "name=kdocs-qdrant" --format "{{.Names}}" 2>nul | findstr /i "kdocs-qdrant" >nul
if %errorlevel% equ 0 (
    echo Demarrage du conteneur existant...
    %DOCKER_EXE% start kdocs-qdrant
    goto :check_health
)

REM Creer le conteneur
echo Creation du conteneur (premier lancement)...
%DOCKER_EXE% run -d --name kdocs-qdrant --restart unless-stopped -p 6333:6333 -p 6334:6334 -v "%~dp0..\..\storage\qdrant:/qdrant/storage" qdrant/qdrant:latest

:check_health
echo.
echo Attente du demarrage (5-10 secondes)...

set ATTEMPTS=0
:loop
set /a ATTEMPTS+=1
if %ATTEMPTS% gtr 10 (
    echo.
    echo Qdrant demarre en arriere-plan.
    goto :done
)

curl -s http://localhost:6333/health 2>nul | findstr /i "ok" >nul
if %errorlevel% equ 0 (
    echo.
    echo [OK] Qdrant est pret !
    goto :done
)

echo   Tentative %ATTEMPTS%/10...
timeout /t 2 /nobreak >nul
goto :loop

:done
echo.
echo URLs:
echo   - REST API: http://localhost:6333
echo   - gRPC:     localhost:6334
echo   - Dashboard: http://localhost:6333/dashboard
echo.
echo Commandes utiles:
echo   - Status: curl http://localhost:6333/health
echo   - Collections: curl http://localhost:6333/collections
echo.
pause
