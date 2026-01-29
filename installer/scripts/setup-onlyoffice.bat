@echo off
setlocal EnableDelayedExpansion
chcp 65001 >nul 2>&1

REM ============================================================
REM K-Docs - Configuration OnlyOffice Document Server
REM ============================================================

set SCRIPT_DIR=%~dp0
set KDOCS_ROOT=%SCRIPT_DIR%..\..
set DOCKER_DIR=%KDOCS_ROOT%\docker\onlyoffice

echo.
echo ══════════════════════════════════════════════════════════
echo   Configuration OnlyOffice Document Server
echo ══════════════════════════════════════════════════════════
echo.

REM Trouver Docker
echo Recherche de Docker...
call "%SCRIPT_DIR%find-docker.bat"
if %errorlevel% neq 0 (
    echo.
    echo [ERREUR] Docker non trouve sur ce systeme.
    echo.
    echo Emplacements verifies:
    echo   - PATH systeme
    echo   - C:\Program Files\Docker\Docker\resources\bin\
    echo   - %LOCALAPPDATA%\Docker\
    echo.
    echo Solutions:
    echo   1. Installez Docker Desktop: https://www.docker.com/products/docker-desktop/
    echo   2. Ou executez: installer\scripts\install-docker.bat
    echo.
    pause
    exit /b 1
)

echo [OK] Docker trouve: %DOCKER_EXE%
echo.

REM Vérifier que Docker daemon tourne
echo Verification du daemon Docker...
%DOCKER_EXE% info >nul 2>&1
if %errorlevel% neq 0 (
    echo.
    echo [ERREUR] Docker Desktop n'est pas demarre.
    echo.
    echo 1. Lancez Docker Desktop depuis le menu Demarrer
    echo 2. Attendez que l'icone baleine soit stable (1-2 min)
    echo 3. Relancez ce script
    echo.

    REM Tenter de lancer Docker Desktop
    echo Tentative de lancement automatique...
    start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe" 2>nul
    if !errorlevel! equ 0 (
        echo Docker Desktop lance. Patientez 60 secondes...
        timeout /t 60 /nobreak
        %DOCKER_EXE% info >nul 2>&1
        if !errorlevel! neq 0 (
            echo [ERREUR] Docker n'a pas demarre. Lancez-le manuellement.
            pause
            exit /b 1
        )
    ) else (
        pause
        exit /b 1
    )
)
echo [OK] Docker daemon operationnel
echo.

REM Vérifier si le conteneur existe déjà
echo Verification du conteneur OnlyOffice...
%DOCKER_EXE% ps -a --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>nul | findstr /i "kdocs-onlyoffice" >nul
if %errorlevel% equ 0 (
    echo [INFO] Conteneur kdocs-onlyoffice existant detecte.

    REM Vérifier s'il tourne
    %DOCKER_EXE% ps --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>nul | findstr /i "kdocs-onlyoffice" >nul
    if !errorlevel! equ 0 (
        echo [OK] Conteneur deja en cours d'execution.
        goto :wait_ready
    ) else (
        echo Demarrage du conteneur existant...
        %DOCKER_EXE% start kdocs-onlyoffice
        goto :wait_ready
    )
)

:create_container
echo.
echo Creation du conteneur OnlyOffice...
echo (Premier telechargement: ~2-3 GB, peut prendre 5-15 minutes)
echo.

cd /d "%DOCKER_DIR%"

REM Utiliser docker compose avec le chemin complet
%DOCKER_COMPOSE_CMD% -f "%DOCKER_DIR%\docker-compose.yml" up -d
if %errorlevel% neq 0 (
    echo.
    echo [ERREUR] Docker Compose a echoue.
    echo.
    echo Tentative alternative avec docker run...
    %DOCKER_EXE% run -d --name kdocs-onlyoffice --restart unless-stopped -p 8080:80 -e JWT_ENABLED=false onlyoffice/documentserver:latest
    if !errorlevel! neq 0 (
        echo [ERREUR] Impossible de creer le conteneur.
        pause
        exit /b 1
    )
)

:wait_ready
echo.
echo Attente du demarrage d'OnlyOffice...
echo (Premiere execution: 30-90 secondes)
echo.

set ATTEMPTS=0
:check_loop
set /a ATTEMPTS+=1
if %ATTEMPTS% gtr 40 (
    echo.
    echo [ATTENTION] OnlyOffice met du temps a demarrer.
    echo Verifiez les logs: %DOCKER_EXE% logs kdocs-onlyoffice
    echo.
    echo Le conteneur continue de demarrer en arriere-plan.
    echo Reessayez dans quelques minutes.
    goto :show_status
)

REM Test healthcheck
curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo.
    echo [OK] OnlyOffice est pret !
    goto :show_status
)

echo   Tentative %ATTEMPTS%/40... (patientez)
timeout /t 3 /nobreak >nul
goto :check_loop

:show_status
echo.
echo ══════════════════════════════════════════════════════════
echo   STATUT ONLYOFFICE
echo ══════════════════════════════════════════════════════════
echo.

%DOCKER_EXE% ps --filter "name=kdocs-onlyoffice" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo.
echo URLs:
echo   - Health check: http://localhost:8080/healthcheck
echo   - Web interface: http://localhost:8080/
echo.

REM Test final
curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo ══════════════════════════════════════════════════════════
    echo   [OK] OnlyOffice fonctionne correctement !
    echo ══════════════════════════════════════════════════════════
    echo.
    echo Vous pouvez maintenant:
    echo   - Ouvrir des fichiers Office dans K-Docs
    echo   - Les modifier en ligne
    echo   - Generer des miniatures automatiquement
) else (
    echo [INFO] OnlyOffice demarre encore...
    echo Testez dans quelques instants: http://localhost:8080/healthcheck
)

echo.
pause
exit /b 0
