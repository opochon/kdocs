@echo off
setlocal EnableDelayedExpansion

REM ============================================================
REM K-Docs - Configuration OnlyOffice Document Server
REM ============================================================

set KDOCS_ROOT=%~dp0..\..
set DOCKER_DIR=%KDOCS_ROOT%\docker\onlyoffice

echo.
echo ══════════════════════════════════════════════════════════
echo   Configuration OnlyOffice Document Server
echo ══════════════════════════════════════════════════════════
echo.

REM Vérifier Docker
echo Vérification de Docker...
docker info >nul 2>&1
if %errorlevel% neq 0 (
    echo.
    echo [ERREUR] Docker n'est pas démarré.
    echo.
    echo 1. Lancez Docker Desktop depuis le menu Démarrer
    echo 2. Attendez que l'icône baleine soit stable (1-2 min)
    echo 3. Relancez ce script
    echo.
    pause
    exit /b 1
)
echo [OK] Docker est opérationnel
echo.

REM Vérifier si le conteneur existe déjà
docker ps -a --filter "name=kdocs-onlyoffice" --format "{{.Names}}" 2>nul | findstr /i "kdocs-onlyoffice" >nul
if %errorlevel% equ 0 (
    echo [INFO] Conteneur kdocs-onlyoffice existant détecté.
    echo.
    echo Que voulez-vous faire ?
    echo   [1] Démarrer le conteneur existant
    echo   [2] Supprimer et recréer
    echo   [3] Annuler
    echo.
    set /p choice="Votre choix: "

    if "!choice!"=="1" goto start_container
    if "!choice!"=="2" goto recreate_container
    echo Annulé.
    exit /b 0
)

:create_container
echo.
echo Création du conteneur OnlyOffice...
echo (Le premier téléchargement peut prendre 5-10 minutes)
echo.

cd /d "%DOCKER_DIR%"

REM Essayer docker compose v2 puis v1
docker compose up -d 2>nul
if %errorlevel% neq 0 (
    docker-compose up -d 2>nul
    if !errorlevel! neq 0 (
        echo [ERREUR] Docker Compose a échoué.
        pause
        exit /b 1
    )
)

goto wait_ready

:start_container
echo.
echo Démarrage du conteneur...
docker start kdocs-onlyoffice
goto wait_ready

:recreate_container
echo.
echo Suppression du conteneur existant...
docker stop kdocs-onlyoffice >nul 2>&1
docker rm kdocs-onlyoffice >nul 2>&1
goto create_container

:wait_ready
echo.
echo Attente du démarrage d'OnlyOffice...
echo (Peut prendre 30-60 secondes au premier lancement)
echo.

set ATTEMPTS=0
:check_loop
set /a ATTEMPTS+=1
if %ATTEMPTS% gtr 30 (
    echo.
    echo [ATTENTION] OnlyOffice met du temps à démarrer.
    echo Vérifiez les logs: docker logs kdocs-onlyoffice
    goto show_status
)

curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo.
    echo [OK] OnlyOffice est prêt !
    goto show_status
)

echo   Tentative %ATTEMPTS%/30...
timeout /t 3 /nobreak >nul
goto check_loop

:show_status
echo.
echo ══════════════════════════════════════════════════════════
echo   STATUT ONLYOFFICE
echo ══════════════════════════════════════════════════════════
echo.

docker ps --filter "name=kdocs-onlyoffice" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo.
echo URLs:
echo   - Health check: http://localhost:8080/healthcheck
echo   - Interface:    http://localhost:8080/
echo.

REM Test final
curl -s http://localhost:8080/healthcheck 2>nul | findstr /i "true" >nul
if %errorlevel% equ 0 (
    echo [OK] OnlyOffice répond correctement.
    echo.
    echo Vous pouvez maintenant ouvrir des fichiers Office dans K-Docs !
) else (
    echo [ATTENTION] OnlyOffice ne répond pas encore.
    echo Patientez quelques instants et testez: http://localhost:8080/healthcheck
)

echo.
pause
exit /b 0
